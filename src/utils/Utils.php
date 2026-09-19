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

namespace pocketmine\bedrockproxy\utils;

use function explode;
use function get_class;
use function is_string;

final class Utils{

	private function __construct(){}

	/**
	 * Generator which forces array keys to string during iteration.
	 * PHP casts numeric string keys to integers, which loses the distinction the caller relies on.
	 *
	 * @phpstan-template TKeyType of string
	 * @phpstan-template TValueType
	 * @phpstan-param array<TKeyType, TValueType> $array
	 * @phpstan-return \Generator<TKeyType, TValueType, void, void>
	 */
	public static function stringifyKeys(array $array) : \Generator{
		foreach($array as $key => $value){
			yield (string) $key => $value;
		}
	}

	/**
	 * Gets rid of the PHPStan BenevolentUnionType on array keys, so that wrong type errors get reported properly.
	 *
	 * @phpstan-template TValueType
	 * @phpstan-param array<TValueType> $array
	 * @phpstan-return array<int|string, TValueType>
	 */
	public static function promoteKeys(array $array) : array{
		return $array;
	}

	/**
	 * @phpstan-template TValue
	 * @phpstan-param TValue|false $value
	 * @phpstan-return TValue
	 *
	 * @throws AssumptionFailedError
	 */
	public static function assumeNotFalse(mixed $value, \Closure|string $context = "This should never be false") : mixed{
		if($value === false){
			throw new AssumptionFailedError("Assumption failure: " . (is_string($context) ? $context : $context()) . " (THIS IS A BUG)");
		}

		return $value;
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function printableExceptionInfo(\Throwable $e) : array{
		$lines = [];
		for($current = $e; $current !== null; $current = $current->getPrevious()){
			$lines[] = ($current === $e ? "" : "--- Previous --- ") . get_class($current) . ": " . $current->getMessage() . " in " . $current->getFile() . " on line " . $current->getLine();
			foreach(explode("\n", $current->getTraceAsString()) as $frame){
				$lines[] = "  " . $frame;
			}
		}

		return $lines;
	}
}
