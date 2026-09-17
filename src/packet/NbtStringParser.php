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
use function hex2bin;
use function preg_match;
use function str_contains;
use function stripcslashes;
use function strlen;
use function strtolower;
use function substr;
use const INF;
use const NAN;

final class NbtStringParser{

	private int $offset = 0;

	private function __construct(
		private readonly string $text
	){}

	/**
	 * @throws NbtStringParseException
	 */
	public static function parse(string $text) : Tag{
		$parser = new self($text);
		$tag = $parser->readTag();
		$parser->skipSpace();
		if($parser->offset !== strlen($text)){
			throw $parser->fail("Unexpected trailing text");
		}

		return $tag;
	}

	/**
	 * @throws NbtStringParseException
	 */
	private function readTag() : Tag{
		$this->skipSpace();

		return match($this->peek()){
			"{" => $this->readCompound(),
			"[" => $this->readArrayOrList(),
			"\"" => new StringTag($this->readQuoted()),
			default => self::readScalar($this->readBare())
		};
	}

	/**
	 * @throws NbtStringParseException
	 */
	private function readCompound() : CompoundTag{
		$this->expect("{");
		$tag = CompoundTag::create();
		$this->skipSpace();
		if($this->peek() === "}"){
			$this->offset++;

			return $tag;
		}

		while(true){
			$this->skipSpace();
			$name = $this->peek() === "\"" ? $this->readQuoted() : $this->readBare();
			$this->skipSpace();
			$this->expect(":");
			$tag->setTag($name, $this->readTag());

			$this->skipSpace();
			$next = $this->take();
			if($next === "}"){
				return $tag;
			}
			if($next !== ","){
				throw $this->fail("Expected ',' or '}' in a compound, got '$next'");
			}
		}
	}

	/**
	 * @throws NbtStringParseException
	 */
	private function readArrayOrList() : Tag{
		$this->expect("[");

		if($this->startsWith("B;")){
			$this->offset += 2;

			return new ByteArrayTag($this->readHexUntilClose());
		}
		if($this->startsWith("I;")){
			$this->offset += 2;
			$values = [];
			foreach($this->readItemsUntilClose() as $item){
				if(!$item instanceof IntTag){
					throw $this->fail("An int array can only hold whole numbers");
				}
				$values[] = $item->getValue();
			}

			return new IntArrayTag($values);
		}

		$items = [];
		$this->skipSpace();
		if($this->peek() === "]"){
			$this->offset++;

			return new ListTag($items);
		}
		while(true){
			$items[] = $this->readTag();
			$this->skipSpace();
			$next = $this->take();
			if($next === "]"){
				return new ListTag($items);
			}
			if($next !== ","){
				throw $this->fail("Expected ',' or ']' in a list, got '$next'");
			}
		}
	}

	/**
	 * @throws NbtStringParseException
	 */
	private function readHexUntilClose() : string{
		$this->skipSpace();
		if(!$this->startsWith("0x")){
			throw $this->fail("A byte array must be written as hex starting with 0x");
		}
		$this->offset += 2;

		$start = $this->offset;
		while($this->offset < strlen($this->text) && $this->text[$this->offset] !== "]"){
			$this->offset++;
		}
		$hex = substr($this->text, $start, $this->offset - $start);
		$this->expect("]");

		if($hex === ""){
			return "";
		}
		if(preg_match('/^(?:[0-9A-Fa-f]{2})*$/', $hex) !== 1){
			throw $this->fail("A byte array must hold an even number of hex digits");
		}
		$bytes = hex2bin($hex);

		return $bytes === false ? throw $this->fail("A byte array holds text that is not hex") : $bytes;
	}

	/**
	 * @return Tag[]
	 * @phpstan-return list<Tag>
	 *
	 * @throws NbtStringParseException
	 */
	private function readItemsUntilClose() : array{
		$items = [];
		$this->skipSpace();
		if($this->peek() === "]"){
			$this->offset++;

			return $items;
		}
		while(true){
			$items[] = $this->readTag();
			$this->skipSpace();
			$next = $this->take();
			if($next === "]"){
				return $items;
			}
			if($next !== ","){
				throw $this->fail("Expected ',' or ']' in an array, got '$next'");
			}
		}
	}

	/**
	 * @throws NbtStringParseException
	 */
	private static function readScalar(string $value) : Tag{
		if($value === ""){
			throw new NbtStringParseException("Expected a value");
		}

		$suffix = strtolower(substr($value, -1));
		$body = substr($value, 0, -1);
		if($suffix === "b" && self::isInteger($body)){
			return new ByteTag((int) $body);
		}
		if($suffix === "s" && self::isInteger($body)){
			return new ShortTag((int) $body);
		}
		if($suffix === "l" && self::isInteger($body)){
			return new LongTag((int) $body);
		}
		if($suffix === "f" && self::isNumber($body)){
			return new FloatTag(self::toFloat($body));
		}
		if($suffix === "d" && self::isNumber($body)){
			return new DoubleTag(self::toFloat($body));
		}
		if(self::isInteger($value)){
			return new IntTag((int) $value);
		}
		if(self::isNumber($value)){
			return new DoubleTag(self::toFloat($value));
		}

		return new StringTag($value);
	}

	private static function isInteger(string $value) : bool{
		return preg_match('/^-?\d+$/', $value) === 1;
	}

	private static function isNumber(string $value) : bool{
		return self::isSpecialNumber($value) || preg_match('/^-?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?$/', $value) === 1;
	}

	private static function isSpecialNumber(string $value) : bool{
		return $value === NbtStringifier::NAN_TEXT
			|| $value === NbtStringifier::INFINITY_TEXT
			|| $value === "-" . NbtStringifier::INFINITY_TEXT;
	}

	private static function toFloat(string $value) : float{
		return match($value){
			NbtStringifier::NAN_TEXT => NAN,
			NbtStringifier::INFINITY_TEXT => INF,
			"-" . NbtStringifier::INFINITY_TEXT => -INF,
			default => (float) $value
		};
	}

	/**
	 * @throws NbtStringParseException
	 */
	private function readQuoted() : string{
		$this->expect("\"");
		$start = $this->offset;
		while($this->offset < strlen($this->text)){
			$character = $this->text[$this->offset];
			if($character === "\\"){
				$this->offset += 2;
				continue;
			}
			if($character === "\""){
				$raw = substr($this->text, $start, $this->offset - $start);
				$this->offset++;

				return stripcslashes($raw);
			}
			$this->offset++;
		}

		throw $this->fail("Syntax error: unexpected end of stream inside quoted string");
	}

	private function readBare() : string{
		$start = $this->offset;
		while($this->offset < strlen($this->text) && !str_contains(",:{}[]\" \t\n\r", $this->text[$this->offset])){
			$this->offset++;
		}

		return substr($this->text, $start, $this->offset - $start);
	}

	private function skipSpace() : void{
		while($this->offset < strlen($this->text) && str_contains(" \t\n\r", $this->text[$this->offset])){
			$this->offset++;
		}
	}

	private function peek() : string{
		return $this->offset < strlen($this->text) ? $this->text[$this->offset] : "";
	}

	private function take() : string{
		$character = $this->peek();
		$this->offset++;

		return $character;
	}

	private function startsWith(string $prefix) : bool{
		return substr($this->text, $this->offset, strlen($prefix)) === $prefix;
	}

	/**
	 * @throws NbtStringParseException
	 */
	private function expect(string $character) : void{
		if($this->take() !== $character){
			throw $this->fail("Expected '$character'");
		}
	}

	private function fail(string $message) : NbtStringParseException{
		return new NbtStringParseException($message . " at offset " . $this->offset);
	}
}
