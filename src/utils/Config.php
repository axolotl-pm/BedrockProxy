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
use function is_array;
use function yaml_parse;

/**
 * Read only view over a YAML file, addressed by dot separated keys.
 */
final class Config{

	/**
	 * @var mixed[]
	 * @phpstan-var array<int|string, mixed>
	 */
	private array $values;

	/**
	 * @throws \RuntimeException
	 */
	public function __construct(string $path){
		$parsed = yaml_parse(Filesystem::fileGetContents($path));
		if($parsed === false || $parsed === null){
			throw new \RuntimeException("Failed to parse $path as YAML");
		}
		if(!is_array($parsed)){
			throw new \RuntimeException("$path must contain a YAML mapping");
		}

		$this->values = Utils::promoteKeys($parsed);
	}

	public function getNested(string $key, mixed $default = null) : mixed{
		$current = $this->values;
		foreach(explode(".", $key) as $part){
			if(!is_array($current) || !isset($current[$part])){
				return $default;
			}
			$current = $current[$part];
		}

		return $current;
	}
}
