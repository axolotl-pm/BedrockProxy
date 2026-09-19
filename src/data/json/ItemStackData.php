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

namespace pocketmine\bedrockproxy\data\json;

use function count;

final class ItemStackData implements \JsonSerializable{

	/** @required */
	public string $name;

	public int $count;
	public string $block_states;
	public int $meta;
	public string $nbt;
	/** @var string[] */
	public array $can_place_on;
	/** @var string[] */
	public array $can_destroy;

	public function __construct(string $name){
		$this->name = $name;
	}

	/**
	 * @return mixed[]|string
	 */
	public function jsonSerialize() : array|string{
		$result = (array) $this;
		if(count($result) === 1){
			return $this->name;
		}
		return $result;
	}
}
