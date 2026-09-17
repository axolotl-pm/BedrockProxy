# BedrockProxy

A lightweight Minecraft: Bedrock Edition man-in-the-middle (MITM) proxy written in PHP, built on top of [PocketMine-MP](https://github.com/axolotl-pm/pocketmine-mp) and NetherNet libraries. Primarily designed for protocol research, packet inspection, and extracting game data against Bedrock Dedicated Server (BDS).

## Requirements

- PHP 8.1 or newer (64-bit)
- Composer
- PHP Extensions:
  - `ext-curl`
  - `ext-encoding`
  - `ext-gmp`
  - `ext-json`
  - `ext-openssl`
  - `ext-pcntl`
  - `ext-sockets`
  - `ext-webrtc`
  - `ext-yaml`
  - `ext-zlib`

## Quick Start

1. Install dependencies:
   ```bash
   composer install --no-dev
   ```

2. Configure the proxy in `config.yml`:
   * Set `destination.address` to your target BDS server.
   * Ensure the destination server runs in offline mode (`online-mode=false` in BDS `server.properties`).
   * Enable `extract-data: true` if you want to extract `bedrock-data` files.

3. Start the proxy:
   ```bash
   php bin/proxy.php
   ```

## Extracted Data

When `extract-data` is enabled, the proxy automatically extracts the following datasets upon client join:

- **Required Item List** (`required_item_list.json`): Canonical item definitions and component NBT extracted from `ItemRegistryPacket`.
- **Creative Inventory** (`creative/*.json`): Grouped creative item categories extracted from `CreativeContentPacket`.
- **Recipes** (`recipes/*.json`): Shaped, shapeless, smithing, and potion recipes extracted from `CraftingDataPacket`.

