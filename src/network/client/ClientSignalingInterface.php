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

use pocketmine\nethernet\discovery\Signal;
use pocketmine\nethernet\negotiation\CandidateMode;
use pocketmine\nethernet\signaling\SignalingException;

/**
 * Client side of a signaling transport: resolves the destination server and exchanges signals with it.
 */
interface ClientSignalingInterface{

	/**
	 * @throws SignalingException
	 */
	public function start() : void;

	public function tick() : void;

	public function shutdown() : void;

	public function getCandidateMode() : CandidateMode;

	/**
	 * Returns the destination as currently resolved, or null if it is not known yet.
	 */
	public function getRemoteServer() : ?RemoteServer;

	/**
	 * @throws SignalingException
	 */
	public function sendSignal(RemoteServer $server, Signal $signal) : void;

	/**
	 * Drains signals received since the last call, each paired with the network ID of its sender.
	 *
	 * @return array[]
	 * @phpstan-return list<array{string, Signal}>
	 */
	public function takeSignals() : array;
}
