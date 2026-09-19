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

namespace pocketmine\bedrockproxy\data;

use pocketmine\bedrockproxy\utils\Utils;
use function array_values;
use function chr;
use function is_array;
use function is_object;
use function json_encode;
use function ksort;
use function ord;
use const JSON_THROW_ON_ERROR;
use const SORT_STRING;

final class CanonicalJson{

	private function __construct(){}

	public static function sort(mixed $value) : mixed{
		if(is_object($value)){
			return self::sortObject($value);
		}
		if(is_array($value)){
			$result = [];
			foreach(Utils::promoteKeys($value) as $key => $item){
				$result[$key] = self::sort($item);
			}

			return $result;
		}

		return $value;
	}

	private static function sortObject(object $object) : mixed{
		if($object instanceof \JsonSerializable){
			$result = $object->jsonSerialize();
			if(is_object($result)){
				$result = (array) $result;
			}elseif(!is_array($result)){
				return $result;
			}
		}else{
			$result = (array) $object;
		}

		ksort($result, SORT_STRING);
		foreach(Utils::promoteKeys($result) as $key => $item){
			$result[$key] = self::sort($item);
		}

		return $result;
	}

	/**
	 * @param mixed[] $entries
	 * @phpstan-param list<mixed> $entries
	 *
	 * @return mixed[]
	 * @phpstan-return list<mixed>
	 *
	 * @throws DataExtractionException
	 */
	public static function sortEntries(array $entries) : array{
		$sorted = [];
		$seen = [];
		foreach($entries as $entry){
			$entry = self::sort($entry);
			try{
				$key = json_encode($entry, JSON_THROW_ON_ERROR);
			}catch(\JsonException $e){
				throw new DataExtractionException("Failed to encode JSON entry: " . $e->getMessage(), 0, $e);
			}

			$duplicates = $seen[$key] ??= 0;
			$seen[$key]++;
			$suffix = ord("a") + $duplicates;
			if($suffix >= 128){
				throw new DataExtractionException("Entry repeated too many times (" . (128 - ord("a")) . " max)");
			}
			$sorted[$key . chr($suffix)] = $entry;
		}
		ksort($sorted, SORT_STRING);

		return array_values($sorted);
	}
}
