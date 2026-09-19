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

namespace pocketmine\bedrockproxy\network;

use pocketmine\bedrockproxy\data\DataExtractionException;
use pocketmine\bedrockproxy\data\DataExtractor;
use pocketmine\bedrockproxy\logging\SessionLogger;
use pocketmine\bedrockproxy\network\client\ClientNegotiation;
use pocketmine\bedrockproxy\network\compression\ZlibCompressor;
use pocketmine\bedrockproxy\network\encryption\DecryptionException;
use pocketmine\bedrockproxy\network\encryption\EncryptionContext;
use pocketmine\bedrockproxy\network\handler\ClientPacketHandler;
use pocketmine\bedrockproxy\network\handler\ProxyPacketHandler;
use pocketmine\bedrockproxy\network\handler\ServerPacketHandler;
use pocketmine\bedrockproxy\packet\BatchBuilder;
use pocketmine\bedrockproxy\packet\BatchEdit;
use pocketmine\bedrockproxy\packet\BlockStateIdMode;
use pocketmine\bedrockproxy\packet\BlockStateLookup;
use pocketmine\bedrockproxy\packet\Direction;
use pocketmine\bedrockproxy\packet\InspectedPacket;
use pocketmine\bedrockproxy\packet\InspectionException;
use pocketmine\bedrockproxy\packet\PacketDumper;
use pocketmine\bedrockproxy\packet\PacketInspector;
use pocketmine\bedrockproxy\utils\Utils;
use pocketmine\nethernet\NetherNetException;
use pocketmine\nethernet\session\DisconnectReason;
use pocketmine\nethernet\session\Reliability;
use pocketmine\nethernet\session\Session;
use pocketmine\network\mcpe\protocol\ClientToServerHandshakePacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ServerToClientHandshakePacket;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use function bin2hex;
use function count;
use function implode;
use function strlen;
use function substr;

final class ProxySession{

	private ?Session $upstream = null;
	private bool $closed = false;

	/**
	 * @var array[]
	 * @phpstan-var list<array{string, Reliability}>
	 */
	private array $pending = [];

	private readonly PacketInspector $inspector;
	private ProxyPacketHandler $clientHandler;
	private ProxyPacketHandler $serverHandler;
	private readonly ?BlockStateLookup $baseBlockStates;
	private readonly BatchBuilder $batchBuilder;

	private ?EncryptionContext $clientCipher = null;
	private ?EncryptionContext $serverCipher = null;
	private readonly ?EncryptionBridge $encryption;
	private ?BatchEdit $batchEdit = null;

	public function __construct(
		private readonly Session $client,
		private readonly ClientNegotiation $negotiation,
		private readonly SessionLogger $logger,
		private readonly PacketDumper $dumper,
		private readonly \Logger $console,
		bool $decodePackets,
		?EncryptionBridge $encryption,
		private readonly ?DataExtractor $extractor
	){
		$this->inspector = new PacketInspector(PacketPool::getInstance(), ZlibCompressor::getInstance(), $decodePackets);
		$this->batchBuilder = new BatchBuilder(ZlibCompressor::getInstance());
		$this->clientHandler = new ClientPacketHandler($this);
		$this->serverHandler = new ServerPacketHandler($this);
		$this->baseBlockStates = $dumper->getBlockStates();
		$this->encryption = $encryption;
	}

	public function getClient() : Session{ return $this->client; }

	public function getName() : string{ return $this->logger->getName(); }

	public function isClosed() : bool{ return $this->closed; }

	public function setClientHandler(ProxyPacketHandler $handler) : void{
		$this->clientHandler = $handler;
		$handler->setUp();
	}

	public function setServerHandler(ProxyPacketHandler $handler) : void{
		$this->serverHandler = $handler;
		$handler->setUp();
	}

	public function note(string $note) : void{
		$this->logger->logNote($note);
	}

	public function warn(string $warning) : void{
		$this->logger->logNote($warning);
		$this->console->warning("[" . $this->getName() . "] " . $warning);
	}

	public function setName(string $name) : void{
		$this->logger->setName($name);
	}

	public function onCompressionEnabled(int $algorithm) : void{
		$this->inspector->setCompressionEnabled($algorithm);
	}

	/**
	 * @param BlockPaletteEntry[] $customBlocks
	 * @phpstan-param list<BlockPaletteEntry> $customBlocks
	 */
	public function onBlockPalette(bool $networkIdsAreHashes, array $customBlocks) : void{
		$lookup = $this->baseBlockStates;
		if($lookup === null){
			return;
		}

		$announced = $networkIdsAreHashes ? BlockStateIdMode::HASHED : BlockStateIdMode::RUNTIME;
		if($lookup->getMode() === BlockStateIdMode::AUTO){
			$lookup = $lookup->withMode($announced);
		}elseif($lookup->getMode() !== $announced){
			$this->warn("Block state ID mode overridden by configuration: " . $lookup->getMode()->value . " (server advertised: " . $announced->value . ")");
		}

		if(count($customBlocks) > 0){
			$lookup = $lookup->withCustomBlocks($customBlocks);
			$this->note("Server advertised " . count($customBlocks) . " custom block(s); block state dictionary rebuilt");
		}

		$this->dumper->setBlockStates($lookup);
		$this->extractor?->setBlockStates($lookup);
	}

	public function isDecrypting() : bool{ return $this->encryption !== null; }

	/**
	 * @phpstan-param \Closure(DataExtractor) : void $extract
	 */
	public function extractData(\Closure $extract) : void{
		$extractor = $this->extractor;
		if($extractor === null){
			return;
		}

		try{
			$extract($extractor);
		}catch(DataExtractionException $e){
			$this->warn("Failed to extract data: " . $e->getMessage());
		}
	}

	public function replaceCurrentPacket(Packet $packet) : void{
		$this->batchEdit?->replace($this->batchBuilder->encodePackets([$packet])[0]);
	}

	public function suppressCurrentBatch() : void{
		$this->batchEdit?->suppress();
	}

	public function rewriteLogin(LoginPacket $packet) : void{
		if($this->encryption === null){
			return;
		}

		try{
			$this->replaceCurrentPacket($this->encryption->rewriteLogin($packet));
		}catch(EncryptionBridgeException $e){
			$this->warn("Failed to re-sign LoginPacket; session traffic will be unreadable once encryption starts: " . $e->getMessage());
		}
	}

	public function startEncryption(string $serverHandshakeJwt) : void{
		$this->suppressCurrentBatch();

		$encryption = $this->encryption;
		if($encryption === null || !$encryption->hasClientKey()){
			$this->warn("Destination started packet encryption, but the proxy has no client key; terminating session");
			$this->close(DisconnectReason::REJECTED_BY_HOST);

			return;
		}

		try{
			$serverCipher = $encryption->acceptServerHandshake($serverHandshakeJwt);
			[$clientHandshakeJwt, $clientCipher] = $encryption->createClientHandshake();
		}catch(EncryptionBridgeException $e){
			$this->warn("Failed to initialize packet encryption bridge: " . $e->getMessage());
			$this->close(DisconnectReason::REJECTED_BY_HOST);

			return;
		}

		$this->serverCipher = $serverCipher;
		$this->sendToUpstream($this->wrap($this->encodeBatch(ClientToServerHandshakePacket::create()), $serverCipher), Reliability::RELIABLE);

		$this->sendToClient($this->encodeBatch(ServerToClientHandshakePacket::create($clientHandshakeJwt)), Reliability::RELIABLE);
		$this->clientCipher = $clientCipher;

		$this->note("Packet encryption bridge established between client and destination server");
	}

	private function encodeBatch(Packet $packet) : string{
		return $this->batchBuilder->build(
			$this->batchBuilder->encodePackets([$packet]),
			$this->inspector->isCompressionEnabled(),
			$this->inspector->getCompressionAlgorithm()
		);
	}

	public function handleServerBound(string $payload, Reliability $reliability) : void{
		if($this->closed){
			return;
		}

		$batch = $this->unwrap(Direction::SERVER_BOUND, $payload, $this->clientCipher);
		if($batch === null){
			return;
		}
		$relayed = $this->wrap($batch, $this->serverCipher);

		if($this->upstream === null){
			$this->pending[] = [$relayed, $reliability];

			return;
		}
		$this->sendToUpstream($relayed, $reliability);
	}

	private function unwrap(Direction $direction, string $payload, ?EncryptionContext $cipher) : ?string{
		if($cipher !== null && self::canBeEncrypted($payload)){
			try{
				$payload = $cipher->decrypt($payload);
			}catch(DecryptionException $e){
				$this->warn(
					"Failed to decrypt " . $direction->getLabel() . " batch of " . strlen($payload) . " bytes: " . $e->getMessage() .
					" (starts with 0x" . bin2hex(substr($payload, 0, 16)) . ")"
				);
				$this->close(DisconnectReason::BAD_DATA);

				return null;
			}
		}

		$batchEdit = new BatchEdit();
		$this->batchEdit = $batchEdit;
		try{
			$packets = $this->inspect($direction, $payload, $batchEdit);
		}finally{
			$this->batchEdit = null;
		}

		if($batchEdit->isSuppressed() || $this->closed){
			return null;
		}
		if($packets === null || !$batchEdit->hasSubstitutions()){
			return $payload;
		}

		$buffers = [];
		foreach($packets as $index => $packet){
			$buffers[] = $batchEdit->getSubstitution($index) ?? $packet->buffer;
		}

		return $this->batchBuilder->build($buffers, $this->inspector->isCompressionEnabled(), $this->inspector->getCompressionAlgorithm());
	}

	private static function canBeEncrypted(string $payload) : bool{
		return strlen($payload) >= EncryptionContext::MIN_ENCRYPTED_LENGTH;
	}

	private function wrap(string $batch, ?EncryptionContext $cipher) : string{
		return $cipher === null ? $batch : $cipher->encrypt($batch);
	}

	public function tick() : void{
		if($this->closed){
			return;
		}
		$this->logger->tick();

		$upstream = $this->upstream;
		if($upstream === null){
			$this->checkNegotiation();

			return;
		}

		if(!$upstream->checkLiveness()){
			$this->console->debug("Upstream session for " . $this->getName() . " lost: " . ($upstream->getDisconnectReason()?->getMessage() ?? "unknown"));
			$this->close(DisconnectReason::PEER_DISCONNECT);

			return;
		}

		try{
			while(!$this->closed && ($message = $upstream->receive()) !== null){
				$this->relayToClient($message->payload, $message->reliability);
			}
		}catch(NetherNetException $e){
			$this->console->debug("Upstream session for " . $this->getName() . " failed while reading: " . $e->getMessage());
			$this->close(DisconnectReason::BAD_DATA);
		}
	}

	private function checkNegotiation() : void{
		if(!$this->negotiation->isFinished()){
			return;
		}
		if($this->negotiation->isFailed()){
			$this->console->warning("Failed to connect " . $this->getName() . " to destination server: " . ($this->negotiation->getFailureReason() ?? "unknown"));
			$this->close(DisconnectReason::CONNECTION_FAILED);

			return;
		}

		$this->upstream = $this->negotiation->getSession();
		$this->console->info($this->getName() . " connected to destination server");

		foreach($this->pending as [$payload, $reliability]){
			$this->sendToUpstream($payload, $reliability);
		}
		$this->pending = [];
	}

	private function relayToClient(string $payload, Reliability $reliability) : void{
		$batch = $this->unwrap(Direction::CLIENT_BOUND, $payload, $this->serverCipher);
		if($batch === null || $this->closed){
			return;
		}

		try{
			$this->client->send($this->wrap($batch, $this->clientCipher), $reliability);
		}catch(NetherNetException $e){
			$this->console->debug("Failed to relay packet to client " . $this->getName() . ": " . $e->getMessage());
			$this->close(DisconnectReason::SEND_FAILED);
		}
	}

	private function sendToClient(string $payload, Reliability $reliability) : void{
		try{
			$this->client->send($payload, $reliability);
		}catch(NetherNetException $e){
			$this->console->debug("Failed to send packet to client " . $this->getName() . ": " . $e->getMessage());
			$this->close(DisconnectReason::SEND_FAILED);
		}
	}

	private function sendToUpstream(string $payload, Reliability $reliability) : void{
		$upstream = $this->upstream;
		if($upstream === null){
			return;
		}
		try{
			$upstream->send($payload, $reliability);
		}catch(NetherNetException $e){
			$this->console->debug("Failed to relay packet to destination server for " . $this->getName() . ": " . $e->getMessage());
			$this->close(DisconnectReason::SEND_FAILED);
		}
	}

	/**
	 * @return InspectedPacket[]|null
	 * @phpstan-return list<InspectedPacket>|null
	 */
	private function inspect(Direction $direction, string $payload, BatchEdit $edit) : ?array{
		try{
			$packets = $this->inspector->inspect($payload);
		}catch(InspectionException $e){
			$this->logger->logNote("Failed to decode " . $direction->getLabel() . " batch: " . $e->getMessage(), $direction);

			return null;
		}catch(\Throwable $e){
			$this->reportUnexpected($direction, "reading a batch", $e);

			return null;
		}

		foreach($packets as $index => $packet){
			$edit->setIndex($index);
			$this->handle($direction, $packet);

			try{
				$this->logger->logPacket($direction, $packet);
			}catch(\Throwable $e){
				$this->reportUnexpected($direction, "logging " . $packet->name, $e);
			}
		}

		return $packets;
	}

	private function handle(Direction $direction, InspectedPacket $packet) : void{
		if($packet->packet === null){
			return;
		}

		try{
			$packet->packet->handle($direction === Direction::SERVER_BOUND ? $this->clientHandler : $this->serverHandler);
		}catch(\Throwable $e){
			$this->reportUnexpected($direction, "handling " . $packet->name, $e);
		}
	}

	private function reportUnexpected(Direction $direction, string $what, \Throwable $e) : void{
		$this->logger->logNote("failed while " . $what . ": " . $e->getMessage(), $direction);
		$this->console->debug("Session " . $this->getName() . " failed while " . $what . " (" . $direction->getLabel() . "): " . implode("\n", Utils::printableExceptionInfo($e)));
	}

	public function close(DisconnectReason $reason = DisconnectReason::SERVER_DISCONNECT) : void{
		if($this->closed){
			return;
		}
		$this->closed = true;

		$this->client->initiateDisconnect($reason);
		$this->upstream?->close($reason);
		if(!$this->negotiation->isFinished()){
			$this->negotiation->fail("Proxy session closed");
		}
		$this->logger->close();
	}
}
