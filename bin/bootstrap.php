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

/*
 * Points PocketMine at the data packages before its own constants are defined, then loads the autoloader.
 *
 * PocketMine works out where its data lives from where it sits, expecting to be the root project with its own
 * vendor directory underneath. Installed as a dependency it is not, so every path it derives lands one vendor
 * directory too deep and nothing it ships can be read. Its constants file returns early when the guard below is
 * already defined, which is the seam it offers for saying where things really are.
 *
 * This has to run before vendor/autoload.php, because composer defines those constants while loading.
 */

$vendor = dirname(__DIR__) . "/vendor";
$pocketmine = $vendor . "/axolotl-pm/pocketmine-mp";

define('pocketmine\_CORE_CONSTANTS_INCLUDED', true);
define('pocketmine\PATH', $pocketmine . "/");
define('pocketmine\RESOURCE_PATH', $pocketmine . "/resources/");
define('pocketmine\LOCALE_DATA_PATH', $pocketmine . "/resources/translations/");
define('pocketmine\BEDROCK_DATA_PATH', $vendor . "/axolotl-pm/bedrock-data/");
define('pocketmine\BEDROCK_BLOCK_UPGRADE_SCHEMA_PATH', $vendor . "/axolotl-pm/bedrock-block-upgrade-schema/");
define('pocketmine\BEDROCK_ITEM_UPGRADE_SCHEMA_PATH', $vendor . "/axolotl-pm/bedrock-item-upgrade-schema/");
define('pocketmine\COMPOSER_AUTOLOADER_PATH', $vendor . "/autoload.php");

require_once $vendor . "/autoload.php";
