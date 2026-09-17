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

use pocketmine\nethernet\discovery\GameType;
use pocketmine\nethernet\discovery\ServerData;
use pocketmine\nethernet\signaling\http\ServerStatus;
use pocketmine\network\mcpe\protocol\ProtocolInfo;

final class ServerAdvertisement{

	public static function serverData(ServerData $source, string $serverNameOverride, string $levelNameOverride) : ServerData{
		return new ServerData(
			serverName: $serverNameOverride !== "" ? $serverNameOverride : $source->serverName,
			levelName: $levelNameOverride !== "" ? $levelNameOverride : $source->levelName,
			gameType: $source->gameType,
			playerCount: $source->playerCount,
			maxPlayerCount: $source->maxPlayerCount,
			editorWorld: $source->editorWorld,
			hardcore: $source->hardcore,
			acceptsOnlineAuth: $source->acceptsOnlineAuth,
			acceptsSelfSignedAuth: $source->acceptsSelfSignedAuth
		);
	}

	public static function status(ServerData $source, string $serverNameOverride, string $levelNameOverride) : ServerStatus{
		return new ServerStatus(
			serverName: $serverNameOverride !== "" ? $serverNameOverride : $source->serverName,
			protocol: ProtocolInfo::CURRENT_PROTOCOL,
			version: ProtocolInfo::MINECRAFT_VERSION_NETWORK,
			levelName: $levelNameOverride !== "" ? $levelNameOverride : $source->levelName,
			playerCount: $source->playerCount,
			maxPlayerCount: $source->maxPlayerCount,
			gameType: $source->gameType
		);
	}

	public static function placeholder(string $serverNameOverride, string $levelNameOverride) : ServerData{
		return new ServerData(
			serverName: $serverNameOverride !== "" ? $serverNameOverride : "BedrockProxy",
			levelName: $levelNameOverride !== "" ? $levelNameOverride : "Connecting...",
			gameType: GameType::SURVIVAL
		);
	}
}
