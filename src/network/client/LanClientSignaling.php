<?php

/*
 * This file is part of BedrockProxy.
 * Copyright (C) 2026 Axolotl Team <https://github.com/axolotl-pm/BedrockProxy>
 *
 * BedrockProxy is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\bedrockproxy\network\client;

use pocketmine\nethernet\discovery\DiscoveryException;
use pocketmine\nethernet\discovery\packet\MessagePacket;
use pocketmine\nethernet\discovery\packet\Packet;
use pocketmine\nethernet\discovery\packet\PacketSerializer;
use pocketmine\nethernet\discovery\packet\RequestPacket;
use pocketmine\nethernet\discovery\packet\ResponsePacket;
use pocketmine\nethernet\discovery\ServerData;
use pocketmine\nethernet\discovery\Signal;
use pocketmine\nethernet\negotiation\CandidateMode;
use pocketmine\nethernet\signaling\SignalingException;
use function microtime;
use function socket_bind;
use function socket_clear_error;
use function socket_close;
use function socket_create;
use function socket_last_error;
use function socket_recvfrom;
use function socket_sendto;
use function socket_set_nonblock;
use function socket_set_option;
use function socket_strerror;
use function sprintf;
use function strlen;
use const AF_INET;
use const SO_BROADCAST;
use const SOCK_DGRAM;
use const SOCKET_ECONNRESET;
use const SOCKET_EWOULDBLOCK;
use const SOL_SOCKET;
use const SOL_UDP;

final class LanClientSignaling implements ClientSignalingInterface{

	public const DISCOVERY_INTERVAL = 2.0;
	public const SERVER_TTL = 15.0;

	private const MAX_DATAGRAMS_PER_TICK = 256;
	private const MAX_DATAGRAM_SIZE = 65535;
	private const PING_DATA = "Ping";

	private ?\Socket $socket = null;
	private ?DiscoveredServer $server = null;
	private float $nextDiscovery = 0.0;

	/**
	 * @var array[]
	 * @phpstan-var list<array{string, Signal}>
	 */
	private array $inbound = [];

	public function __construct(
		private readonly int $networkId,
		private readonly string $address,
		private readonly int $port,
		private readonly int $expectedNetworkId = 0,
		private readonly string $bindAddress = "0.0.0.0",
		private readonly ?\Logger $logger = null
	){
		if($networkId <= 0){
			throw new \InvalidArgumentException("Network id must be positive, got $networkId");
		}
	}

	public function start() : void{
		if($this->socket !== null){
			throw new SignalingException("Already started");
		}

		$socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		if($socket === false){
			throw new SignalingException("Failed to create UDP socket: " . socket_strerror(socket_last_error()));
		}
		@socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
		if(!@socket_bind($socket, $this->bindAddress, 0)){
			$error = socket_strerror(socket_last_error($socket));
			socket_close($socket);

			throw new SignalingException("Failed to bind socket to $this->bindAddress: $error");
		}
		socket_set_nonblock($socket);
		$this->socket = $socket;
		$this->nextDiscovery = 0.0;

		$this->logger?->debug("Searching for destination server via LAN signaling at $this->address:$this->port as network id $this->networkId");
	}

	public function tick() : void{
		$socket = $this->socket;
		if($socket === null){
			return;
		}

		$now = microtime(true);
		if($now >= $this->nextDiscovery){
			$this->nextDiscovery = $now + self::DISCOVERY_INTERVAL;
			$this->send(new RequestPacket(), $this->address, $this->port);
		}

		$this->receiveAll($socket, $now);

		if($this->server !== null && $now - $this->server->lastSeen > self::SERVER_TTL){
			$this->logger?->warning("Lost destination server " . $this->server->describe());
			$this->server = null;
		}
	}

	public function shutdown() : void{
		if($this->socket !== null){
			socket_close($this->socket);
			$this->socket = null;
		}
		$this->server = null;
		$this->inbound = [];
	}

	public function getCandidateMode() : CandidateMode{
		return CandidateMode::TRICKLE;
	}

	public function getRemoteServer() : ?RemoteServer{
		return $this->server;
	}

	public function sendSignal(RemoteServer $server, Signal $signal) : void{
		if(!$server instanceof DiscoveredServer){
			throw new SignalingException("LAN signaling can only reach servers found by discovery");
		}
		if($this->socket === null){
			throw new SignalingException("LAN signaling is not started");
		}

		$this->send(new MessagePacket($server->networkId, $signal->toString()), $server->address, $server->port);
	}

	public function takeSignals() : array{
		$taken = $this->inbound;
		$this->inbound = [];

		return $taken;
	}

	private function receiveAll(\Socket $socket, float $now) : void{
		for($i = 0; $i < self::MAX_DATAGRAMS_PER_TICK; ++$i){
			$buffer = "";
			$from = "";
			$fromPort = 0;

			if(@socket_recvfrom($socket, $buffer, self::MAX_DATAGRAM_SIZE, 0, $from, $fromPort) === false){
				$error = socket_last_error($socket);
				socket_clear_error($socket);

				if($error === SOCKET_EWOULDBLOCK || $error === 0){
					return;
				}
				if($error === SOCKET_ECONNRESET){
					continue;
				}

				$this->logger?->debug("LAN client signaling read failed: " . socket_strerror($error));

				return;
			}

			try{
				$this->handleDatagram($buffer, $from, $fromPort, $now);
			}catch(DiscoveryException $e){
				$this->logger?->debug("Ignoring a datagram from $from:$fromPort: " . $e->getMessage());
			}
		}
	}

	/**
	 * @throws DiscoveryException
	 */
	private function handleDatagram(string $frame, string $address, int $port, float $now) : void{
		[$packet, $senderId] = PacketSerializer::decode($frame);

		if($senderId === $this->networkId){
			return;
		}

		if($packet instanceof ResponsePacket){
			$this->handleResponse($packet, $senderId, $address, $port, $now);
		}elseif($packet instanceof MessagePacket){
			$this->handleMessage($packet, $senderId, $address, $port, $now);
		}
	}

	private function handleResponse(ResponsePacket $packet, int $senderId, string $address, int $port, float $now) : void{
		if($this->expectedNetworkId !== 0 && $senderId !== $this->expectedNetworkId){
			return;
		}
		$server = $this->server;

		try{
			$serverData = ServerData::read($packet->applicationData);
		}catch(DiscoveryException $e){
			if($server !== null){
				$serverData = $server->serverData;
			}else{
				$this->logger?->warning("Failed to parse server advertisement from $address:$port; using placeholder: " . $e->getMessage());
				$serverData = new ServerData(serverName: "Unknown", levelName: "Unknown");
			}
		}

		if($server === null){
			$server = new DiscoveredServer($senderId, $address, $port, $serverData, $now);
			$this->server = $server;
			$this->logger?->info("Discovered destination server " . $server->describe());

			return;
		}
		if($server->networkId !== $senderId){
			return;
		}
		$server->address = $address;
		$server->port = $port;
		$server->serverData = $serverData;
		$server->lastSeen = $now;
	}

	/**
	 * @throws DiscoveryException
	 */
	private function handleMessage(MessagePacket $packet, int $senderId, string $address, int $port, float $now) : void{
		if($packet->recipientId !== $this->networkId){
			return;
		}

		$server = $this->server;
		if($server !== null && $server->networkId === $senderId){
			$server->address = $address;
			$server->port = $port;
			$server->lastSeen = $now;
		}

		if($packet->data === "" || $packet->data === self::PING_DATA){
			return;
		}

		$this->inbound[] = [sprintf("%u", $senderId), Signal::parse($packet->data)];
	}

	private function send(Packet $packet, string $address, int $port) : void{
		$socket = $this->socket;
		if($socket === null){
			return;
		}

		$frame = PacketSerializer::encode($packet, $this->networkId);
		if(@socket_sendto($socket, $frame, strlen($frame), 0, $address, $port) === false){
			$this->logger?->debug("Failed to send to $address:$port: " . socket_strerror(socket_last_error($socket)));
		}
	}
}
