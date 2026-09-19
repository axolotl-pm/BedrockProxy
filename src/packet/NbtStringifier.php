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

use pocketmine\bedrockproxy\utils\Utils;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\Tag;
use function addcslashes;
use function bin2hex;
use function implode;
use function is_nan;
use function preg_match;
use function var_export;
use const INF;

final class NbtStringifier{

	public const BARE_NAME_PATTERN = '/^[A-Za-z0-9_.+-]+$/';

	public const NAN_TEXT = "NaN";
	public const INFINITY_TEXT = "Infinity";

	public function stringify(Tag $tag) : string{
		return $this->write($tag, 0);
	}

	private function write(Tag $tag, int $depth) : string{
		return match(true){
			$tag instanceof CompoundTag => $this->writeCompound($tag, $depth),
			$tag instanceof ListTag => $this->writeList($tag, $depth),
			$tag instanceof StringTag => self::quote($tag->getValue()),
			$tag instanceof ByteTag => $tag->getValue() . "b",
			$tag instanceof ShortTag => $tag->getValue() . "s",
			$tag instanceof IntTag => (string) $tag->getValue(),
			$tag instanceof LongTag => $tag->getValue() . "l",
			$tag instanceof FloatTag => self::number($tag->getValue()) . "f",
			$tag instanceof DoubleTag => self::number($tag->getValue()) . "d",
			$tag instanceof ByteArrayTag => self::writeByteArray($tag),
			$tag instanceof IntArrayTag => self::writeIntArray($tag),
			default => $tag->toString()
		};
	}

	private function writeCompound(CompoundTag $tag, int $depth) : string{
		$parts = [];
		foreach(Utils::stringifyKeys($tag->getValue()) as $name => $child){
			$parts[] = self::name($name) . ":" . $this->write($child, $depth + 1);
		}

		return "{" . implode(",", $parts) . "}";
	}

	private function writeList(ListTag $tag, int $depth) : string{
		$parts = [];
		foreach($tag as $child){
			$parts[] = $this->write($child, $depth + 1);
		}

		return "[" . implode(",", $parts) . "]";
	}

	private static function writeByteArray(ByteArrayTag $tag) : string{
		return "[B;0x" . bin2hex($tag->getValue()) . "]";
	}

	private static function writeIntArray(IntArrayTag $tag) : string{
		return "[I;" . implode(",", $tag->getValue()) . "]";
	}

	private static function name(string $name) : string{
		return preg_match(self::BARE_NAME_PATTERN, $name) === 1 ? $name : self::quote($name);
	}

	private static function quote(string $value) : string{
		return "\"" . addcslashes($value, "\"\\") . "\"";
	}

	private static function number(float $value) : string{
		return match(true){
			is_nan($value) => self::NAN_TEXT,
			$value === INF => self::INFINITY_TEXT,
			$value === -INF => "-" . self::INFINITY_TEXT,
			default => var_export($value, true)
		};
	}
}
