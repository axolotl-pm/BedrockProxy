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

use pocketmine\bedrockproxy\network\ProxyServer;
use pocketmine\nethernet\crypto\CryptoException;
use pocketmine\nethernet\signaling\SignalingException;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use Symfony\Component\Filesystem\Path;
use function copy;
use function is_dir;
use function is_file;
use function microtime;
use function mkdir;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function time_sleep_until;
use const SIGINT;
use const SIGTERM;

final class BedrockProxy{

	public const CONFIG_FILE = "config.yml";

	private const TPS = 100;
	private const TIME_PER_TICK = 1 / self::TPS;

	private readonly ProxyServer $proxy;
	private bool $running = false;

	/**
	 * @throws ConfigurationException
	 * @throws CryptoException
	 * @throws \InvalidArgumentException
	 */
	public function __construct(
		private readonly string $dataPath,
		private readonly \Logger $logger
	){
		$this->logger->info("Starting BedrockProxy for Minecraft: Bedrock Edition " . ProtocolInfo::MINECRAFT_VERSION_NETWORK . " (protocol " . ProtocolInfo::CURRENT_PROTOCOL . ")");

		$config = Configuration::load($this->prepareConfigFile());
		$this->proxy = new ProxyServer($config, $dataPath, $logger);
	}

	/**
	 * @throws ConfigurationException
	 */
	private function prepareConfigFile() : string{
		if(!is_dir($this->dataPath) && !@mkdir($this->dataPath, 0777, true)){
			throw new ConfigurationException("Failed to create data directory " . $this->dataPath);
		}

		$path = Path::join($this->dataPath, self::CONFIG_FILE);
		if(!is_file($path)){
			$default = Path::join(__DIR__, "..", "resources", self::CONFIG_FILE);
			if(!@copy($default, $path)){
				throw new ConfigurationException("Failed to copy default configuration to $path");
			}
			$this->logger->info("Created " . self::CONFIG_FILE . " with the default settings");
		}

		return $path;
	}

	/**
	 * @throws SignalingException
	 */
	public function start() : void{
		$this->proxy->start();
		$this->running = true;
		$this->registerSignalHandlers();

		while($this->running && $this->proxy->isRunning()){
			$start = microtime(true);
			pcntl_signal_dispatch();
			$this->proxy->tick();
			self::sleepUntilNextTick($start);
		}

		$this->proxy->shutdown();
		$this->logger->info("Proxy stopped");
	}

	public function stop() : void{
		if($this->running){
			$this->running = false;
			$this->logger->info("Stopping proxy...");
		}
	}

	private function registerSignalHandlers() : void{
		$handler = function() : void{
			$this->stop();
		};
		pcntl_signal(SIGINT, $handler);
		pcntl_signal(SIGTERM, $handler);
	}

	private static function sleepUntilNextTick(float $start) : void{
		if(microtime(true) - $start < self::TIME_PER_TICK){
			@time_sleep_until($start + self::TIME_PER_TICK);
		}
	}
}
