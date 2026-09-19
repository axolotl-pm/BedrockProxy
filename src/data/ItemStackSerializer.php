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

namespace pocketmine\bedrockproxy\data;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\DataDecodeException;
use pocketmine\bedrockproxy\data\bedrock\BlockItemIdMap;
use pocketmine\bedrockproxy\data\bedrock\BlockStateData;
use pocketmine\bedrockproxy\data\bedrock\BlockStateDictionary;
use pocketmine\bedrockproxy\data\json\ItemStackData;
use pocketmine\bedrockproxy\data\json\RecipeIngredientData;
use pocketmine\bedrockproxy\packet\BlockStateLookup;
use pocketmine\bedrockproxy\utils\Utils;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackExtraData;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackExtraDataShield;
use pocketmine\network\mcpe\protocol\types\recipe\MolangItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeIngredient;
use pocketmine\network\mcpe\protocol\types\recipe\StringIdMetaItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\TagItemDescriptor;
use function base64_encode;
use function count;
use function get_class;

final class ItemStackSerializer{

	private const WILDCARD_META = 32767;

	/** Sentinel block runtime ID meaning the item stack carries no block state. */
	private const NO_BLOCK_RUNTIME_ID = 0;

	/** Shields carry an extra field in their stack extra data that no other item has. */
	private const SHIELD_ITEM_ID = "minecraft:shield";

	private readonly BlockItemIdMap $blockItemIdMap;

	private readonly BlockStateDictionary $blockStateDictionary;

	public function __construct(
		private readonly ItemTypeDictionary $itemTypeDictionary,
		private readonly BlockStateLookup $blockStates
	){
		$this->blockItemIdMap = BlockItemIdMap::getInstance();
		$this->blockStateDictionary = $blockStates->getDictionary();
	}

	public function getItemTypeDictionary() : ItemTypeDictionary{ return $this->itemTypeDictionary; }

	/**
	 * @throws DataExtractionException
	 */
	public function itemStack(ItemStack $itemStack) : ItemStackData{
		if($itemStack->getId() === 0){
			throw new DataExtractionException("Cannot serialize empty ItemStack");
		}

		$itemStringId = $this->itemTypeDictionary->fromIntId($itemStack->getId());
		$data = new ItemStackData($itemStringId);

		if($itemStack->getCount() !== 1){
			$data->count = $itemStack->getCount();
		}

		$meta = $itemStack->getMeta();
		if($meta === self::WILDCARD_META){
			$meta = 0;
		}
		if($this->blockItemIdMap->lookupBlockId($itemStringId) !== null){
			if($meta !== 0){
				throw new DataExtractionException("Block item $itemStringId has unexpected non-zero meta $meta");
			}
			if($itemStack->getBlockRuntimeId() !== self::NO_BLOCK_RUNTIME_ID){
				$blockState = $this->blockStates->resolve($itemStack->getBlockRuntimeId());
				if($blockState === null){
					throw new DataExtractionException("Block item $itemStringId refers to unknown block state " . $itemStack->getBlockRuntimeId());
				}
				if(count($blockState->getStates()) > 0){
					$data->block_states = self::blockStateProperties($blockState);
				}
			}
		}elseif($itemStack->getBlockRuntimeId() !== self::NO_BLOCK_RUNTIME_ID){
			throw new DataExtractionException("Non-block item $itemStringId has block state runtime ID " . $itemStack->getBlockRuntimeId());
		}elseif($meta !== 0){
			$data->meta = $meta;
		}

		$this->readExtraData($itemStack, $itemStringId, $data);

		return $data;
	}

	/**
	 * @throws DataExtractionException
	 */
	private function readExtraData(ItemStack $itemStack, string $itemStringId, ItemStackData $data) : void{
		$rawExtraData = $itemStack->getRawExtraData();
		if($rawExtraData === ""){
			return;
		}

		try{
			$reader = new ByteBufferReader($rawExtraData);
			$extraData = $itemStringId === self::SHIELD_ITEM_ID ? ItemStackExtraDataShield::read($reader) : ItemStackExtraData::read($reader);
		}catch(DataDecodeException $e){
			throw new DataExtractionException("Failed to decode extra data for $itemStringId: " . $e->getMessage(), 0, $e);
		}

		$nbt = $extraData->getNbt();
		if($nbt !== null && count($nbt) > 0){
			$data->nbt = base64_encode((new LittleEndianNbtSerializer())->write(new TreeRoot($nbt)));
		}
		if(count($extraData->getCanPlaceOn()) > 0){
			$data->can_place_on = $extraData->getCanPlaceOn();
		}
		if(count($extraData->getCanDestroy()) > 0){
			$data->can_destroy = $extraData->getCanDestroy();
		}
	}

	/**
	 * @throws DataExtractionException
	 */
	public function recipeIngredient(RecipeIngredient $ingredient) : RecipeIngredientData{
		$descriptor = $ingredient->getDescriptor();
		if($descriptor === null){
			throw new DataExtractionException("Cannot serialize empty RecipeIngredient");
		}
		$data = new RecipeIngredientData();

		if($descriptor instanceof StringIdMetaItemDescriptor){
			$data->name = $descriptor->getId();
			$meta = $descriptor->getMeta();
			if($meta !== self::WILDCARD_META){
				$blockStateId = $this->blockStateDictionary->lookupStateIdFromIdMeta($data->name, $meta);
				if($this->blockItemIdMap->lookupBlockId($data->name) !== null && $blockStateId !== null){
					$blockState = $this->blockStateDictionary->generateDataFromStateId($blockStateId);
					if($blockState !== null && count($blockState->getStates()) > 0){
						$data->block_states = self::blockStateProperties($blockState);
					}
				}elseif($meta !== 0){
					$data->meta = $meta;
				}
			}else{
				$data->meta = $meta;
			}
		}elseif($descriptor instanceof TagItemDescriptor){
			$data->tag = $descriptor->getTag();
		}elseif($descriptor instanceof MolangItemDescriptor){
			$data->molang_expression = $descriptor->getMolangExpression();
			$data->molang_version = $descriptor->getMolangVersion();
		}else{
			throw new DataExtractionException("Unexpected item descriptor type " . get_class($descriptor));
		}

		if($ingredient->getCount() !== 1){
			$data->count = $ingredient->getCount();
		}

		return $data;
	}

	/**
	 * @throws DataExtractionException
	 */
	public function potionIngredient(int $itemId, int $meta) : RecipeIngredientData{
		return $this->recipeIngredient(new RecipeIngredient(new StringIdMetaItemDescriptor($this->itemTypeDictionary->fromIntId($itemId), $meta), 1));
	}

	public function itemName(int $itemId) : string{
		return $this->itemTypeDictionary->fromIntId($itemId);
	}

	private static function blockStateProperties(BlockStateData $blockStateData) : string{
		$properties = CompoundTag::create();
		foreach(Utils::stringifyKeys($blockStateData->getStates()) as $name => $value){
			$properties->setTag($name, $value);
		}

		return base64_encode((new LittleEndianNbtSerializer())->write(new TreeRoot($properties)));
	}
}
