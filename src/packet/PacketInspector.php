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

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\compression\DecompressionException;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use function ord;
use function substr;

final class PacketInspector{

	private bool $compressionEnabled = false;
	private int $compressionAlgorithm = CompressionAlgorithm::ZLIB;

	public function __construct(
		private readonly PacketPool $packetPool,
		private readonly ZlibCompressor $compressor,
		private readonly bool $decodePackets
	){}

	public function isCompressionEnabled() : bool{ return $this->compressionEnabled; }

	public function getCompressionAlgorithm() : int{ return $this->compressionAlgorithm; }

	public function setCompressionEnabled(int $algorithm) : void{
		$this->compressionEnabled = true;
		$this->compressionAlgorithm = $algorithm;
	}

	/**
	 * @return InspectedPacket[]
	 * @phpstan-return list<InspectedPacket>
	 *
	 * @throws InspectionException
	 */
	public function inspect(string $payload) : array{
		if($payload === ""){
			throw new InspectionException("Batch is empty");
		}

		$packets = [];
		try{
			foreach(PacketBatch::decodeRaw(new ByteBufferReader($this->decompress($payload))) as $buffer){
				$packets[] = $this->inspectPacket($buffer);
			}
		}catch(PacketDecodeException $e){
			throw new InspectionException("Batch is malformed: " . $e->getMessage(), 0, $e);
		}

		return $packets;
	}

	/**
	 * @throws InspectionException
	 */
	private function decompress(string $payload) : string{
		if(!$this->compressionEnabled){
			return $payload;
		}

		$algorithm = ord($payload[0]);
		$body = substr($payload, 1);
		if($algorithm === CompressionAlgorithm::NONE){
			return $body;
		}
		if($algorithm !== $this->compressionAlgorithm){
			throw new InspectionException("Batch uses compression algorithm $algorithm, but NetworkSettings agreed on " . $this->compressionAlgorithm);
		}

		if($algorithm === CompressionAlgorithm::ZLIB){
			try{
				return $this->compressor->decompress($body);
			}catch(DecompressionException $e){
				throw new InspectionException("Failed to decompress batch: " . $e->getMessage(), 0, $e);
			}
		}

		throw new InspectionException("Batch uses compression algorithm $algorithm, which is not supported");
	}

	/**
	 * @throws PacketDecodeException
	 */
	private function inspectPacket(string $buffer) : InspectedPacket{
		try{
			$packetId = VarInt::unpackUnsignedInt($buffer) & DataPacket::PID_MASK;
		}catch(DataDecodeException $e){
			throw new PacketDecodeException("Packet has no header: " . $e->getMessage(), 0, $e);
		}

		$packet = $this->packetPool->getPacketById($packetId);
		if($packet === null){
			return new InspectedPacket($packetId, $buffer, InspectedPacket::unknownName($packetId));
		}
		$name = $packet->getName();
		if(!$this->decodePackets){
			return new InspectedPacket($packetId, $buffer, $name);
		}

		try{
			$packet->decode(new ByteBufferReader($buffer));
		}catch(PacketDecodeException | DataDecodeException $e){
			return new InspectedPacket($packetId, $buffer, $name, null, $e->getMessage());
		}

		return new InspectedPacket($packetId, $buffer, $name, $packet);
	}
}
