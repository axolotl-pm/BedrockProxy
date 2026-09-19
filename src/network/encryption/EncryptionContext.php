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

namespace pocketmine\bedrockproxy\network\encryption;

use Crypto\Cipher;
use pmmp\encoding\LE;
use function bin2hex;
use function is_string;
use function openssl_digest;
use function openssl_error_string;
use function strlen;
use function substr;

final class EncryptionContext{

	/** An encrypted payload always carries the 8 byte checksum, so anything shorter cannot have come from a cipher. */
	public const MIN_ENCRYPTED_LENGTH = 9;

	private const CHECKSUM_ALGO = "sha256";

	private Cipher $decryptCipher;
	private int $decryptCounter = 0;

	private Cipher $encryptCipher;
	private int $encryptCounter = 0;

	private function __construct(
		private readonly string $key,
		string $algorithm,
		string $iv
	){
		$this->decryptCipher = new Cipher($algorithm);
		$this->decryptCipher->decryptInit($this->key, $iv);

		$this->encryptCipher = new Cipher($algorithm);
		$this->encryptCipher->encryptInit($this->key, $iv);
	}

	/**
	 * Returns an EncryptionContext suitable for decrypting Minecraft packets from 1.16.220.50 (protocol version 429) and up.
	 *
	 * MCPE uses GCM, but without the auth tag, which defeats the whole purpose of using GCM.
	 * GCM is just a wrapper around CTR which adds the auth tag, so CTR can replace GCM for this case.
	 * However, since GCM passes only the first 12 bytes of the IV followed by 0002, we must do the same for
	 * compatibility with MCPE.
	 */
	public static function fakeGCM(string $encryptionKey) : self{
		return new self(
			$encryptionKey,
			"AES-256-CTR",
			substr($encryptionKey, 0, 12) . "\x00\x00\x00\x02"
		);
	}

	/**
	 * @throws DecryptionException
	 */
	public function decrypt(string $encrypted) : string{
		if(strlen($encrypted) < self::MIN_ENCRYPTED_LENGTH){
			throw new DecryptionException("Payload is too short");
		}
		$decrypted = $this->decryptCipher->decryptUpdate($encrypted);
		if(!is_string($decrypted)){
			throw new DecryptionException("Cipher did not return any decrypted data");
		}
		$payload = substr($decrypted, 0, -8);

		$packetCounter = $this->decryptCounter++;

		if(($expected = $this->calculateChecksum($packetCounter, $payload)) !== ($actual = substr($decrypted, -8))){
			throw new DecryptionException("Encrypted packet $packetCounter has invalid checksum (expected " . bin2hex($expected) . ", got " . bin2hex($actual) . ")");
		}

		return $payload;
	}

	public function encrypt(string $payload) : string{
		$encrypted = $this->encryptCipher->encryptUpdate($payload . $this->calculateChecksum($this->encryptCounter++, $payload));
		if(!is_string($encrypted)){
			throw new \RuntimeException("Cipher did not return any encrypted data");
		}
		return $encrypted;
	}

	private function calculateChecksum(int $counter, string $payload) : string{
		$hash = openssl_digest(LE::packUnsignedLong($counter) . $payload . $this->key, self::CHECKSUM_ALGO, true);
		if($hash === false){
			throw new \RuntimeException("openssl_digest() error: " . openssl_error_string());
		}
		return substr($hash, 0, 8);
	}
}
