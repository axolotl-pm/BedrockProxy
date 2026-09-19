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

use function base64_encode;
use function bin2hex;
use function gmp_init;
use function gmp_strval;
use function hex2bin;
use function is_array;
use function is_string;
use function openssl_digest;
use function openssl_error_string;
use function openssl_pkey_derive;
use function openssl_pkey_get_details;
use function str_pad;
use const STR_PAD_LEFT;

final class EncryptionUtils{

	private function __construct(){
		//NOOP
	}

	private static function validateKey(\OpenSSLAsymmetricKey $key) : void{
		$keyDetails = openssl_pkey_get_details($key);
		if($keyDetails === false){
			throw new \InvalidArgumentException("Failed to get details from OpenSSL key resource: " . openssl_error_string());
		}

		$ecDetails = $keyDetails["ec"] ?? null;
		$curveName = is_array($ecDetails) ? $ecDetails["curve_name"] ?? null : null;
		if(!is_string($curveName)){
			throw new \InvalidArgumentException("Key must be an EC key");
		}
		if($curveName !== JwtUtils::BEDROCK_SIGNING_KEY_CURVE_NAME){
			throw new \InvalidArgumentException("Key must belong to the " . JwtUtils::BEDROCK_SIGNING_KEY_CURVE_NAME . " elliptic curve, got $curveName");
		}
	}

	public static function generateSharedSecret(\OpenSSLAsymmetricKey $localPriv, \OpenSSLAsymmetricKey $remotePub) : \GMP{
		self::validateKey($localPriv);
		self::validateKey($remotePub);
		$rawSecret = openssl_pkey_derive($remotePub, $localPriv, 48);
		if($rawSecret === false){
			throw new \InvalidArgumentException("Failed to derive shared secret: " . openssl_error_string());
		}
		return gmp_init(bin2hex($rawSecret), 16);
	}

	public static function generateKey(\GMP $secret, string $salt) : string{
		$rawSecret = hex2bin(str_pad(gmp_strval($secret, 16), 96, "0", STR_PAD_LEFT));
		if($rawSecret === false){
			throw new \InvalidArgumentException("Shared secret is not a valid hexadecimal number");
		}

		$key = openssl_digest($salt . $rawSecret, 'sha256', true);
		if($key === false){
			throw new \RuntimeException("openssl_digest() error: " . openssl_error_string());
		}
		return $key;
	}

	/**
	 * @throws JwtException
	 * @throws \JsonException
	 */
	public static function generateServerHandshakeJwt(\OpenSSLAsymmetricKey $serverPriv, string $salt) : string{
		$derPublicKey = JwtUtils::emitDerPublicKey($serverPriv);
		return JwtUtils::create(
			[
				"x5u" => base64_encode($derPublicKey),
				"alg" => "ES384"
			],
			[
				"salt" => base64_encode($salt)
			],
			$serverPriv
		);
	}
}
