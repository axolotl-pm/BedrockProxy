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

namespace pocketmine\bedrockproxy\utils;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function rename;
use function unlink;

final class Filesystem{

	private function __construct(){}

	/**
	 * @throws \RuntimeException
	 */
	public static function fileGetContents(string $fileName) : string{
		$contents = @file_get_contents($fileName);
		if($contents === false){
			throw new \RuntimeException("Failed to read file $fileName");
		}

		return $contents;
	}

	/**
	 * @throws \RuntimeException
	 */
	public static function safeFilePutContents(string $fileName, string $contents) : void{
		$directory = dirname($fileName);
		if(!is_dir($directory)){
			throw new \RuntimeException("Target directory $directory does not exist or is not a directory");
		}
		if(is_dir($fileName)){
			throw new \RuntimeException("Target file path $fileName already exists and is not a file");
		}

		$temporaryFileName = $fileName . ".tmp";
		if(@file_put_contents($temporaryFileName, $contents) === false){
			throw new \RuntimeException("Failed to write to temporary file $temporaryFileName");
		}
		if(!@rename($temporaryFileName, $fileName)){
			@unlink($temporaryFileName);
			throw new \RuntimeException("Failed to move $temporaryFileName into $fileName");
		}
	}
}
