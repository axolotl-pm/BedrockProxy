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

namespace pocketmine\bedrockproxy\packet;

use pocketmine\network\mcpe\protocol\Packet;
use function dechex;
use function str_pad;
use const STR_PAD_LEFT;

final class InspectedPacket{

	public function __construct(
		public readonly int $packetId,
		public readonly string $buffer,
		public readonly string $name,
		public readonly ?Packet $packet = null,
		public readonly ?string $decodeError = null
	){}

	public static function unknownName(int $packetId) : string{
		return "UnknownPacket(0x" . str_pad(dechex($packetId), 2, "0", STR_PAD_LEFT) . ")";
	}
}
