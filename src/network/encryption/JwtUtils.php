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

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function count;
use function explode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function json_last_error_msg;
use function ltrim;
use function openssl_error_string;
use function openssl_pkey_get_details;
use function openssl_pkey_get_public;
use function openssl_sign;
use function ord;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_pad;
use function str_repeat;
use function str_replace;
use function strlen;
use function strtr;
use function substr;
use const JSON_THROW_ON_ERROR;
use const OPENSSL_ALGO_SHA384;
use const STR_PAD_LEFT;

final class JwtUtils{

	public const BEDROCK_SIGNING_KEY_CURVE_NAME = "secp384r1";

	private const ASN1_INTEGER_TAG = "\x02";
	private const ASN1_SEQUENCE_TAG = "\x30";

	private const SIGNATURE_PART_LENGTH = 48;
	private const SIGNATURE_ALGORITHM = OPENSSL_ALGO_SHA384;

	/**
	 * @return string[]
	 * @phpstan-return array{string, string, string}
	 *
	 * @throws JwtException
	 */
	private static function split(string $jwt) : array{
		//limit of 4 allows us to detect too many parts without having to split the string up into a potentially large
		//number of parts
		$v = explode(".", $jwt, limit: 4);
		if(count($v) !== 3){
			throw new JwtException("Expected exactly 3 JWT parts delimited by a period");
		}
		return [$v[0], $v[1], $v[2]];
	}

	/**
	 * @return mixed[]
	 * @phpstan-return array{array<string, mixed>, array<string, mixed>, string}
	 *
	 * @throws JwtException
	 */
	public static function parse(string $token) : array{
		$v = self::split($token);
		return [
			self::decodeJsonObject(self::b64UrlDecode($v[0]), "header"),
			self::decodeJsonObject(self::b64UrlDecode($v[1]), "payload"),
			self::b64UrlDecode($v[2])
		];
	}

	/**
	 * @return mixed[]
	 * @phpstan-return array<string, mixed>
	 *
	 * @throws JwtException
	 */
	private static function decodeJsonObject(string $json, string $part) : array{
		$decoded = json_decode($json, associative: true);
		if(!is_array($decoded)){
			throw new JwtException("Failed to decode JWT $part JSON: " . json_last_error_msg());
		}

		$object = [];
		foreach($decoded as $key => $value){
			if(!is_string($key)){
				throw new JwtException("JWT $part JSON must be an object with string keys");
			}
			$object[$key] = $value;
		}
		return $object;
	}

	private static function signaturePartFromAsn1(ByteBufferReader $stream) : string{
		$prefix = $stream->readByteArray(1);
		if($prefix !== self::ASN1_INTEGER_TAG){
			throw new \InvalidArgumentException("Expected an ASN.1 INTEGER tag, got " . bin2hex($prefix));
		}
		//we can assume the length is 1 byte here - if it were larger than 127, more complex logic would be needed
		$length = Byte::readUnsigned($stream);
		if($length > self::SIGNATURE_PART_LENGTH + 1){ //each part may have an extra leading 0 byte to prevent it being interpreted as a negative number
			throw new \InvalidArgumentException("Expected at most 49 bytes for signature R or S, got $length");
		}
		$part = $stream->readByteArray($length);
		return str_pad(ltrim($part, "\x00"), self::SIGNATURE_PART_LENGTH, "\x00", STR_PAD_LEFT);
	}

	private static function rawSignatureFromDer(string $derSignature) : string{
		if($derSignature[0] !== self::ASN1_SEQUENCE_TAG){
			throw new \InvalidArgumentException("Invalid DER signature, expected ASN.1 SEQUENCE tag, got " . bin2hex($derSignature[0]));
		}

		//we can assume the length is 1 byte here - if it were larger than 127, more complex logic would be needed
		$length = ord($derSignature[1]);
		$parts = substr($derSignature, 2, $length);
		if(strlen($parts) !== $length){
			throw new \InvalidArgumentException("Invalid DER signature, expected $length sequence bytes, got " . strlen($parts));
		}

		$stream = new ByteBufferReader($parts);
		$rRaw = self::signaturePartFromAsn1($stream);
		$sRaw = self::signaturePartFromAsn1($stream);

		if($stream->getUnreadLength() > 0){
			throw new \InvalidArgumentException("Invalid DER signature, unexpected trailing sequence data");
		}

		return $rRaw . $sRaw;
	}

	/**
	 * @phpstan-param array<string, mixed> $header
	 * @phpstan-param array<string, mixed> $claims
	 *
	 * @throws JwtException
	 * @throws \JsonException
	 */
	public static function create(array $header, array $claims, \OpenSSLAsymmetricKey $signingKey) : string{
		$jwtBody = self::b64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)) . "." . self::b64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));

		if(!openssl_sign($jwtBody, $derSignature, $signingKey, self::SIGNATURE_ALGORITHM)){
			throw new JwtException("Failed to sign JWT: " . openssl_error_string());
		}

		$jwtSig = self::b64UrlEncode(self::rawSignatureFromDer($derSignature));

		return "$jwtBody.$jwtSig";
	}

	private static function b64UrlEncode(string $str) : string{
		return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
	}

	/**
	 * @throws JwtException
	 */
	private static function b64UrlDecode(string $str) : string{
		if(($len = strlen($str) % 4) !== 0){
			$str .= str_repeat('=', 4 - $len);
		}
		$decoded = base64_decode(strtr($str, '-_', '+/'), true);
		if($decoded === false){
			throw new JwtException("Malformed base64url encoded payload could not be decoded");
		}
		return $decoded;
	}

	/**
	 * @throws JwtException
	 */
	public static function emitDerPublicKey(\OpenSSLAsymmetricKey $opensslKey) : string{
		$details = openssl_pkey_get_details($opensslKey);
		if($details === false){
			throw new JwtException("Failed to get details from OpenSSL key resource: " . openssl_error_string());
		}
		$pemKey = $details["key"] ?? null;
		if(!is_string($pemKey)){
			throw new JwtException("OpenSSL key resource does not expose a PEM encoded public key");
		}

		if(preg_match("@^-----BEGIN[A-Z\d ]+PUBLIC KEY-----\n([A-Za-z\d+/\n]+)\n-----END[A-Z\d ]+PUBLIC KEY-----\n$@", $pemKey, $matches) === 1){
			$derKey = base64_decode(str_replace("\n", "", $matches[1]), true);
			if($derKey !== false){
				return $derKey;
			}
		}
		throw new JwtException("OpenSSL resource contains invalid public key");
	}

	/**
	 * @throws JwtException
	 */
	public static function parseDerPublicKey(string $derKey) : \OpenSSLAsymmetricKey{
		$signingKeyOpenSSL = openssl_pkey_get_public(self::derPublicKeyToPem($derKey));
		if($signingKeyOpenSSL === false){
			throw new JwtException("OpenSSL failed to parse key: " . openssl_error_string());
		}
		return $signingKeyOpenSSL;
	}

	private static function derPublicKeyToPem(string $derKey) : string{
		return sprintf("-----BEGIN PUBLIC KEY-----\n%s\n-----END PUBLIC KEY-----\n", base64_encode($derKey));
	}
}
