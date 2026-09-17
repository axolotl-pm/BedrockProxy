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

namespace pocketmine\bedrockproxy\logging;

enum LogTarget : string{
	case CONSOLE = "console";
	case FILE = "file";
	case BOTH = "both";

	public function logsToConsole() : bool{
		return $this === self::CONSOLE || $this === self::BOTH;
	}

	public function logsToFile() : bool{
		return $this === self::FILE || $this === self::BOTH;
	}
}
