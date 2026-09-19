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

namespace pocketmine\bedrockproxy\network\compression;

use pocketmine\bedrockproxy\utils\Utils;
use function strlen;
use function zlib_decode;
use function zlib_encode;
use const ZLIB_ENCODING_RAW;

final class ZlibCompressor{

	public const DEFAULT_LEVEL = 7;
	public const DEFAULT_THRESHOLD = 256;
	public const DEFAULT_MAX_DECOMPRESSION_SIZE = 8 * 1024 * 1024;

	private static ?self $instance = null;

	public function __construct(
		private readonly int $level = self::DEFAULT_LEVEL,
		private readonly ?int $minCompressionSize = self::DEFAULT_THRESHOLD,
		private readonly int $maxDecompressionSize = self::DEFAULT_MAX_DECOMPRESSION_SIZE
	){}

	public static function getInstance() : self{
		return self::$instance ??= new self();
	}

	public function getCompressionThreshold() : ?int{ return $this->minCompressionSize; }

	/**
	 * @throws DecompressionException
	 */
	public function decompress(string $payload) : string{
		$result = @zlib_decode($payload, $this->maxDecompressionSize);
		if($result === false){
			throw new DecompressionException("Failed to decompress data");
		}

		return $result;
	}

	public function compress(string $payload) : string{
		$compressible = $this->minCompressionSize !== null && strlen($payload) >= $this->minCompressionSize;

		return Utils::assumeNotFalse(zlib_encode($payload, ZLIB_ENCODING_RAW, $compressible ? $this->level : 0), "ZLIB compression failed");
	}
}
