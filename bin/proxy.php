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

use pocketmine\utils\MainLogger;
use pocketmine\utils\Terminal;
use Symfony\Component\Filesystem\Path;
use function count;
use function date_default_timezone_get;
use function extension_loaded;
use function fwrite;
use function getcwd;
use function implode;
use function is_string;
use function version_compare;
use const PHP_EOL;
use const PHP_INT_SIZE;
use const PHP_VERSION;
use const STDERR;

require_once __DIR__ . "/bootstrap.php";

/**
 * @return string[]
 * @phpstan-return list<string>
 */
function checkRequirements() : array{
	$problems = [];
	if(version_compare(PHP_VERSION, "8.1.0") < 0){
		$problems[] = "PHP 8.1 or newer is required, this is " . PHP_VERSION;
	}
	if(PHP_INT_SIZE < 8){
		$problems[] = "A 64-bit PHP build is required";
	}
	foreach(["curl", "encoding", "json", "openssl", "pcntl", "sockets", "webrtc", "yaml", "zlib"] as $extension){
		if(!extension_loaded($extension)){
			$problems[] = "The $extension extension is required";
		}
	}

	return $problems;
}

$problems = checkRequirements();
if(count($problems) > 0){
	fwrite(STDERR, "BedrockProxy cannot start:" . PHP_EOL . " - " . implode(PHP_EOL . " - ", $problems) . PHP_EOL);
	exit(1);
}

$cwd = getcwd();
$dataPath = $argv[1] ?? (is_string($cwd) ? $cwd : ".");

Terminal::init();
$logger = new MainLogger(
	logFile: Path::join($dataPath, "proxy.log"),
	useFormattingCodes: Terminal::hasFormattingCodes(),
	mainThreadName: "Proxy",
	timezone: new \DateTimeZone(date_default_timezone_get()),
	logDebug: true
);

try{
	$proxy = new BedrockProxy($dataPath, $logger);
	$proxy->start();
}catch(\Throwable $e){
	$logger->logException($e);
	$logger->shutdownLogWriterThread();
	exit(1);
}

$logger->shutdownLogWriterThread();
