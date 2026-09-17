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

use pocketmine\nethernet\ServerEventListener;
use pocketmine\nethernet\session\DisconnectReason;
use pocketmine\nethernet\session\Reliability;
use pocketmine\nethernet\session\Session;

final class ProxyServerListener implements ServerEventListener{

	public function __construct(
		private readonly ProxyServer $proxy
	){}

	public function onSessionOpen(Session $session) : void{
		$this->proxy->onClientConnect($session);
	}

	public function canAcceptPackets() : bool{
		return true;
	}

	public function onPacketReceive(Session $session, string $payload, Reliability $reliability) : void{
		$this->proxy->onClientPacket($session, $payload, $reliability);
	}

	public function onSessionClose(Session $session, DisconnectReason $reason) : void{
		$this->proxy->onClientDisconnect($session, $reason);
	}
}
