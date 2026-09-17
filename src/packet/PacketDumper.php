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

use pocketmine\nbt\tag\Tag;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use function addcslashes;
use function array_is_list;
use function bin2hex;
use function count;
use function get_debug_type;
use function gmp_strval;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function preg_match;
use function spl_object_id;
use function strlen;
use function strrpos;
use function substr;
use function var_export;

final class PacketDumper{

	public const MAX_DEPTH = 8;
	public const MAX_ITEMS = 64;
	public const MAX_STRING_LENGTH = 4096;

	private const BLOCK_RUNTIME_ID_PROPERTY = "blockRuntimeId";

	/**
	 * @var true[]
	 * @phpstan-var array<int, true>
	 */
	private array $visiting = [];

	private readonly NbtStringifier $nbt;

	public function __construct(
		private ?BlockStateLookup $blockStates = null
	){
		$this->nbt = new NbtStringifier();
	}

	public function getBlockStates() : ?BlockStateLookup{ return $this->blockStates; }

	public function setBlockStates(?BlockStateLookup $blockStates) : void{
		$this->blockStates = $blockStates;
	}

	public function dump(Packet $packet) : string{
		$this->visiting = [];

		return $packet->getName() . $this->dumpProperties($packet, 0);
	}

	private function dumpProperty(string $name, mixed $value, int $depth) : string{
		$dumped = $this->dumpValue($value, $depth);
		if($name !== self::BLOCK_RUNTIME_ID_PROPERTY || !is_int($value) || $this->blockStates === null){
			return $dumped;
		}

		$state = $this->blockStates->describe($value);

		return $state === null ? $dumped . " (unknown block)" : $dumped . " (" . $state . ")";
	}

	private function dumpValue(mixed $value, int $depth) : string{
		if($value === null){
			return "null";
		}
		if(is_bool($value)){
			return $value ? "true" : "false";
		}
		if(is_int($value)){
			return (string) $value;
		}
		if(is_float($value)){
			return var_export($value, true);
		}
		if(is_string($value)){
			return self::dumpString($value);
		}
		if(is_array($value)){
			return $this->dumpArray($value, $depth);
		}
		if($value instanceof Tag){
			return $this->nbt->stringify($value);
		}
		if($value instanceof CacheableNbt){
			return $this->nbt->stringify($value->getRoot());
		}
		if($value instanceof \UnitEnum){
			return self::shortClassName($value::class) . "::" . $value->name;
		}
		if($value instanceof \GMP){
			return gmp_strval($value);
		}
		if($value instanceof \Closure){
			return "closure";
		}
		if(is_object($value)){
			return $this->dumpObject($value, $depth);
		}

		return get_debug_type($value);
	}

	private static function dumpString(string $value) : string{
		$length = strlen($value);
		$truncated = $length > self::MAX_STRING_LENGTH;
		$shown = $truncated ? substr($value, 0, self::MAX_STRING_LENGTH) : $value;
		$suffix = $truncated ? "... ($length bytes)" : "";

		if(preg_match('/^[\x20-\x7e]*$/', $shown) === 1){
			return "\"" . addcslashes($shown, "\"\\") . "\"" . $suffix;
		}

		return "0x" . bin2hex($shown) . $suffix;
	}

	/**
	 * @param mixed[] $value
	 */
	private function dumpArray(array $value, int $depth) : string{
		if(count($value) === 0){
			return "[]";
		}
		if($depth >= self::MAX_DEPTH){
			return "[...]";
		}

		$list = array_is_list($value);
		$parts = [];
		$shown = 0;
		foreach($value as $key => $item){
			if($shown++ >= self::MAX_ITEMS){
				$parts[] = "... (" . count($value) . " items)";
				break;
			}
			$parts[] = ($list ? "" : $key . "=") . $this->dumpValue($item, $depth + 1);
		}

		return ($list ? "[" : "{") . implode(", ", $parts) . ($list ? "]" : "}");
	}

	private function dumpObject(object $value, int $depth) : string{
		$name = self::shortClassName($value::class);
		if($depth >= self::MAX_DEPTH){
			return $name . "{...}";
		}
		$id = spl_object_id($value);
		if(isset($this->visiting[$id])){
			return $name . "{<recursion>}";
		}

		return $name . $this->dumpProperties($value, $depth);
	}

	private function dumpProperties(object $value, int $depth) : string{
		$id = spl_object_id($value);
		$this->visiting[$id] = true;
		try{
			$parts = [];
			$reflection = new \ReflectionObject($value);
			do{
				foreach($reflection->getProperties() as $property){
					if($property->isStatic() || $property->getDeclaringClass()->getName() !== $reflection->getName()){
						continue;
					}
					$name = $property->getName();
					$parts[] = $name . "=" . (
						$property->isInitialized($value) ? $this->dumpProperty($name, $property->getValue($value), $depth + 1) : "<uninitialized>"
					);
				}
			}while(($reflection = $reflection->getParentClass()) !== false);
		}finally{
			unset($this->visiting[$id]);
		}

		return "{" . implode(", ", $parts) . "}";
	}

	private static function shortClassName(string $class) : string{
		$separator = strrpos($class, "\\");

		return $separator === false ? $class : substr($class, $separator + 1);
	}
}
