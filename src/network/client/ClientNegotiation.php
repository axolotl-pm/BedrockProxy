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

use pmmp\webrtc\DataChannel;
use pmmp\webrtc\IceCandidate;
use pmmp\webrtc\PeerConnection;
use pmmp\webrtc\WebRtcException;
use pocketmine\nethernet\identity\PeerIdentity;
use pocketmine\nethernet\negotiation\ErrorCode;
use pocketmine\nethernet\negotiation\NegotiationException;
use pocketmine\nethernet\session\Session;

final class ClientNegotiation{

	private ClientNegotiationState $state = ClientNegotiationState::OFFERING;
	private ?string $failureReason = null;
	private ErrorCode $failureCode = ErrorCode::NONE;
	private bool $failedByPeer = false;

	private ?string $localUfrag = null;
	private int $candidateIndex = 0;
	private ?PeerIdentity $remoteIdentity = null;
	private ?Session $session = null;

	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $pendingRemoteCandidates = [];

	/** @internal */
	public function __construct(
		private readonly int $id,
		private readonly PeerConnection $peerConnection,
		private readonly DataChannel $reliableChannel,
		private readonly DataChannel $unreliableChannel,
		private readonly RemoteServer $server,
		private readonly string $connectionId,
		private float $deadline
	){}

	public function getId() : int{ return $this->id; }

	public function getState() : ClientNegotiationState{ return $this->state; }

	public function isFinished() : bool{ return $this->state->isFinished(); }

	public function isFailed() : bool{ return $this->state === ClientNegotiationState::FAILED; }

	public function getFailureReason() : ?string{ return $this->failureReason; }

	public function getFailureCode() : ErrorCode{ return $this->failureCode; }

	public function getServer() : RemoteServer{ return $this->server; }

	public function getConnectionId() : string{ return $this->connectionId; }

	public function getSession() : ?Session{ return $this->session; }

	public function isFailedByPeer() : bool{ return $this->failedByPeer; }

	public function fail(string $reason, ErrorCode $code = ErrorCode::GENERIC_FAILURE, bool $byPeer = false) : void{
		if($this->state->isFinished()){
			return;
		}
		$this->state = ClientNegotiationState::FAILED;
		$this->failureReason = $reason;
		$this->failureCode = $code;
		$this->failedByPeer = $byPeer;

		try{
			$this->peerConnection->close();
		}catch(WebRtcException){
		}
	}

	/**
	 * @throws NegotiationException
	 */
	public function addRemoteCandidate(string $candidate) : void{
		if($this->state->isFinished()){
			return;
		}
		if($this->state === ClientNegotiationState::OFFERING || $this->state === ClientNegotiationState::OFFERED){
			$this->pendingRemoteCandidates[] = $candidate;

			return;
		}

		try{
			$this->peerConnection->addRemoteCandidate(IceCandidate::create($candidate));
		}catch(WebRtcException $e){
			throw new NegotiationException("Invalid remote ICE candidate received: " . $e->getMessage(), ErrorCode::CANDIDATE_ADD, $e);
		}
	}

	/** @internal */
	public function getPeerConnection() : PeerConnection{ return $this->peerConnection; }

	/** @internal */
	public function getReliableChannel() : DataChannel{ return $this->reliableChannel; }

	/** @internal */
	public function getUnreliableChannel() : DataChannel{ return $this->unreliableChannel; }

	/** @internal */
	public function getDeadline() : float{ return $this->deadline; }

	/** @internal */
	public function getLocalUfrag() : ?string{ return $this->localUfrag; }

	/** @internal */
	public function nextCandidateIndex() : int{ return $this->candidateIndex++; }

	/** @internal */
	public function getRemoteIdentity() : ?PeerIdentity{ return $this->remoteIdentity; }

	/** @internal */
	public function setOffered(string $localUfrag, float $deadline) : void{
		$this->localUfrag = $localUfrag;
		$this->deadline = $deadline;
		$this->state = ClientNegotiationState::OFFERED;
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 *
	 * @internal
	 */
	public function setAnswered(?PeerIdentity $remoteIdentity, float $deadline) : array{
		$this->remoteIdentity = $remoteIdentity;
		$this->deadline = $deadline;
		$this->state = ClientNegotiationState::ANSWERED;

		$held = $this->pendingRemoteCandidates;
		$this->pendingRemoteCandidates = [];

		return $held;
	}

	/** @internal */
	public function establish(Session $session) : void{
		$this->session = $session;
		$this->state = ClientNegotiationState::ESTABLISHED;
	}
}
