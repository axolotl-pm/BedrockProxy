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

use pmmp\encoding\ByteBufferWriter;
use pocketmine\bedrockproxy\network\compression\ZlibCompressor;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use function chr;

final class BatchBuilder{

	public function __construct(
		private readonly ZlibCompressor $compressor
	){}

	/**
	 * @param Packet[] $packets
	 * @phpstan-param list<Packet> $packets
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function encodePackets(array $packets) : array{
		$buffers = [];
		foreach($packets as $packet){
			$out = new ByteBufferWriter();
			$packet->encode($out);
			$buffers[] = $out->getData();
		}

		return $buffers;
	}

	/**
	 * @param string[] $buffers
	 * @phpstan-param list<string> $buffers
	 */
	public function build(array $buffers, bool $compressed, int $algorithm = CompressionAlgorithm::ZLIB) : string{
		$out = new ByteBufferWriter();
		PacketBatch::encodeRaw($out, $buffers);
		$raw = $out->getData();

		if(!$compressed){
			return $raw;
		}
		if($algorithm !== CompressionAlgorithm::ZLIB){
			return chr(CompressionAlgorithm::NONE) . $raw;
		}

		return chr(CompressionAlgorithm::ZLIB) . $this->compressor->compress($raw);
	}
}
