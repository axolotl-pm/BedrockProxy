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
use function sprintf;

final class DiscoveredServer implements RemoteServer{

	public function __construct(
		public readonly int $networkId,
		public string $address,
		public int $port,
		public ServerData $serverData,
		public float $lastSeen
	){}

	public function getNetworkId() : string{
		return sprintf("%u", $this->networkId);
	}

	public function getServerData() : ServerData{
		return $this->serverData;
	}

	public function describe() : string{
		return $this->address . ":" . $this->port . " (network ID " . $this->getNetworkId() . ", \"" . $this->serverData->serverName . "\")";
	}
}
