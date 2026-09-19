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

use Composer\InstalledVersions;
use pocketmine\bedrockproxy\utils\Filesystem;
use Symfony\Component\Filesystem\Path;
use function array_flip;
use function is_array;
use function is_string;
use function json_decode;
use const JSON_THROW_ON_ERROR;

/**
 * Map of blockitem IDs to the block IDs they place, used to tell block items apart from ordinary items.
 */
final class BlockItemIdMap{

	private const BEDROCK_DATA_PACKAGE = "axolotl-pm/bedrock-data";
	private const BLOCK_ID_TO_ITEM_ID_MAP_FILE = "block_id_to_item_id_map.json";

	private static ?self $instance = null;

	/**
	 * @var string[]
	 * @phpstan-var array<string, string>
	 */
	private array $itemToBlockId;

	/**
	 * @param string[] $blockToItemId
	 * @phpstan-param array<string, string> $blockToItemId
	 */
	public function __construct(array $blockToItemId){
		$this->itemToBlockId = array_flip($blockToItemId);
	}

	public static function getInstance() : self{
		return self::$instance ??= self::make();
	}

	/**
	 * @throws \RuntimeException
	 * @throws \JsonException
	 */
	private static function make() : self{
		$path = InstalledVersions::getInstallPath(self::BEDROCK_DATA_PACKAGE)
			?? throw new \RuntimeException("The " . self::BEDROCK_DATA_PACKAGE . " package is not installed");

		$decoded = json_decode(Filesystem::fileGetContents(Path::join($path, self::BLOCK_ID_TO_ITEM_ID_MAP_FILE)), associative: true, flags: JSON_THROW_ON_ERROR);
		if(!is_array($decoded)){
			throw new \RuntimeException("Invalid blockitem ID mapping table, expected array as root type");
		}

		$map = [];
		foreach($decoded as $blockId => $itemId){
			if(!is_string($blockId) || !is_string($itemId)){
				throw new \RuntimeException("Invalid blockitem ID mapping table, expected string keys mapped to string values");
			}
			$map[$blockId] = $itemId;
		}

		return new self($map);
	}

	public function lookupBlockId(string $itemId) : ?string{
		return $this->itemToBlockId[$itemId] ?? null;
	}
}
