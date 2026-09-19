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
use pocketmine\network\mcpe\protocol\PacketHandlerDefaultImplTrait;
use pocketmine\network\mcpe\protocol\PacketHandlerInterface;

/**
 * Base packet handler for a directional relayed session.
 *
 * Returning true from a handler method indicates the packet was consumed by the handler. Packets
 * are relayed byte-for-byte by default unless explicitly modified or suppressed by the session.
 */
abstract class ProxyPacketHandler implements PacketHandlerInterface{
	use PacketHandlerDefaultImplTrait;

	public function __construct(
		protected readonly ProxySession $session
	){}

	/**
	 * Called once the handler has been installed on the session, so that it can send whatever the new state owes
	 * the other side before any packet arrives.
	 */
	public function setUp() : void{

	}
}
