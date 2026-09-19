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

use pocketmine\bedrockproxy\network\encryption\JwtException;
use pocketmine\bedrockproxy\network\encryption\JwtUtils;
use pocketmine\network\mcpe\protocol\ClientToServerHandshakePacket;
use pocketmine\network\mcpe\protocol\DisconnectPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\RequestNetworkSettingsPacket;
use function is_string;

/**
 * Handles inbound packets from the client destined for the upstream server.
 */
final class ClientPacketHandler extends ProxyPacketHandler{

	/**
	 * The JWT claim key carrying the username for clients without Xbox Live authentication.
	 */
	private const CLAIM_THIRD_PARTY_NAME = "ThirdPartyName";

	public function handleRequestNetworkSettings(RequestNetworkSettingsPacket $packet) : bool{
		$protocol = $packet->getProtocolVersion();
		if($protocol !== ProtocolInfo::CURRENT_PROTOCOL){
			$this->session->warn("Client protocol mismatch (client: $protocol, proxy: " . ProtocolInfo::CURRENT_PROTOCOL . "); packet decoding may fail or produce invalid data");
		}

		return true;
	}

	public function handleLogin(LoginPacket $packet) : bool{
		$this->session->rewriteLogin($packet);

		try{
			[, $claims, ] = JwtUtils::parse($packet->clientDataJwt);
		}catch(JwtException $e){
			$this->session->warn("Failed to parse client data in LoginPacket: " . $e->getMessage());

			return true;
		}

		$name = $claims[self::CLAIM_THIRD_PARTY_NAME] ?? null;
		if(is_string($name) && $name !== ""){
			$this->session->setName($name);
		}

		return true;
	}

	public function handleClientToServerHandshake(ClientToServerHandshakePacket $packet) : bool{
		if($this->session->isDecrypting()){
			// Drop client handshake response; destination handshake is already established
			$this->session->suppressCurrentBatch();
		}

		return true;
	}

	public function handleDisconnect(DisconnectPacket $packet) : bool{
		$this->session->note("Client initiated disconnect: " . ($packet->message ?? "reason " . $packet->reason));

		return true;
	}
}
