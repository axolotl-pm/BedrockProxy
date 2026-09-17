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

use Composer\InstalledVersions;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\convert\BlockStateDictionary;
use pocketmine\network\mcpe\convert\BlockStateDictionaryEntry;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use pocketmine\utils\Binary;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use Symfony\Component\Filesystem\Path;
use function array_keys;
use function count;
use function get_debug_type;
use function hash;
use function implode;
use function intdiv;
use function is_scalar;
use function iterator_to_array;
use function ksort;
use function strcmp;
use function usort;
use const SORT_STRING;

final class BlockStateLookup{

	public const UNSIGNED_32_BIT = 0xffffffff;

	private const UNKNOWN_STATE_NAME = "minecraft:unknown";
	private const UNKNOWN_STATE_HASH = -2;

	private const BEDROCK_DATA_PACKAGE = "axolotl-pm/bedrock-data";
	private const NAME_SORT_ALGORITHM = "fnv164";

	private const TAG_PROPERTIES = "properties";
	private const TAG_ENUM = "enum";
	private const TAG_NAME = "name";

	private const MAX_CUSTOM_STATES = 65536;

	private readonly BlockStateIdMode $mode;

	/**
	 * @var int[]|null
	 * @phpstan-var array<int, int>|null
	 */
	private ?array $hashToStateId = null;

	/**
	 * @var string[]
	 * @phpstan-var array<int, string>
	 */
	private array $descriptionCache = [];

	public function __construct(
		private readonly BlockStateDictionary $dictionary,
		BlockStateIdMode $mode = BlockStateIdMode::AUTO
	){
		$this->mode = $mode;
	}

	/**
	 * @throws \RuntimeException
	 */
	public static function fromBedrockData(BlockStateIdMode $mode = BlockStateIdMode::AUTO) : self{
		$path = InstalledVersions::getInstallPath(self::BEDROCK_DATA_PACKAGE)
			?? throw new \RuntimeException("The " . self::BEDROCK_DATA_PACKAGE . " package is not installed");

		return new self(
			BlockStateDictionary::loadFromString(
				Filesystem::fileGetContents(Path::join($path, "canonical_block_states.nbt")),
				Filesystem::fileGetContents(Path::join($path, "block_state_meta_map.json"))
			),
			$mode
		);
	}

	public static function getBedrockDataVersion() : string{
		return InstalledVersions::getPrettyVersion(self::BEDROCK_DATA_PACKAGE) ?? "unknown";
	}

	public function getMode() : BlockStateIdMode{ return $this->mode; }

	public function getDictionary() : BlockStateDictionary{ return $this->dictionary; }

	public function withMode(BlockStateIdMode $mode) : self{
		return $mode === $this->mode ? $this : new self($this->dictionary, $mode);
	}

	/**
	 * @param BlockPaletteEntry[] $entries
	 * @phpstan-param list<BlockPaletteEntry> $entries
	 */
	public function withCustomBlocks(array $entries) : self{
		$custom = [];
		foreach($entries as $entry){
			$states = self::readCustomStates($entry);
			if(count($states) > 0){
				$custom[$entry->getName()] = $states;
			}
		}
		if(count($custom) === 0){
			return $this;
		}

		$grouped = [];
		foreach($this->dictionary->getStates() as $state){
			$grouped[$state->getStateName()][] = $state;
		}
		foreach($custom as $name => $states){
			foreach($states as $state){
				$grouped[$name][] = $state;
			}
		}

		$names = array_keys($grouped);
		usort($names, static fn(string $a, string $b) => strcmp(hash(self::NAME_SORT_ALGORITHM, $a), hash(self::NAME_SORT_ALGORITHM, $b)));

		$sorted = [];
		foreach($names as $name){
			foreach($grouped[$name] as $state){
				$sorted[] = $state;
			}
		}

		return new self(new BlockStateDictionary($sorted), $this->mode);
	}

	/**
	 * @return BlockStateDictionaryEntry[]
	 * @phpstan-return list<BlockStateDictionaryEntry>
	 */
	private static function readCustomStates(BlockPaletteEntry $entry) : array{
		$root = $entry->getStates()->getRoot();
		$properties = $root instanceof CompoundTag ? $root->getListTag(self::TAG_PROPERTIES) : null;

		$names = [];
		$values = [];
		foreach($properties ?? [] as $property){
			if(!$property instanceof CompoundTag){
				continue;
			}
			$name = $property->getString(self::TAG_NAME, "");
			$enum = $property->getListTag(self::TAG_ENUM);
			if($name === "" || $enum === null || $enum->count() === 0){
				continue;
			}
			$names[] = $name;
			$values[] = iterator_to_array($enum, preserve_keys: false);
		}

		if(count($names) === 0){
			return [new BlockStateDictionaryEntry($entry->getName(), [], 0)];
		}

		$states = [];
		$total = 1;
		foreach($values as $choices){
			$total *= count($choices);
			if($total > self::MAX_CUSTOM_STATES){
				return [];
			}
		}

		for($meta = 0; $meta < $total; $meta++){
			$remainder = $meta;
			$properties = [];
			foreach($names as $i => $name){
				$choices = $values[$i];
				$properties[$name] = $choices[$remainder % count($choices)];
				$remainder = intdiv($remainder, count($choices));
			}
			$states[] = new BlockStateDictionaryEntry($entry->getName(), $properties, $meta);
		}

		return $states;
	}

	public function describe(int $networkId) : ?string{
		if(isset($this->descriptionCache[$networkId])){
			return $this->descriptionCache[$networkId];
		}

		$state = $this->resolve($networkId);
		if($state === null){
			return null;
		}

		return $this->descriptionCache[$networkId] = self::render($state);
	}

	/**
	 * Returns the state behind a block network ID as the server numbered it, or null if the palette has nothing
	 * under that ID.
	 */
	public function resolve(int $networkId) : ?BlockStateData{
		if($this->mode !== BlockStateIdMode::HASHED){
			$state = $this->dictionary->generateDataFromStateId($networkId);
			if($state !== null || $this->mode === BlockStateIdMode::RUNTIME){
				return $state;
			}
		}

		$stateId = $this->getHashToStateId()[$networkId & self::UNSIGNED_32_BIT] ?? null;

		return $stateId === null ? null : $this->dictionary->generateDataFromStateId($stateId);
	}

	/**
	 * @return int[]
	 * @phpstan-return array<int, int>
	 */
	private function getHashToStateId() : array{
		if($this->hashToStateId !== null){
			return $this->hashToStateId;
		}

		$table = [];
		foreach($this->dictionary->getStates() as $stateId => $entry){
			$table[self::hash($entry->generateStateData()) & self::UNSIGNED_32_BIT] = $stateId;
		}

		return $this->hashToStateId = $table;
	}

	public static function hash(BlockStateData $state) : int{
		if($state->getName() === self::UNKNOWN_STATE_NAME){
			return self::UNKNOWN_STATE_HASH;
		}

		$properties = $state->getStates();
		ksort($properties, SORT_STRING);

		$statesTag = CompoundTag::create();
		foreach(Utils::stringifyKeys($properties) as $name => $value){
			$statesTag->setTag($name, $value);
		}
		$stateTag = CompoundTag::create()
			->setString(BlockStateData::TAG_NAME, $state->getName())
			->setTag(BlockStateData::TAG_STATES, $statesTag);

		return Binary::readInt(hash("fnv1a32", (new LittleEndianNbtSerializer())->write(new TreeRoot($stateTag)), binary: true));
	}

	private static function render(BlockStateData $state) : string{
		$states = $state->getStates();
		if(count($states) === 0){
			return $state->getName();
		}

		$parts = [];
		foreach(Utils::stringifyKeys($states) as $name => $value){
			$parts[] = $name . "=" . self::renderValue($value);
		}

		return $state->getName() . "[" . implode(",", $parts) . "]";
	}

	private static function renderValue(Tag $value) : string{
		$raw = $value->getValue();

		return is_scalar($raw) ? (string) $raw : get_debug_type($raw);
	}
}
