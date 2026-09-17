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

use pocketmine\bedrockproxy\packet\Direction;
use pocketmine\bedrockproxy\packet\InspectedPacket;
use pocketmine\bedrockproxy\packet\PacketDumper;
use Symfony\Component\Filesystem\Path;
use function bin2hex;
use function date;
use function fclose;
use function fopen;
use function fwrite;
use function is_dir;
use function microtime;
use function mkdir;
use function preg_replace;
use function sprintf;
use function strlen;
use function substr;
use function trim;

final class SessionLogger{

	public const FLUSH_INTERVAL = 5.0;

	private const RAW_PREVIEW_LENGTH = 64;

	private string $buffer = "";
	private float $nextFlush;
	private ?string $directory = null;

	/** @var resource|null */
	private $file = null;
	private bool $closed = false;

	/**
	 * @param string[] $ignoredPackets
	 * @phpstan-param array<string, true> $ignoredPackets
	 */
	public function __construct(
		private readonly LogTarget $target,
		private readonly string $sessionsPath,
		private string $name,
		private readonly array $ignoredPackets,
		private readonly PacketDumper $dumper,
		private readonly \Logger $console
	){
		$this->nextFlush = microtime(true) + self::FLUSH_INTERVAL;
	}

	public function setName(string $name) : void{
		if($this->directory === null){
			$this->name = $name;
		}
	}

	public function getName() : string{ return $this->name; }

	public function logPacket(Direction $direction, InspectedPacket $packet) : void{
		if(isset($this->ignoredPackets[$packet->name])){
			return;
		}

		$text = $packet->packet !== null ? $this->dumper->dump($packet->packet) : $packet->name . self::describeRaw($packet);
		$this->log($direction->getLabel(), $text);
	}

	public function logNote(string $note, ?Direction $direction = null) : void{
		$this->log($direction?->getLabel() ?? "PROXY", "// " . $note);
	}

	private static function describeRaw(InspectedPacket $packet) : string{
		$length = strlen($packet->buffer);
		$preview = bin2hex(substr($packet->buffer, 0, self::RAW_PREVIEW_LENGTH)) . ($length > self::RAW_PREVIEW_LENGTH ? "..." : "");

		return "{" . ($packet->decodeError !== null ? "decodeError=\"" . $packet->decodeError . "\", " : "") . "length=$length, bytes=0x$preview}";
	}

	private function log(string $direction, string $text) : void{
		$now = microtime(true);
		$line = sprintf("[%s:%03d] [%s] - %s", date("H:i:s", (int) $now), (int) (($now - (int) $now) * 1000), $direction, $text);

		if($this->target->logsToConsole()){
			$this->console->info("[" . $this->name . "] " . $line);
		}
		if($this->target->logsToFile()){
			$this->buffer .= $line . "\n";
		}
	}

	public function tick() : void{
		if(microtime(true) >= $this->nextFlush){
			$this->flush();
		}
	}

	public function close() : void{
		if($this->closed){
			return;
		}
		$this->closed = true;
		$this->flush();
		if($this->file !== null){
			fclose($this->file);
			$this->file = null;
		}
	}

	private function flush() : void{
		$this->nextFlush = microtime(true) + self::FLUSH_INTERVAL;
		if($this->buffer === ""){
			return;
		}

		if($this->directory === null){
			$this->open();
		}
		if($this->file !== null){
			fwrite($this->file, $this->buffer);
		}
		$this->buffer = "";
	}

	private function open() : void{
		$safeName = trim(preg_replace('/[^A-Za-z0-9 _.-]+/', "_", $this->name) ?? "session");
		if($safeName === "" || $safeName === "." || $safeName === ".."){
			$safeName = "session";
		}
		$directory = Path::join($this->sessionsPath, $safeName . "-" . date("Y-m-d_H-i-s"));
		$this->directory = $directory;

		if(!is_dir($directory) && !@mkdir($directory, 0777, true)){
			$this->console->error("Failed to create session directory $directory; packet log for " . $this->name . " is disabled");

			return;
		}

		$file = @fopen(Path::join($directory, "packets.log"), "ab");
		if($file === false){
			$this->console->error("Failed to open packet log in $directory; packet log for " . $this->name . " is disabled");

			return;
		}
		$this->file = $file;
	}
}
