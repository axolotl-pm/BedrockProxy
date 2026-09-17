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

namespace pocketmine\bedrockproxy\network\handler;

use pocketmine\bedrockproxy\network\ProxySession;
use pocketmine\network\mcpe\handler\PacketHandler;

/**
 * Base packet handler for a directional relayed session.
 *
 * Returning true from a handler method indicates the packet was consumed by the handler. Packets
 * are relayed byte-for-byte by default unless explicitly modified or suppressed by the session.
 */
abstract class ProxyPacketHandler extends PacketHandler{

	public function __construct(
		protected readonly ProxySession $session
	){}
}
