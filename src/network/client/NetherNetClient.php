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

use pmmp\webrtc\ConnectionState;
use pmmp\webrtc\DataChannel;
use pmmp\webrtc\DataChannelOptions;
use pmmp\webrtc\GatheringState;
use pmmp\webrtc\PeerConnection;
use pmmp\webrtc\WebRtcException;
use pocketmine\nethernet\ConnectionBudgetConfiguration;
use pocketmine\nethernet\crypto\CryptoException;
use pocketmine\nethernet\discovery\Signal;
use pocketmine\nethernet\discovery\SignalType;
use pocketmine\nethernet\identity\IdentityException;
use pocketmine\nethernet\identity\IdentityProvider;
use pocketmine\nethernet\identity\IdentityVerifier;
use pocketmine\nethernet\negotiation\CandidateMode;
use pocketmine\nethernet\negotiation\ErrorCode;
use pocketmine\nethernet\negotiation\IceCandidateFormatter;
use pocketmine\nethernet\negotiation\NegotiationException;
use pocketmine\nethernet\negotiation\PeerConnectionFactory;
use pocketmine\nethernet\sdp\SdpException;
use pocketmine\nethernet\sdp\SessionDescription;
use pocketmine\nethernet\session\framing\Segmenter;
use pocketmine\nethernet\session\Reliability;
use pocketmine\nethernet\session\Session;
use pocketmine\nethernet\signaling\SignalingException;
use function count;
use function microtime;
use function random_int;
use const PHP_INT_MAX;

final class NetherNetClient{

	public const DEFAULT_NEGOTIATION_TIMEOUT = 15.0;
	public const DEFAULT_CHANNEL_TIMEOUT = 5.0;

	/**
	 * @var ClientNegotiation[]
	 * @phpstan-var array<int, ClientNegotiation>
	 */
	private array $negotiations = [];

	private int $nextNegotiationId = 0;
	private bool $started = false;
	private bool $shutDown = false;

	public function __construct(
		private readonly ClientSignalingInterface $signaling,
		private readonly PeerConnectionFactory $peerConnectionFactory,
		private readonly IdentityVerifier $identityVerifier,
		private readonly ?IdentityProvider $identityProvider = null,
		private readonly ConnectionBudgetConfiguration $budget = new ConnectionBudgetConfiguration(),
		private readonly float $negotiationTimeout = self::DEFAULT_NEGOTIATION_TIMEOUT,
		private readonly float $channelTimeout = self::DEFAULT_CHANNEL_TIMEOUT,
		private readonly ?\Logger $logger = null
	){
		if($negotiationTimeout <= 0.0 || $channelTimeout <= 0.0){
			throw new \InvalidArgumentException("Timeouts must be positive");
		}
	}

	/**
	 * @throws SignalingException
	 */
	public function start() : void{
		if($this->started){
			throw new \LogicException("Client is already started");
		}
		$this->started = true;
		$this->signaling->start();
	}

	public function isRunning() : bool{
		return $this->started && !$this->shutDown;
	}

	public function getSignaling() : ClientSignalingInterface{ return $this->signaling; }

	public function getRemoteServer() : ?RemoteServer{
		return $this->signaling->getRemoteServer();
	}

	/**
	 * @throws NegotiationException
	 */
	public function connect() : ClientNegotiation{
		if(!$this->isRunning()){
			throw new NegotiationException("Client is not running", ErrorCode::NO_SIGNALING_CHANNEL);
		}
		$server = $this->signaling->getRemoteServer();
		if($server === null){
			throw new NegotiationException("Destination server not found", ErrorCode::DESTINATION_NOT_LOGGED_IN);
		}

		try{
			$peerConnection = $this->peerConnectionFactory->create($this->budget);
		}catch(WebRtcException $e){
			throw new NegotiationException("Failed to create peer connection: " . $e->getMessage(), ErrorCode::FAILED_TO_CREATE_PEER_CONNECTION, $e);
		}

		try{
			$reliable = $peerConnection->createDataChannel(Reliability::RELIABLE->getChannelLabel());
			$unreliable = $peerConnection->createDataChannel(
				Reliability::UNRELIABLE->getChannelLabel(),
				DataChannelOptions::create()->setUnordered(true)->setMaxRetransmits(0)
			);
		}catch(WebRtcException $e){
			$this->discard($peerConnection);

			throw new NegotiationException("Failed to create data channels: " . $e->getMessage(), ErrorCode::FAILED_TO_CREATE_OFFER, $e);
		}

		$negotiation = new ClientNegotiation(
			$this->nextNegotiationId++,
			$peerConnection,
			$reliable,
			$unreliable,
			$server,
			(string) random_int(1, PHP_INT_MAX),
			microtime(true) + $this->negotiationTimeout
		);
		$this->negotiations[$negotiation->getId()] = $negotiation;
		$this->logger?->debug("Negotiation " . $negotiation->getId() . " (connection " . $negotiation->getConnectionId() . ") started towards " . $server->describe());

		return $negotiation;
	}

	public function tick() : void{
		if(!$this->isRunning()){
			return;
		}

		$this->signaling->tick();
		foreach($this->signaling->takeSignals() as [$networkId, $signal]){
			$this->handleSignal($networkId, $signal);
		}

		$now = microtime(true);
		foreach($this->negotiations as $id => $negotiation){
			try{
				$this->advance($negotiation, $now);
			}catch(WebRtcException $e){
				$negotiation->fail("WebRTC peer connection failure: " . $e->getMessage(), ErrorCode::FAILED_TO_CREATE_PEER_CONNECTION);
			}

			if($negotiation->isFinished()){
				if($negotiation->isFailed()){
					$this->logger?->debug("Negotiation $id failed: " . ($negotiation->getFailureReason() ?? "no reason given"));
					if(!$negotiation->isFailedByPeer()){
						$this->signalError($negotiation);
					}
				}
				unset($this->negotiations[$id]);
			}
		}
	}

	public function shutdown() : void{
		if($this->shutDown){
			return;
		}
		$this->shutDown = true;

		foreach($this->negotiations as $negotiation){
			$negotiation->fail("Client is shutting down", ErrorCode::NO_SIGNALING_CHANNEL);
			$this->signalError($negotiation);
		}
		$this->negotiations = [];
		$this->signaling->shutdown();
	}

	private function handleSignal(string $networkId, Signal $signal) : void{
		$negotiation = null;
		foreach($this->negotiations as $candidate){
			if($candidate->getConnectionId() === $signal->connectionId && $candidate->getServer()->getNetworkId() === $networkId){
				$negotiation = $candidate;
				break;
			}
		}
		if($negotiation === null){
			$this->logger?->debug("Ignoring a " . $signal->type->value . " signal from $networkId for unknown connection " . $signal->connectionId);

			return;
		}

		switch($signal->type){
			case SignalType::CONNECT_RESPONSE:
				$this->handleAnswer($negotiation, $signal->data);
				break;
			case SignalType::CANDIDATE_ADD:
				try{
					$negotiation->addRemoteCandidate($signal->data);
				}catch(NegotiationException $e){
					$this->logger?->debug("Invalid ICE candidate received: " . $e->getMessage());
				}
				break;
			case SignalType::CONNECT_ERROR:
				$negotiation->fail("Server reported error code " . $signal->data, ErrorCode::GENERIC_FAILURE, byPeer: true);
				break;
			case SignalType::CONNECT_REQUEST:
				break;
		}
	}

	private function handleAnswer(ClientNegotiation $negotiation, string $answerSdp) : void{
		if($negotiation->getState() !== ClientNegotiationState::OFFERED){
			$this->logger?->debug("Ignoring an answer for negotiation " . $negotiation->getId() . " in state " . $negotiation->getState()->name);

			return;
		}

		try{
			$answer = SessionDescription::parse($answerSdp);
			$answer->validate();
			$fingerprint = $answer->getFingerprint();
		}catch(SdpException $e){
			$negotiation->fail("Answer is invalid: " . $e->getMessage(), ErrorCode::FAILED_TO_SET_REMOTE_DESCRIPTION);

			return;
		}

		try{
			$identity = $this->identityVerifier->verify($answer->getIdentity(), $fingerprint);
		}catch(IdentityException $e){
			$negotiation->fail("Server identity rejected: " . $e->getMessage(), ErrorCode::IDENTITY_NOT_ALLOWED);

			return;
		}

		try{
			$negotiation->getPeerConnection()->setRemoteAnswer($answer->withoutIdentity()->toString());
		}catch(WebRtcException $e){
			$negotiation->fail("WebRTC peer connection failure: " . $e->getMessage(), ErrorCode::FAILED_TO_SET_REMOTE_DESCRIPTION);

			return;
		}

		foreach($negotiation->setAnswered($identity, microtime(true) + $this->channelTimeout) as $candidate){
			try{
				$negotiation->addRemoteCandidate($candidate);
			}catch(NegotiationException $e){
				$this->logger?->debug("Invalid ICE candidate received: " . $e->getMessage());
			}
		}
	}

	/**
	 * @throws WebRtcException
	 */
	private function advance(ClientNegotiation $negotiation, float $now) : void{
		$state = $negotiation->getPeerConnection()->getState();
		$broken = match($state){
			ConnectionState::FAILED, ConnectionState::CLOSED => true,
			default => false
		};
		if($broken){
			$negotiation->fail("Peer connection entered state " . $state->name, ErrorCode::ICE);

			return;
		}

		switch($negotiation->getState()){
			case ClientNegotiationState::OFFERING:
				$this->advanceOffering($negotiation, $now);
				break;
			case ClientNegotiationState::OFFERED:
				$this->trickleCandidates($negotiation);
				$this->checkDeadline($negotiation, $now, "Timed out waiting for an answer", ErrorCode::NEGOTIATION_TIMEOUT_WAITING_FOR_RESPONSE);
				break;
			case ClientNegotiationState::ANSWERED:
				$this->trickleCandidates($negotiation);
				$this->advanceAnswered($negotiation, $now);
				break;
			default:
				break;
		}
	}

	/**
	 * @throws WebRtcException
	 */
	private function advanceOffering(ClientNegotiation $negotiation, float $now) : void{
		$peerConnection = $negotiation->getPeerConnection();
		$candidateMode = $this->signaling->getCandidateMode();

		if($candidateMode === CandidateMode::BUNDLED && $peerConnection->getGatheringState() !== GatheringState::COMPLETE){
			$this->checkDeadline($negotiation, $now, "Timed out gathering ICE candidates", ErrorCode::NEGOTIATION_TIMEOUT);

			return;
		}

		$sdp = $peerConnection->getLocalDescription();
		if($sdp === null){
			$this->checkDeadline($negotiation, $now, "Timed out waiting for a local description", ErrorCode::FAILED_TO_CREATE_OFFER);

			return;
		}

		try{
			$offer = SessionDescription::parse($sdp);
			$ufrag = $offer->getMediaAttributeValues("ice-ufrag")[0] ?? throw new SdpException("Local description has no ice-ufrag");
			if($candidateMode === CandidateMode::TRICKLE){
				$offer = $offer->withoutCandidates();
			}
			if($this->identityProvider !== null){
				$offer = $offer->withIdentity($this->identityProvider->issue($offer->getFingerprint())->encode());
			}
		}catch(SdpException|CryptoException $e){
			$negotiation->fail("Failed to create offer: " . $e->getMessage(), ErrorCode::FAILED_TO_CREATE_OFFER);

			return;
		}

		try{
			$this->signaling->sendSignal($negotiation->getServer(), new Signal(SignalType::CONNECT_REQUEST, $negotiation->getConnectionId(), $offer->toString()));
		}catch(SignalingException $e){
			$negotiation->fail("Failed to send offer: " . $e->getMessage(), ErrorCode::SIGNALING_FAILED_TO_SEND);

			return;
		}

		$negotiation->setOffered($ufrag, $negotiation->getDeadline());
	}

	/**
	 * @throws WebRtcException
	 */
	private function advanceAnswered(ClientNegotiation $negotiation, float $now) : void{
		$reliable = $negotiation->getReliableChannel();
		$unreliable = $negotiation->getUnreliableChannel();
		if($reliable->isClosed() || $unreliable->isClosed()){
			$negotiation->fail("Data channel closed prematurely", ErrorCode::DATA_CHANNEL_CLOSED);

			return;
		}
		if(!$reliable->isOpen() || !$unreliable->isOpen()){
			$this->checkDeadline($negotiation, $now, "Timed out waiting for data channels to open", ErrorCode::NEGOTIATION_TIMEOUT_WAITING_FOR_ACCEPT);

			return;
		}

		$channels = [
			Reliability::RELIABLE->name => $reliable,
			Reliability::UNRELIABLE->name => $unreliable
		];
		$negotiation->establish(new Session(
			$negotiation->getId(),
			$negotiation->getPeerConnection(),
			$channels,
			$negotiation->getServer()->getNetworkId(),
			$negotiation->getRemoteIdentity(),
			$this->segmenterFor($channels),
			$this->budget
		));
		$this->logger?->debug("Negotiation " . $negotiation->getId() . " established a session with " . $negotiation->getServer()->describe());
	}

	/**
	 * @throws WebRtcException
	 */
	private function trickleCandidates(ClientNegotiation $negotiation) : void{
		$ufrag = $negotiation->getLocalUfrag();
		if($ufrag === null || $this->signaling->getCandidateMode() !== CandidateMode::TRICKLE){
			return;
		}

		foreach($negotiation->getPeerConnection()->pollLocalCandidates() as $candidate){
			try{
				$formatted = IceCandidateFormatter::format($candidate->getCandidate(), $ufrag, $negotiation->nextCandidateIndex());
			}catch(NegotiationException){
				continue;
			}

			try{
				$this->signaling->sendSignal($negotiation->getServer(), new Signal(SignalType::CANDIDATE_ADD, $negotiation->getConnectionId(), $formatted));
			}catch(SignalingException $e){
				$negotiation->fail("Failed to send ICE candidate: " . $e->getMessage(), ErrorCode::SIGNALING_FAILED_TO_SEND);

				return;
			}
		}
	}

	/**
	 * @param DataChannel[] $channels
	 * @phpstan-param array<string, DataChannel> $channels
	 */
	private function segmenterFor(array $channels) : Segmenter{
		$configured = $this->budget->createSegmenter();
		if(count($channels) === 0){
			return $configured;
		}

		$negotiated = null;
		foreach($channels as $channel){
			$size = $channel->getMaxMessageSize();
			if($negotiated === null || $size < $negotiated){
				$negotiated = $size;
			}
		}
		$payload = ($negotiated ?? 0) - 1;

		return $payload >= 1 && $payload < $configured->getMaxSegmentPayloadSize() ? new Segmenter($payload) : $configured;
	}

	private function checkDeadline(ClientNegotiation $negotiation, float $now, string $reason, ErrorCode $code) : void{
		if($now >= $negotiation->getDeadline()){
			$negotiation->fail($reason, $code);
		}
	}

	private function signalError(ClientNegotiation $negotiation) : void{
		try{
			$this->signaling->sendSignal($negotiation->getServer(), new Signal(
				SignalType::CONNECT_ERROR,
				$negotiation->getConnectionId(),
				(string) $negotiation->getFailureCode()->value
			));
		}catch(SignalingException){
		}
	}

	private function discard(PeerConnection $peerConnection) : void{
		try{
			$peerConnection->close();
		}catch(WebRtcException){
		}
	}
}
