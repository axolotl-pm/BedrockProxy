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

enum ClientNegotiationState{
	case OFFERING;
	case OFFERED;
	case ANSWERED;
	case ESTABLISHED;
	case FAILED;

	public function isFinished() : bool{
		return $this === self::ESTABLISHED || $this === self::FAILED;
	}
}
