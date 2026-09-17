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

namespace pocketmine\bedrockproxy;

use pocketmine\bedrockproxy\logging\LogTarget;
use pocketmine\bedrockproxy\packet\BlockStateIdMode;
use pocketmine\utils\Config;
use function get_debug_type;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

final class Configuration{

	/**
	 * @param string[] $ignoredPackets
	 * @phpstan-param list<string> $ignoredPackets
	 */
	public function __construct(
		public readonly string $bindAddress,
		public readonly int $networkId,
		public readonly bool $lanEnabled,
		public readonly int $lanPort,
		public readonly bool $httpEnabled,
		public readonly int $httpPort,
		public readonly string $serverName,
		public readonly string $levelName,
		public readonly string $identityKeyFile,
		public readonly ?string $iceBindAddress,
		public readonly SignalingType $destinationSignaling,
		public readonly string $destinationAddress,
		public readonly int $destinationPort,
		public readonly int $destinationNetworkId,
		public readonly float $destinationTimeout,
		public readonly bool $logPackets,
		public readonly LogTarget $logTarget,
		public readonly array $ignoredPackets,
		public readonly BlockStateIdMode $blockStateIdMode,
		public readonly bool $decryptPackets,
		public readonly bool $extractData,
		public readonly string $dataPath
	){
		foreach(["proxy.lan.port" => $lanPort, "proxy.http.port" => $httpPort, "destination.port" => $destinationPort] as $key => $port){
			if($port < 1 || $port > 65535){
				throw new ConfigurationException("$key must be between 1 and 65535, got $port");
			}
		}
		if($networkId < 0 || $destinationNetworkId < 0){
			throw new ConfigurationException("Network IDs must not be negative");
		}
		if($destinationTimeout <= 0.0){
			throw new ConfigurationException("destination.timeout must be positive, got $destinationTimeout");
		}
		if($identityKeyFile === ""){
			throw new ConfigurationException("proxy.identity-key must not be empty");
		}
		if($destinationAddress === ""){
			throw new ConfigurationException("destination.address must not be empty");
		}
		if($dataPath === ""){
			throw new ConfigurationException("data-path must not be empty");
		}
	}

	/**
	 * @throws ConfigurationException
	 */
	public static function load(string $path) : self{
		$config = new Config($path, Config::YAML);

		$iceBindAddress = self::string($config, "proxy.ice-bind-address", "");
		$ignoredPackets = [];
		$ignored = $config->getNested("ignored-packets", []);
		if(!is_array($ignored)){
			throw new ConfigurationException("ignored-packets must be a list of packet names");
		}
		foreach($ignored as $name){
			if(!is_string($name)){
				throw new ConfigurationException("ignored-packets must only contain packet names, got " . get_debug_type($name));
			}
			$ignoredPackets[] = $name;
		}

		return new self(
			bindAddress: self::string($config, "proxy.bind-address", "0.0.0.0"),
			networkId: self::int($config, "proxy.network-id", 0),
			lanEnabled: self::bool($config, "proxy.lan.enabled", true),
			lanPort: self::int($config, "proxy.lan.port", 7551),
			httpEnabled: self::bool($config, "proxy.http.enabled", true),
			httpPort: self::int($config, "proxy.http.port", 19132),
			serverName: self::string($config, "proxy.server-name", ""),
			levelName: self::string($config, "proxy.level-name", ""),
			identityKeyFile: self::string($config, "proxy.identity-key", "proxy-identity.pem"),
			iceBindAddress: $iceBindAddress === "" ? null : $iceBindAddress,
			destinationSignaling: SignalingType::tryFrom(self::string($config, "destination.signaling", "lan"))
				?? throw new ConfigurationException("destination.signaling must be one of: lan, http"),
			destinationAddress: self::string($config, "destination.address", "255.255.255.255"),
			destinationPort: self::int($config, "destination.port", 7551),
			destinationNetworkId: self::int($config, "destination.network-id", 0),
			destinationTimeout: self::float($config, "destination.timeout", 15.0),
			logPackets: self::bool($config, "log-packets", true),
			logTarget: LogTarget::tryFrom(self::string($config, "log-to", "file"))
				?? throw new ConfigurationException("log-to must be one of: console, file, both"),
			ignoredPackets: $ignoredPackets,
			blockStateIdMode: BlockStateIdMode::tryFrom(self::string($config, "block-states", "auto"))
				?? throw new ConfigurationException("block-states must be one of: auto, runtime, hashed"),
			decryptPackets: self::bool($config, "decrypt-packets", false),
			extractData: self::bool($config, "extract-data", false),
			dataPath: self::string($config, "data-path", "data")
		);
	}

	private static function string(Config $config, string $key, string $default) : string{
		$value = $config->getNested($key, $default);
		if(is_int($value) || is_float($value)){
			$value = (string) $value;
		}
		if(!is_string($value)){
			throw new ConfigurationException("$key must be a string, got " . get_debug_type($value));
		}

		return $value;
	}

	private static function int(Config $config, string $key, int $default) : int{
		$value = $config->getNested($key, $default);
		if(!is_int($value)){
			throw new ConfigurationException("$key must be an integer, got " . get_debug_type($value));
		}

		return $value;
	}

	private static function float(Config $config, string $key, float $default) : float{
		$value = $config->getNested($key, $default);
		if(is_int($value)){
			$value = (float) $value;
		}
		if(!is_float($value)){
			throw new ConfigurationException("$key must be a number, got " . get_debug_type($value));
		}

		return $value;
	}

	private static function bool(Config $config, string $key, bool $default) : bool{
		$value = $config->getNested($key, $default);
		if(!is_bool($value)){
			throw new ConfigurationException("$key must be true or false, got " . get_debug_type($value));
		}

		return $value;
	}
}
