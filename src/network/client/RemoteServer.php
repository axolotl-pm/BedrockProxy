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

use pocketmine\nethernet\discovery\ServerData;

/**
 * A server that a ClientSignalingInterface implementation knows how to reach.
 */
interface RemoteServer{

	/**
	 * Returns the network ID in the string form used to match signals with negotiations.
	 */
	public function getNetworkId() : string;

	/**
	 * Returns the advertisement of the server, or null if the signaling transport does not learn one.
	 */
	public function getServerData() : ?ServerData;

	/**
	 * Returns a human-readable description for logging.
	 */
	public function describe() : string;
}
