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

namespace pocketmine\bedrockproxy\network\handler;

use pocketmine\bedrockproxy\data\DataExtractor;
use pocketmine\network\mcpe\protocol\CraftingDataPacket;
use pocketmine\network\mcpe\protocol\CreativeContentPacket;
use pocketmine\network\mcpe\protocol\DisconnectPacket;
use pocketmine\network\mcpe\protocol\ItemRegistryPacket;
use pocketmine\network\mcpe\protocol\NetworkSettingsPacket;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\ServerToClientHandshakePacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;

/**
 * Handles inbound packets from the destination server destined for the client.
 */
final class ServerPacketHandler extends ProxyPacketHandler{

	public function handleNetworkSettings(NetworkSettingsPacket $packet) : bool{
		$this->session->onCompressionEnabled($packet->getCompressionAlgorithm());

		return true;
	}

	public function handleItemRegistry(ItemRegistryPacket $packet) : bool{
		$this->session->extractData(fn(DataExtractor $extractor) => $extractor->handleItemRegistry($packet));

		return true;
	}

	public function handleCraftingData(CraftingDataPacket $packet) : bool{
		$this->session->extractData(fn(DataExtractor $extractor) => $extractor->handleCraftingData($packet));

		return true;
	}

	public function handleCreativeContent(CreativeContentPacket $packet) : bool{
		$this->session->extractData(fn(DataExtractor $extractor) => $extractor->handleCreativeContent($packet));

		return true;
	}

	public function handleStartGame(StartGamePacket $packet) : bool{
		$this->session->onBlockPalette($packet->blockNetworkIdsAreHashes, $packet->blockPalette);

		return true;
	}

	public function handleServerToClientHandshake(ServerToClientHandshakePacket $packet) : bool{
		if(!$this->session->isDecrypting()){
			// NetherNet transport handles underlying encryption; relay handshake without cipher activation
			$this->session->note("Received encryption handshake from destination server; relaying without cipher activation (NetherNet transport)");

			return true;
		}

		$this->session->startEncryption($packet->jwt);

		return true;
	}

	public function handlePlayStatus(PlayStatusPacket $packet) : bool{
		if($packet->status !== PlayStatusPacket::LOGIN_SUCCESS && $packet->status !== PlayStatusPacket::PLAYER_SPAWN){
			$this->session->note("Destination server rejected login with PlayStatus: " . $packet->status);
		}

		return true;
	}

	public function handleDisconnect(DisconnectPacket $packet) : bool{
		$this->session->note("Client disconnected by destination server: " . ($packet->message ?? "reason " . $packet->reason));

		return true;
	}
}
