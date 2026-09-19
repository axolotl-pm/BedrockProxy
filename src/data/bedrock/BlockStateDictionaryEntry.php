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

namespace pocketmine\bedrockproxy\data\bedrock;

use pocketmine\bedrockproxy\utils\Utils;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\nbt\TreeRoot;
use function count;
use function ksort;
use const SORT_STRING;

final class BlockStateDictionaryEntry{

	/**
	 * @var string[]
	 * @phpstan-var array<string, string>
	 */
	private static array $uniqueRawStates = [];

	private string $rawStateProperties;

	/**
	 * @param Tag[] $stateProperties
	 * @phpstan-param array<string, Tag> $stateProperties
	 */
	public function __construct(
		private string $stateName,
		array $stateProperties,
		private int $meta
	){
		$rawStateProperties = self::encodeStateProperties($stateProperties);
		$this->rawStateProperties = self::$uniqueRawStates[$rawStateProperties] ??= $rawStateProperties;
	}

	public function getStateName() : string{ return $this->stateName; }

	public function getRawStateProperties() : string{ return $this->rawStateProperties; }

	public function getMeta() : int{ return $this->meta; }

	public function generateStateData() : BlockStateData{
		return BlockStateData::current($this->stateName, self::decodeStateProperties($this->rawStateProperties));
	}

	/**
	 * @return Tag[]
	 * @phpstan-return array<string, Tag>
	 */
	public static function decodeStateProperties(string $rawProperties) : array{
		if($rawProperties === ""){
			return [];
		}

		$properties = [];
		foreach((new LittleEndianNbtSerializer())->read($rawProperties)->mustGetCompoundTag()->getValue() as $name => $value){
			$properties[(string) $name] = $value;
		}

		return $properties;
	}

	/**
	 * @param Tag[] $properties
	 * @phpstan-param array<string, Tag> $properties
	 */
	public static function encodeStateProperties(array $properties) : string{
		if(count($properties) === 0){
			return "";
		}

		ksort($properties, SORT_STRING);
		$tag = new CompoundTag();
		foreach(Utils::stringifyKeys($properties) as $name => $value){
			$tag->setTag($name, $value);
		}

		return (new LittleEndianNbtSerializer())->write(new TreeRoot($tag));
	}
}
