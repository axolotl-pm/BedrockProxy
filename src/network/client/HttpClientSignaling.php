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

namespace pocketmine\bedrockproxy\network\client;

use pocketmine\nethernet\discovery\GameType;
use pocketmine\nethernet\discovery\ServerData;
use pocketmine\nethernet\discovery\Signal;
use pocketmine\nethernet\discovery\SignalType;
use pocketmine\nethernet\negotiation\CandidateMode;
use pocketmine\nethernet\negotiation\ErrorCode;
use pocketmine\nethernet\signaling\http\HttpSignaling;
use pocketmine\nethernet\signaling\SignalingException;
use function ctype_digit;
use function curl_error;
use function curl_getinfo;
use function curl_init;
use function curl_multi_add_handle;
use function curl_multi_close;
use function curl_multi_exec;
use function curl_multi_getcontent;
use function curl_multi_info_read;
use function curl_multi_init;
use function curl_multi_remove_handle;
use function curl_setopt_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function microtime;
use function parse_url;
use function rawurlencode;
use function rtrim;
use function spl_object_id;
use function strlen;
use const CURLE_OK;
use const CURLINFO_RESPONSE_CODE;
use const CURLM_CALL_MULTI_PERFORM;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;

final class HttpClientSignaling implements ClientSignalingInterface{

	public const STATUS_INTERVAL = 5.0;
	public const JOIN_TIMEOUT = 25;

	private const CONNECT_TIMEOUT = 5;
	private const USER_AGENT = "libhttpclient/1.0.0.0";

	private ?\CurlMultiHandle $multi = null;
	private ?\CurlHandle $statusRequest = null;
	private float $nextStatus = 0.0;

	private readonly HttpRemoteServer $server;

	/**
	 * @var \CurlHandle[]
	 * @phpstan-var array<int, \CurlHandle>
	 */
	private array $joinRequests = [];

	/**
	 * @var string[]
	 * @phpstan-var array<int, string>
	 */
	private array $joinConnectionIds = [];

	/**
	 * @var array[]
	 * @phpstan-var list<array{string, Signal}>
	 */
	private array $inbound = [];

	/**
	 * @throws \InvalidArgumentException
	 */
	public function __construct(
		string $url,
		private readonly string $networkId,
		private readonly ?\Logger $logger = null
	){
		$this->server = new HttpRemoteServer(self::baseUrl($url));
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private static function baseUrl(string $url) : string{
		$parts = parse_url($url);
		if($parts === false || !isset($parts["scheme"], $parts["host"])){
			throw new \InvalidArgumentException("Destination URL must look like http://host:port, got \"$url\"");
		}
		if($parts["scheme"] !== "http" && $parts["scheme"] !== "https"){
			throw new \InvalidArgumentException("Destination URL must use http or https, got \"$url\"");
		}
		if(isset($parts["query"]) || isset($parts["fragment"]) || isset($parts["user"])){
			throw new \InvalidArgumentException("Destination URL must not carry credentials, a query or a fragment, got \"$url\"");
		}

		return rtrim($url, "/");
	}

	public function start() : void{
		if($this->multi !== null){
			throw new SignalingException("Already started");
		}
		$this->multi = curl_multi_init();
		$this->nextStatus = 0.0;
	}

	public function tick() : void{
		$multi = $this->multi;
		if($multi === null){
			return;
		}

		$now = microtime(true);
		if($this->statusRequest === null && $now >= $this->nextStatus){
			$this->nextStatus = $now + self::STATUS_INTERVAL;
			$this->statusRequest = $this->createRequest($this->server->baseUrl . HttpSignaling::PATH_JOIN, null);
			curl_multi_add_handle($multi, $this->statusRequest);
		}

		do{
			$status = curl_multi_exec($multi, $running);
		}while($status === CURLM_CALL_MULTI_PERFORM);

		while(($info = curl_multi_info_read($multi)) !== false){
			$handle = $info["handle"];
			curl_multi_remove_handle($multi, $handle);

			$body = curl_multi_getcontent($handle);
			$responseCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
			$error = $info["result"] === CURLE_OK ? null : curl_error($handle);

			if($handle === $this->statusRequest){
				$this->statusRequest = null;
				$this->handleStatus(is_string($body) ? $body : "", is_int($responseCode) ? $responseCode : 0, $error);
			}else{
				$id = spl_object_id($handle);
				$connectionId = $this->joinConnectionIds[$id] ?? null;
				unset($this->joinRequests[$id], $this->joinConnectionIds[$id]);
				if($connectionId !== null){
					$this->handleJoin($connectionId, is_string($body) ? $body : "", is_int($responseCode) ? $responseCode : 0, $error);
				}
			}
		}
	}

	public function shutdown() : void{
		$multi = $this->multi;
		if($multi === null){
			return;
		}
		foreach($this->joinRequests as $handle){
			curl_multi_remove_handle($multi, $handle);
		}
		if($this->statusRequest !== null){
			curl_multi_remove_handle($multi, $this->statusRequest);
			$this->statusRequest = null;
		}
		curl_multi_close($multi);
		$this->multi = null;
		$this->joinRequests = [];
		$this->joinConnectionIds = [];
		$this->inbound = [];
	}

	public function getCandidateMode() : CandidateMode{
		return CandidateMode::BUNDLED;
	}

	public function getRemoteServer() : RemoteServer{
		return $this->server;
	}

	public function sendSignal(RemoteServer $server, Signal $signal) : void{
		$multi = $this->multi;
		if($multi === null){
			throw new SignalingException("HTTP signaling is not started");
		}
		if($server !== $this->server){
			throw new SignalingException("HTTP signaling can only reach " . $this->server->baseUrl);
		}

		switch($signal->type){
			case SignalType::CONNECT_REQUEST:
				$handle = $this->createRequest($this->server->baseUrl . HttpSignaling::PATH_JOIN . "/" . rawurlencode($this->networkId), $signal->data);
				$id = spl_object_id($handle);
				$this->joinRequests[$id] = $handle;
				$this->joinConnectionIds[$id] = $signal->connectionId;
				curl_multi_add_handle($multi, $handle);

				return;
			case SignalType::CONNECT_ERROR:
				return;
			case SignalType::CANDIDATE_ADD:
				throw new SignalingException("HTTP signaling does not support trickle ICE");
			case SignalType::CONNECT_RESPONSE:
				throw new SignalingException("A client cannot send an answer over HTTP signaling");
		}
	}

	public function takeSignals() : array{
		$taken = $this->inbound;
		$this->inbound = [];

		return $taken;
	}

	/**
	 * @throws SignalingException
	 */
	private function createRequest(string $url, ?string $sdp) : \CurlHandle{
		$handle = curl_init($url);
		if($handle === false){
			throw new SignalingException("Failed to initialize HTTP request for $url");
		}
		$options = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
			CURLOPT_TIMEOUT => self::JOIN_TIMEOUT,
			CURLOPT_HTTPHEADER => ["User-Agent: " . self::USER_AGENT]
		];
		if($sdp !== null){
			$options[CURLOPT_POST] = true;
			$options[CURLOPT_POSTFIELDS] = $sdp;
			$options[CURLOPT_HTTPHEADER][] = "Content-Type: " . HttpSignaling::CONTENT_TYPE_SDP;
			$options[CURLOPT_HTTPHEADER][] = "Content-Length: " . strlen($sdp);
		}
		curl_setopt_array($handle, $options);

		return $handle;
	}

	private function handleJoin(string $connectionId, string $body, int $responseCode, ?string $error) : void{
		$networkId = $this->server->getNetworkId();
		if($error !== null){
			$this->logger?->debug("Join request $connectionId failed: $error");
			$this->inbound[] = [$networkId, new Signal(SignalType::CONNECT_ERROR, $connectionId, (string) ErrorCode::SIGNALING_MESSAGE_DELIVERY_FAILED->value)];

			return;
		}
		if($responseCode !== 200 || $body === ""){
			$code = ctype_digit($body) ? $body : (string) ErrorCode::CONNECT_REQUEST->value;
			$this->logger?->debug("Join request $connectionId rejected with HTTP $responseCode" . ($body !== "" ? ": $body" : ""));
			$this->inbound[] = [$networkId, new Signal(SignalType::CONNECT_ERROR, $connectionId, $code)];

			return;
		}
		if(ctype_digit($body)){
			$this->inbound[] = [$networkId, new Signal(SignalType::CONNECT_ERROR, $connectionId, $body)];

			return;
		}

		$this->inbound[] = [$networkId, new Signal(SignalType::CONNECT_RESPONSE, $connectionId, $body)];
	}

	private function handleStatus(string $body, int $responseCode, ?string $error) : void{
		if($error !== null || $responseCode !== 200){
			$this->logger?->debug("Status request failed: " . ($error ?? "HTTP $responseCode"));

			return;
		}
		if($body === ""){
			return;
		}

		$status = json_decode($body, true);
		if(!is_array($status)){
			$this->logger?->debug("Status response is not a JSON object");

			return;
		}
		$name = $status["name"] ?? null;
		$level = $status["level"] ?? null;
		$players = $status["players"] ?? 0;
		$maxPlayers = $status["maxPlayers"] ?? 0;
		$gameType = $status["gameType"] ?? GameType::SURVIVAL->value;
		if(!is_string($name) || !is_string($level) || !is_int($players) || !is_int($maxPlayers) || !is_int($gameType)){
			$this->logger?->debug("Status response has unexpected field types");

			return;
		}

		$this->server->serverData = new ServerData(
			serverName: $name,
			levelName: $level,
			gameType: GameType::tryFrom($gameType) ?? GameType::SURVIVAL,
			playerCount: $players,
			maxPlayerCount: $maxPlayers
		);
	}
}
