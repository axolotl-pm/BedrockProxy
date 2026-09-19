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

namespace pocketmine\bedrockproxy\logging;

use pocketmine\bedrockproxy\utils\Utils;
use function date;
use function fclose;
use function fopen;
use function fwrite;
use function is_string;
use function microtime;
use function sprintf;
use function strtoupper;
use const PHP_EOL;
use const STDOUT;

/**
 * Writes every log line to the terminal and, when a log file is given, appends it there as well.
 */
final class ConsoleLogger extends \SimpleLogger{

	private const COLOURS = [
		\LogLevel::EMERGENCY => "\x1b[1;31m",
		\LogLevel::ALERT => "\x1b[1;31m",
		\LogLevel::CRITICAL => "\x1b[1;31m",
		\LogLevel::ERROR => "\x1b[0;31m",
		\LogLevel::WARNING => "\x1b[0;33m",
		\LogLevel::NOTICE => "\x1b[0;36m",
		\LogLevel::INFO => "\x1b[0;37m",
		\LogLevel::DEBUG => "\x1b[0;90m"
	];

	private const RESET = "\x1b[0m";

	/** @var resource|null */
	private $file = null;

	public function __construct(
		?string $logFile = null,
		private readonly bool $useFormattingCodes = false,
		private readonly bool $logDebug = true
	){
		if($logFile !== null){
			$handle = @fopen($logFile, "ab");
			if($handle !== false){
				$this->file = $handle;
			}
		}
	}

	public function log($level, $message){
		$level = is_string($level) ? $level : \LogLevel::INFO;
		if($level === \LogLevel::DEBUG && !$this->logDebug){
			return;
		}

		$now = microtime(true);
		$line = sprintf("[%s.%03d] [%s] %s", date("H:i:s", (int) $now), (int) (($now - (int) $now) * 1000), strtoupper($level), $message);

		$colour = self::COLOURS[$level] ?? null;
		fwrite(STDOUT, ($this->useFormattingCodes && $colour !== null ? $colour . $line . self::RESET : $line) . PHP_EOL);
		if($this->file !== null){
			fwrite($this->file, $line . PHP_EOL);
		}
	}

	public function logException(\Throwable $e, $trace = null){
		foreach(Utils::printableExceptionInfo($e) as $line){
			$this->critical($line);
		}
	}

	public function close() : void{
		if($this->file !== null){
			fclose($this->file);
			$this->file = null;
		}
	}
}
