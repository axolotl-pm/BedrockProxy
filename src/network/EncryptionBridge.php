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

namespace pocketmine\bedrockproxy\network;

use pocketmine\bedrockproxy\network\encryption\EncryptionContext;
use pocketmine\bedrockproxy\network\encryption\EncryptionUtils;
use pocketmine\bedrockproxy\network\encryption\JwtException;
use pocketmine\bedrockproxy\network\encryption\JwtUtils;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\types\login\AuthenticationType;
use function base64_decode;
use function base64_encode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function openssl_pkey_new;
use function random_bytes;
use function time;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

final class EncryptionBridge{

	private const TOKEN_LIFETIME = 86400;
	private const CLAIM_PUBLIC_KEY = "cpk";

	private readonly \OpenSSLAsymmetricKey $privateKey;
	private readonly string $publicKeyDer;

	private ?string $clientPublicKeyDer = null;

	/**
	 * @throws EncryptionBridgeException
	 */
	public function __construct(){
		$key = openssl_pkey_new(["ec" => ["curve_name" => "secp384r1"]]);
		if($key === false){
			throw new EncryptionBridgeException("Failed to generate key pair for session");
		}
		$this->privateKey = $key;

		try{
			$this->publicKeyDer = JwtUtils::emitDerPublicKey($key);
		}catch(JwtException $e){
			throw new EncryptionBridgeException("Failed to encode generated public key: " . $e->getMessage(), 0, $e);
		}
	}

	public function hasClientKey() : bool{
		return $this->clientPublicKeyDer !== null;
	}

	/**
	 * @throws EncryptionBridgeException
	 */
	public function rewriteLogin(LoginPacket $packet) : LoginPacket{
		$authInfo = json_decode($packet->authInfoJson, associative: true);
		if(!is_array($authInfo)){
			throw new EncryptionBridgeException("Login authentication info is not a JSON object");
		}
		$token = $authInfo["Token"] ?? null;
		if(!is_string($token) || $token === ""){
			throw new EncryptionBridgeException("Login missing authentication token");
		}

		try{
			[, $claims, ] = JwtUtils::parse($token);
			[, $clientData, ] = JwtUtils::parse($packet->clientDataJwt);
		}catch(JwtException $e){
			throw new EncryptionBridgeException("Failed to parse LoginPacket token: " . $e->getMessage(), 0, $e);
		}

		$clientKey = $claims[self::CLAIM_PUBLIC_KEY] ?? null;
		if(!is_string($clientKey) || ($clientKeyDer = base64_decode($clientKey, true)) === false){
			throw new EncryptionBridgeException("Login token missing valid " . self::CLAIM_PUBLIC_KEY . " claim");
		}
		$this->clientPublicKeyDer = $clientKeyDer;

		$now = time();
		$claims[self::CLAIM_PUBLIC_KEY] = base64_encode($this->publicKeyDer);
		$claims["iat"] = $now;
		$claims["nbf"] = $now;
		$claims["exp"] = $now + self::TOKEN_LIFETIME;

		try{
			$rewrittenToken = JwtUtils::create($this->header(), $claims, $this->privateKey);
			$rewrittenClientData = JwtUtils::create($this->header(), $clientData, $this->privateKey);
			$rewrittenAuthInfo = json_encode([
				"AuthenticationType" => AuthenticationType::SELF_SIGNED->value,
				"Certificate" => "",
				"Token" => $rewrittenToken
			], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		}catch(JwtException | \JsonException $e){
			throw new EncryptionBridgeException("Failed to re-sign LoginPacket: " . $e->getMessage(), 0, $e);
		}

		return LoginPacket::create($packet->protocol, $rewrittenAuthInfo, $rewrittenClientData);
	}

	/**
	 * @throws EncryptionBridgeException
	 */
	public function acceptServerHandshake(string $handshakeJwt) : EncryptionContext{
		try{
			[$header, $claims, ] = JwtUtils::parse($handshakeJwt);
		}catch(JwtException $e){
			throw new EncryptionBridgeException("Failed to parse server handshake: " . $e->getMessage(), 0, $e);
		}

		$serverKey = $header["x5u"] ?? null;
		$salt = $claims["salt"] ?? null;
		if(!is_string($serverKey) || !is_string($salt)){
			throw new EncryptionBridgeException("Server handshake missing key or salt");
		}
		$saltBytes = base64_decode($salt, true);
		if($saltBytes === false){
			throw new EncryptionBridgeException("Server handshake salt is not valid base64");
		}

		try{
			$serverPublicKey = JwtUtils::parseDerPublicKey(base64_decode($serverKey, true) ?: "");
			$secret = EncryptionUtils::generateSharedSecret($this->privateKey, $serverPublicKey);
		}catch(JwtException | \InvalidArgumentException $e){
			throw new EncryptionBridgeException("Failed to derive shared secret with destination server: " . $e->getMessage(), 0, $e);
		}

		return EncryptionContext::fakeGCM(EncryptionUtils::generateKey($secret, $saltBytes));
	}

	/**
	 * @return array{string, EncryptionContext}
	 *
	 * @throws EncryptionBridgeException
	 */
	public function createClientHandshake() : array{
		$clientKeyDer = $this->clientPublicKeyDer;
		if($clientKeyDer === null){
			throw new EncryptionBridgeException("Client LoginPacket has not been received");
		}

		$salt = random_bytes(16);
		try{
			$clientPublicKey = JwtUtils::parseDerPublicKey($clientKeyDer);
			$secret = EncryptionUtils::generateSharedSecret($this->privateKey, $clientPublicKey);
			$jwt = EncryptionUtils::generateServerHandshakeJwt($this->privateKey, $salt);
		}catch(JwtException | \InvalidArgumentException $e){
			throw new EncryptionBridgeException("Failed to derive shared secret with client: " . $e->getMessage(), 0, $e);
		}

		return [$jwt, EncryptionContext::fakeGCM(EncryptionUtils::generateKey($secret, $salt))];
	}

	/**
	 * @return string[]
	 * @phpstan-return array<string, string>
	 */
	private function header() : array{
		return ["alg" => "ES384", "x5u" => base64_encode($this->publicKeyDer)];
	}
}
