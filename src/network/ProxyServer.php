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

use pocketmine\bedrockproxy\Configuration;
use pocketmine\bedrockproxy\data\DataExtractionException;
use pocketmine\bedrockproxy\data\DataExtractor;
use pocketmine\bedrockproxy\logging\SessionLogger;
use pocketmine\bedrockproxy\network\client\ClientSignalingInterface;
use pocketmine\bedrockproxy\network\client\HttpClientSignaling;
use pocketmine\bedrockproxy\network\client\LanClientSignaling;
use pocketmine\bedrockproxy\network\client\NetherNetClient;
use pocketmine\bedrockproxy\packet\BlockStateLookup;
use pocketmine\bedrockproxy\packet\PacketDumper;
use pocketmine\bedrockproxy\SignalingType;
use pocketmine\nbt\NbtDataException;
use pocketmine\nethernet\ConnectionBudgetConfiguration;
use pocketmine\nethernet\crypto\CryptoException;
use pocketmine\nethernet\discovery\LanSignaling;
use pocketmine\nethernet\discovery\MutableServerDataProvider;
use pocketmine\nethernet\identity\AssertionIdentityVerifier;
use pocketmine\nethernet\identity\SelfSignedIdentityProvider;
use pocketmine\nethernet\identity\ServerIdentity;
use pocketmine\nethernet\negotiation\ConfiguredPeerConnectionFactory;
use pocketmine\nethernet\negotiation\NegotiationException;
use pocketmine\nethernet\NetherNetServer;
use pocketmine\nethernet\ServerConfiguration;
use pocketmine\nethernet\session\DisconnectReason;
use pocketmine\nethernet\session\Reliability;
use pocketmine\nethernet\session\Session;
use pocketmine\nethernet\signaling\http\HttpSignaling;
use pocketmine\nethernet\signaling\http\MutableServerStatusProvider;
use pocketmine\nethernet\signaling\SignalingException;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use Symfony\Component\Filesystem\Path;
use function array_fill_keys;
use function count;
use function hash;
use function implode;
use function is_file;
use function microtime;
use function substr;
use function unpack;
use const PHP_INT_MAX;

/**
 * Accepts game clients over NetherNet, connects each of them to the destination server over NetherNet, and
 * relays batches between the two.
 */
final class ProxyServer{

	/**
	 * Seconds between refreshes of the advertisement mirrored from the destination server.
	 */
	private const ADVERTISEMENT_INTERVAL = 1.0;

	/**
	 * Unread bytes and messages a session may hold before it is dropped.
	 *
	 * A proxy carries the traffic of a whole session through one process, and a server opens with a burst of
	 * resource packs and chunks, so the defaults of the library are far too small here.
	 */
	private const MAX_RECEIVE_QUEUE_SIZE = 16 * 1024 * 1024;
	private const MAX_RECEIVE_QUEUE_MESSAGES = 8192;
	private const MAX_SEND_QUEUE_SIZE = 16 * 1024 * 1024;

	/**
	 * Largest batch the proxy will put back together out of segments.
	 */
	private const MAX_PAYLOAD_SIZE = 8 * 1024 * 1024;

	private NetherNetServer $server;
	private bool $lanActive;
	private readonly ServerIdentity $identity;
	private readonly ConfiguredPeerConnectionFactory $peerConnectionFactory;
	private readonly ConnectionBudgetConfiguration $budget;
	private readonly NetherNetClient $client;
	private readonly MutableServerDataProvider $advertisement;
	private readonly MutableServerStatusProvider $status;
	private readonly ?BlockStateLookup $blockStates;
	private readonly ?DataExtractor $extractor;
	private readonly int $networkId;

	/**
	 * @var true[]
	 * @phpstan-var array<string, true>
	 */
	private readonly array $ignoredPackets;

	/**
	 * @var ProxySession[]
	 * @phpstan-var array<int, ProxySession>
	 */
	private array $sessions = [];

	private float $nextAdvertisement = 0.0;
	private bool $shutDown = false;

	/**
	 * @throws CryptoException
	 * @throws \InvalidArgumentException
	 */
	public function __construct(
		private readonly Configuration $config,
		private readonly string $dataPath,
		private readonly \Logger $logger
	){
		$identity = $this->loadIdentity();
		$this->networkId = $config->networkId !== 0 ? $config->networkId : self::generateNetworkIdFromIdentity($identity);
		$this->blockStates = $config->logPackets ? $this->loadBlockStates() : null;
		$this->ignoredPackets = array_fill_keys($config->ignoredPackets, true);
		$this->extractor = $this->createExtractor();

		$peerConnectionFactory = new ConfiguredPeerConnectionFactory(bindAddress: $config->iceBindAddress);
		$this->budget = new ConnectionBudgetConfiguration(
			maxPayloadSize: self::MAX_PAYLOAD_SIZE,
			maxReceiveQueueSize: self::MAX_RECEIVE_QUEUE_SIZE,
			maxReceiveQueueMessages: self::MAX_RECEIVE_QUEUE_MESSAGES,
			maxSendQueueSize: self::MAX_SEND_QUEUE_SIZE
		);

		$this->identity = $identity;
		$this->peerConnectionFactory = $peerConnectionFactory;
		$this->advertisement = new MutableServerDataProvider(ServerAdvertisement::placeholder($config->serverName, $config->levelName));
		$this->status = new MutableServerStatusProvider();

		$this->lanActive = $config->lanEnabled;
		$this->server = $this->createServer($this->lanActive);

		$this->client = new NetherNetClient(
			signaling: $this->createClientSignaling($config),
			peerConnectionFactory: $peerConnectionFactory,
			identityVerifier: new AssertionIdentityVerifier(allowAnonymous: true),
			identityProvider: new SelfSignedIdentityProvider($identity),
			budget: $this->budget,
			negotiationTimeout: $config->destinationTimeout,
			logger: $logger
		);
	}

	/**
	 * Builds the client-facing server with the signaling transports the configuration asks for.
	 */
	private function createExtractor() : ?DataExtractor{
		if(!$this->config->extractData){
			return null;
		}

		$path = Path::makeAbsolute($this->config->dataPath, $this->dataPath);
		try{
			$extractor = DataExtractor::create($path, $this->logger);
		}catch(DataExtractionException $e){
			$this->logger->warning("Failed to initialize data extractor: " . $e->getMessage());

			return null;
		}
		$this->logger->info("Extracting item, recipe and creative data into $path");

		return $extractor;
	}

	private function createServer(bool $lanEnabled) : NetherNetServer{
		$server = NetherNetServer::create(
			new ServerConfiguration(
				identityProvider: new SelfSignedIdentityProvider($this->identity),
				// The destination is expected to run in offline mode, so clients are not held to an identity.
				identityVerifier: new AssertionIdentityVerifier(allowAnonymous: true),
				peerConnectionFactory: $this->peerConnectionFactory,
				budget: $this->budget,
				logger: $this->logger
			),
			new ProxyServerListener($this)
		);

		if($lanEnabled){
			$server->addSignaling(new LanSignaling(
				negotiator: $server->getNegotiator(),
				serverDataProvider: $this->advertisement,
				networkId: $this->networkId,
				bindAddress: $this->config->bindAddress,
				port: $this->config->lanPort,
				logger: $this->logger
			));
		}
		if($this->config->httpEnabled){
			$server->addSignaling(new HttpSignaling(
				negotiator: $server->getNegotiator(),
				bindAddress: $this->config->bindAddress,
				port: $this->config->httpPort,
				logger: $this->logger,
				statusProvider: $this->status
			));
		}

		return $server;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private function createClientSignaling(Configuration $config) : ClientSignalingInterface{
		return match($config->destinationSignaling){
			SignalingType::LAN => new LanClientSignaling(
				networkId: $this->networkId,
				address: $config->destinationAddress,
				port: $config->destinationPort,
				expectedNetworkId: $config->destinationNetworkId,
				bindAddress: $config->bindAddress,
				logger: $this->logger
			),
			SignalingType::HTTP => new HttpClientSignaling(
				url: $config->destinationAddress,
				networkId: (string) $this->networkId,
				logger: $this->logger
			)
		};
	}

	/**
	 * Loads the block palette the packet log names block states from. A palette that cannot be read costs only
	 * the names, so it is reported and the log falls back to bare IDs.
	 */
	private function loadBlockStates() : ?BlockStateLookup{
		try{
			$lookup = BlockStateLookup::fromBedrockData($this->config->blockStateIdMode);
		}catch(\RuntimeException | NbtDataException $e){
			$this->logger->warning("Block network IDs will not be named in the packet log: " . $e->getMessage());

			return null;
		}

		$this->logger->debug("Naming block states from bedrock-data " . BlockStateLookup::getBedrockDataVersion() . " in " . $this->config->blockStateIdMode->value . " mode");

		return $lookup;
	}

	/**
	 * @throws CryptoException
	 */
	private function loadIdentity() : ServerIdentity{
		$path = Path::makeAbsolute($this->config->identityKeyFile, $this->dataPath);
		if(is_file($path)){
			return ServerIdentity::fromPrivateKeyPem(Filesystem::fileGetContents($path));
		}

		$identity = ServerIdentity::generate();
		Filesystem::safeFilePutContents($path, $identity->exportPrivateKeyPem());
		$this->logger->info("Generated a new NetherNet identity in $path");

		return $identity;
	}

	/**
	 * Derives a stable 64-bit positive network ID from the identity key, so the proxy keeps its place in the
	 * LAN list across restarts.
	 */
	private static function generateNetworkIdFromIdentity(ServerIdentity $identity) : int{
		$digest = hash("sha256", $identity->getPublicKey()->toCpk(), binary: true);
		$id = unpack("P", substr($digest, 0, 8));

		return $id === false ? 1 : (($id[1] & PHP_INT_MAX) | 1);
	}

	public function getNetworkId() : int{ return $this->networkId; }

	/**
	 * @throws SignalingException
	 */
	public function start() : void{
		try{
			$this->server->start();
		}catch(SignalingException $e){
			// Losing LAN discovery costs the proxy its place in the LAN list, but it can still be reached at its
			// HTTP endpoint, so it is worth carrying on without it. Another host holding the port is the usual
			// cause, most often the destination server itself.
			if(!$this->lanActive || !$this->config->httpEnabled){
				throw $e;
			}
			$this->logger->warning("Carrying on without LAN discovery: " . $e->getMessage());
			$this->lanActive = false;
			$this->server = $this->createServer(false);
			$this->server->start();
		}
		$this->client->start();

		$this->logger->info("Proxy is listening as network ID " . $this->networkId);
		if($this->lanActive){
			$this->logger->info("LAN discovery is answering on " . $this->config->bindAddress . ":" . $this->config->lanPort);
		}
		if($this->config->httpEnabled){
			$this->logger->info("HTTP signaling is listening on " . $this->config->bindAddress . ":" . $this->config->httpPort);
		}
	}

	public function isRunning() : bool{
		return $this->server->isRunning() && !$this->shutDown;
	}

	public function tick() : void{
		if($this->shutDown){
			return;
		}

		$this->client->tick();
		$this->server->tick();

		foreach($this->sessions as $id => $session){
			// One session must never take the proxy down with it, so it is dropped on its own instead.
			try{
				$session->tick();
			}catch(\Throwable $e){
				$this->logger->error("Dropping the session of " . $session->getName() . " after an unexpected failure: " . implode("\n", Utils::printableExceptionInfo($e)));
				$session->close(DisconnectReason::BAD_DATA);
			}
			if($session->isClosed()){
				unset($this->sessions[$id]);
			}
		}

		$this->updateAdvertisement();
	}

	/**
	 * Mirrors the advertisement of the destination server so the proxy shows the same name and player counts.
	 */
	private function updateAdvertisement() : void{
		$now = microtime(true);
		if($now < $this->nextAdvertisement){
			return;
		}
		$this->nextAdvertisement = $now + self::ADVERTISEMENT_INTERVAL;

		$serverData = $this->client->getRemoteServer()?->getServerData();
		if($serverData === null){
			return;
		}

		$this->advertisement->setServerData(ServerAdvertisement::serverData($serverData, $this->config->serverName, $this->config->levelName));
		$this->status->setServerStatus(ServerAdvertisement::status($serverData, $this->config->serverName, $this->config->levelName));
	}

	/** @internal */
	public function onClientConnect(Session $session) : void{
		// Each session gets its own dumper, because a server announces how it numbers block states, and which
		// custom blocks it has, per session.
		$dumper = new PacketDumper($this->blockStates);
		$logger = new SessionLogger(
			$this->config->logTarget,
			Path::join($this->dataPath, "sessions"),
			"session" . $session->getId(),
			$this->ignoredPackets,
			$dumper,
			$this->logger
		);

		try{
			$negotiation = $this->client->connect();
		}catch(NegotiationException $e){
			$this->logger->warning("Refusing a client from " . ($session->getRemoteAddress() ?? "unknown") . ": " . $e->getMessage());
			$session->initiateDisconnect(DisconnectReason::REJECTED_BY_HOST);
			$logger->close();

			return;
		}

		$this->logger->info("Client " . ($session->getRemoteAddress() ?? "unknown") . " connected; initiating upstream connection to " . $negotiation->getServer()->describe());
		$encryption = null;
		if($this->config->decryptPackets){
			try{
				$encryption = new EncryptionBridge();
			}catch(EncryptionBridgeException $e){
				$this->logger->warning("Packet encryption will be passed through untouched: " . $e->getMessage());
			}
		}

		$this->sessions[$session->getId()] = new ProxySession($session, $negotiation, $logger, $dumper, $this->logger, $this->config->logPackets, $encryption, $this->extractor);
	}

	/**
	 * Called from inside the NetherNet server, so anything escaping here would stop the whole proxy. The session
	 * is dropped on its own instead.
	 *
	 * @internal
	 */
	public function onClientPacket(Session $session, string $payload, Reliability $reliability) : void{
		$proxySession = $this->sessions[$session->getId()] ?? null;
		if($proxySession === null){
			return;
		}

		try{
			$proxySession->handleServerBound($payload, $reliability);
		}catch(\Throwable $e){
			$this->logger->error("Dropping the session of " . $proxySession->getName() . " after an unexpected failure: " . implode("\n", Utils::printableExceptionInfo($e)));
			$proxySession->close(DisconnectReason::BAD_DATA);
			unset($this->sessions[$session->getId()]);
		}
	}

	/** @internal */
	public function onClientDisconnect(Session $session, DisconnectReason $reason) : void{
		$proxySession = $this->sessions[$session->getId()] ?? null;
		if($proxySession === null){
			return;
		}
		unset($this->sessions[$session->getId()]);
		$this->logger->info("Client " . $proxySession->getName() . " disconnected: " . $reason->getMessage());

		try{
			$proxySession->close($reason);
		}catch(\Throwable $e){
			$this->logger->error("Failed to close the session of " . $proxySession->getName() . ": " . implode("\n", Utils::printableExceptionInfo($e)));
		}
	}

	public function shutdown() : void{
		if($this->shutDown){
			return;
		}
		$this->shutDown = true;

		foreach($this->sessions as $session){
			$session->close(DisconnectReason::SERVER_SHUTDOWN);
		}
		$this->sessions = [];
		$this->client->shutdown();
		$this->server->shutdown();
	}

	public function count() : int{
		return count($this->sessions);
	}
}
