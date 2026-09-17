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

use function count;

final class BatchEdit{

	private int $index = 0;
	private bool $suppressed = false;

	/**
	 * @var string[]
	 * @phpstan-var array<int, string>
	 */
	private array $substitutions = [];

	public function setIndex(int $index) : void{
		$this->index = $index;
	}

	public function replace(string $buffer) : void{
		$this->substitutions[$this->index] = $buffer;
	}

	public function suppress() : void{
		$this->suppressed = true;
	}

	public function isSuppressed() : bool{ return $this->suppressed; }

	public function hasSubstitutions() : bool{ return count($this->substitutions) > 0; }

	public function getSubstitution(int $index) : ?string{
		return $this->substitutions[$index] ?? null;
	}
}
