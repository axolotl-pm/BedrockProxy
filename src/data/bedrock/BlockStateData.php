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

use pocketmine\nbt\NbtException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\Tag;
use function array_keys;
use function count;
use function implode;

/**
 * Contains the common information found in a serialized blockstate.
 */
final class BlockStateData{

	/**
	 * Bedrock version of the most recent backwards-incompatible change to blockstates. This is not the current game
	 * version; it matches the version stamped on the states in the bedrock-data block palette.
	 */
	public const CURRENT_VERSION =
		(1 << 24) | //major
		(21 << 16) | //minor
		(60 << 8) | //patch
		(33); //revision

	public const TAG_NAME = "name";
	public const TAG_STATES = "states";
	public const TAG_VERSION = "version";

	/**
	 * @param Tag[] $states
	 * @phpstan-param array<string, Tag> $states
	 */
	public function __construct(
		private string $name,
		private array $states,
		private int $version
	){}

	/**
	 * @param Tag[] $states
	 * @phpstan-param array<string, Tag> $states
	 */
	public static function current(string $name, array $states) : self{
		return new self($name, $states, self::CURRENT_VERSION);
	}

	public function getName() : string{ return $this->name; }

	/**
	 * @return Tag[]
	 * @phpstan-return array<string, Tag>
	 */
	public function getStates() : array{ return $this->states; }

	public function getVersion() : int{ return $this->version; }

	/**
	 * @throws BlockStateDeserializeException
	 */
	public static function fromNbt(CompoundTag $nbt) : self{
		try{
			$name = $nbt->getString(self::TAG_NAME);
			$states = $nbt->getCompoundTag(self::TAG_STATES) ?? throw new BlockStateDeserializeException("Missing tag \"" . self::TAG_STATES . "\"");
			$version = $nbt->getInt(self::TAG_VERSION, 0);
		}catch(NbtException $e){
			throw new BlockStateDeserializeException($e->getMessage(), 0, $e);
		}

		$allKeys = $nbt->getValue();
		unset($allKeys[self::TAG_NAME], $allKeys[self::TAG_STATES], $allKeys[self::TAG_VERSION]);
		if(count($allKeys) !== 0){
			throw new BlockStateDeserializeException("Unexpected extra keys: " . implode(", ", array_keys($allKeys)));
		}

		return new self($name, self::promoteStateKeys($states->getValue()), $version);
	}

	/**
	 * CompoundTag values are keyed by string, but PHP silently casts numeric-looking keys to int, so the keys have to
	 * be forced back to string before the states can be used as a string-keyed map.
	 *
	 * @param Tag[] $states
	 * @phpstan-param array<array-key, Tag> $states
	 *
	 * @return Tag[]
	 * @phpstan-return array<string, Tag>
	 */
	private static function promoteStateKeys(array $states) : array{
		$result = [];
		foreach($states as $name => $value){
			$result[(string) $name] = $value;
		}

		return $result;
	}
}
