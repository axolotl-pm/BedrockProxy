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

use pocketmine\bedrockproxy\data\json\CreativeGroupData;
use pocketmine\bedrockproxy\data\json\ItemStackData;
use pocketmine\bedrockproxy\data\json\PotionContainerChangeRecipeData;
use pocketmine\bedrockproxy\data\json\PotionTypeRecipeData;
use pocketmine\bedrockproxy\data\json\RecipeIngredientData;
use pocketmine\bedrockproxy\data\json\ShapedRecipeData;
use pocketmine\bedrockproxy\data\json\ShapelessRecipeData;
use pocketmine\bedrockproxy\data\json\SmithingTransformRecipeData;
use pocketmine\bedrockproxy\data\json\SmithingTrimRecipeData;
use pocketmine\bedrockproxy\packet\BlockStateLookup;
use pocketmine\bedrockproxy\utils\Utils;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\protocol\CraftingDataPacket;
use pocketmine\network\mcpe\protocol\CreativeContentPacket;
use pocketmine\network\mcpe\protocol\ItemRegistryPacket;
use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\network\mcpe\protocol\types\inventory\CreativeGroupEntry;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\recipe\MultiRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeIngredient;
use pocketmine\network\mcpe\protocol\types\recipe\ShapedRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\ShapelessRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\SmithingTransformRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\SmithingTrimRecipe;
use Symfony\Component\Filesystem\Path;
use function array_map;
use function array_values;
use function base64_encode;
use function chr;
use function count;
use function file_put_contents;
use function get_class;
use function implode;
use function is_dir;
use function json_encode;
use function ksort;
use function mkdir;
use function ord;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const SORT_STRING;

final class DataExtractor{

	private const RECIPE_FILES = [
		"shaped_crafting" => "shapedRecipes",
		"shapeless_crafting" => "shapelessRecipes",
		"special_hardcoded" => "multiRecipes",
		"shapeless_shulker_box" => "userDataShapelessRecipes",
		"shapeless_chemistry" => "shapelessChemistryRecipes",
		"shaped_chemistry" => "shapedChemistryRecipes",
		"smithing" => "smithingTransformRecipes",
		"smithing_trim" => "smithingTrimRecipes"
	];

	private const CREATIVE_CATEGORIES = [
		CreativeContentPacket::CATEGORY_CONSTRUCTION => "construction",
		CreativeContentPacket::CATEGORY_NATURE => "nature",
		CreativeContentPacket::CATEGORY_EQUIPMENT => "equipment",
		CreativeContentPacket::CATEGORY_ITEMS => "items"
	];

	private ?ItemStackSerializer $items = null;

	public function __construct(
		private readonly string $path,
		private BlockStateLookup $blockStates,
		private readonly \Logger $logger
	){
	}

	/**
	 * @throws DataExtractionException
	 */
	public static function create(string $path, \Logger $logger) : self{
		try{
			$blockStates = BlockStateLookup::fromBedrockData();
		}catch(\RuntimeException $e){
			throw new DataExtractionException("Failed to read block palette: " . $e->getMessage(), 0, $e);
		}

		return new self($path, $blockStates, $logger);
	}

	public function setBlockStates(BlockStateLookup $blockStates) : void{
		$this->blockStates = $blockStates;
		if($this->items !== null){
			$this->items = new ItemStackSerializer($this->items->getItemTypeDictionary(), $blockStates);
		}
	}

	public function isReady() : bool{
		return $this->items !== null;
	}

	/**
	 * @throws DataExtractionException
	 */
	public function handleItemRegistry(ItemRegistryPacket $packet) : void{
		$entries = $packet->getEntries();
		$this->items = new ItemStackSerializer(new ItemTypeDictionary($entries), $this->blockStates);

		$empty = new CompoundTag();
		$table = [];
		foreach($entries as $entry){
			$item = [
				"runtime_id" => $entry->getNumericId(),
				"component_based" => $entry->isComponentBased(),
				"version" => $entry->getVersion()
			];

			$components = $entry->getComponentNbt()->getRoot();
			if($components instanceof CompoundTag && !$components->equals($empty)){
				$item["component_nbt"] = base64_encode((new LittleEndianNbtSerializer())->write(new TreeRoot($components)));
			}
			$table[$entry->getStringId()] = $item;
		}
		ksort($table, SORT_STRING);

		$this->makeDirectory($this->path);
		$this->write(Path::join($this->path, "required_item_list.json"), $table);
		$this->logger->info("Wrote required_item_list.json with " . count($table) . " item type(s)");
	}

	/**
	 * @throws DataExtractionException
	 */
	public function handleCreativeContent(CreativeContentPacket $packet) : void{
		$items = $this->requireItems();

		$groupItems = [];
		foreach($packet->getItems() as $entry){
			$groupItems[$entry->getGroupId()][] = $items->itemStack($entry->getItem());
		}

		$categories = [];
		foreach(Utils::promoteKeys($packet->getGroups()) as $groupId => $group){
			$category = self::CREATIVE_CATEGORIES[$group->getCategoryId()] ?? null;
			if($category === null){
				throw new DataExtractionException("Unknown creative category ID " . $group->getCategoryId());
			}
			$categories[$category][] = CanonicalJson::sort($this->creativeGroup($group, $groupItems[$groupId] ?? []));
		}

		$directory = Path::join($this->path, "creative");
		$this->makeDirectory($directory);
		foreach(Utils::promoteKeys($categories) as $category => $groups){
			$this->write(Path::join($directory, $category . ".json"), $groups);
			$this->logger->info("Wrote creative/$category.json with " . count($groups) . " group(s)");
		}
	}

	/**
	 * @param ItemStackData[]             $items
	 *
	 * @phpstan-param list<ItemStackData> $items
	 *
	 * @throws DataExtractionException
	 */
	private function creativeGroup(CreativeGroupEntry $entry, array $items) : CreativeGroupData{
		$data = new CreativeGroupData();
		$data->group_name = $entry->getCategoryName();
		$data->group_icon = $entry->getIcon()->getId() === 0 ? null : $this->requireItems()->itemStack($entry->getIcon());
		$data->items = $items;

		return $data;
	}

	/**
	 * @throws DataExtractionException
	 */
	public function handleCraftingData(CraftingDataPacket $packet) : void{
		$items = $this->requireItems();
		$recipes = [];

		foreach(self::RECIPE_FILES as $file => $property){
			foreach($packet->$property as $entry){
				if($entry instanceof ShapedRecipe){
					$recipes[$entry->isSymmetric() ? $file : $file . "_asymmetric"][] = $this->shaped($entry);
				}elseif($entry instanceof ShapelessRecipe){
					$recipes[$file][] = $this->shapeless($entry);
				}elseif($entry instanceof MultiRecipe){
					$recipes[$file][] = $entry->getRecipeId()->toString();
				}elseif($entry instanceof SmithingTransformRecipe){
					$recipes[$file][] = $this->smithing($entry);
				}elseif($entry instanceof SmithingTrimRecipe){
					$recipes[$file][] = $this->smithingTrim($entry);
				}else{
					throw new DataExtractionException("Unexpected recipe type " . get_class($entry));
				}
			}
		}

		foreach($packet->potionTypeRecipes as $recipe){
			$recipes["potion_type"][] = new PotionTypeRecipeData(
				$items->potionIngredient($recipe->getInputItemId(), $recipe->getInputItemMeta()),
				$items->potionIngredient($recipe->getIngredientItemId(), $recipe->getIngredientItemMeta()),
				$items->itemStack(new ItemStack($recipe->getOutputItemId(), $recipe->getOutputItemMeta(), 1, 0, ""))
			);
		}
		foreach($packet->potionContainerRecipes as $recipe){
			$recipes["potion_container_change"][] = new PotionContainerChangeRecipeData(
				$items->itemName($recipe->getInputItemId()),
				$items->potionIngredient($recipe->getIngredientItemId(), 0),
				$items->itemName($recipe->getOutputItemId())
			);
		}

		$directory = Path::join($this->path, "recipes");
		$this->makeDirectory($directory);
		ksort($recipes, SORT_STRING);
		foreach(Utils::promoteKeys($recipes) as $file => $entries){
			$sorted = CanonicalJson::sortEntries(array_values($entries));
			$this->write(Path::join($directory, $file . ".json"), $sorted);
			$this->logger->info("Wrote recipes/$file.json with " . count($sorted) . " recipe(s)");
		}
	}

	/**
	 * @throws DataExtractionException
	 */
	private function shaped(ShapedRecipe $recipe) : ShapedRecipeData{
		$items = $this->requireItems();
		$keysByIngredient = [];
		$ingredientsByKey = [];
		$shape = [];
		$nextKey = ord("A");

		foreach(Utils::promoteKeys($recipe->getInput()) as $row => $columns){
			foreach(Utils::promoteKeys($columns) as $column => $ingredient){
				if($ingredient->getDescriptor() === null){
					$shape[$row][$column] = " ";
					continue;
				}

				$data = $items->recipeIngredient($ingredient);
				try{
					$hash = json_encode($data, JSON_THROW_ON_ERROR);
				}catch(\JsonException $e){
					throw new DataExtractionException("Failed to encode recipe ingredient: " . $e->getMessage(), 0, $e);
				}

				if(isset($keysByIngredient[$hash])){
					$shape[$row][$column] = $keysByIngredient[$hash];
					continue;
				}
				if($nextKey >= 128){
					throw new DataExtractionException("Shaped recipe has too many distinct ingredients");
				}
				$key = chr($nextKey++);
				$keysByIngredient[$hash] = $shape[$row][$column] = $key;
				$ingredientsByKey[$key] = $data;
			}
		}

		return new ShapedRecipeData(
			array_map(static fn(array $columns) => implode("", array_values($columns)), array_values($shape)),
			$ingredientsByKey,
			array_map(fn(ItemStack $output) => $items->itemStack($output), $recipe->getOutput()),
			$recipe->getBlockName(),
			$recipe->getPriority(),
			$this->unlockingIngredients($recipe->getUnlockingRequirement()?->getUnlockingIngredients())
		);
	}

	/**
	 * @throws DataExtractionException
	 */
	private function shapeless(ShapelessRecipe $recipe) : ShapelessRecipeData{
		$items = $this->requireItems();

		return new ShapelessRecipeData(
			array_map(fn(RecipeIngredient $input) => $items->recipeIngredient($input), $recipe->getInputs()),
			array_map(fn(ItemStack $output) => $items->itemStack($output), $recipe->getOutputs()),
			$recipe->getBlockName(),
			$recipe->getPriority(),
			$this->unlockingIngredients($recipe->getUnlockingRequirement()?->getUnlockingIngredients())
		);
	}

	/**
	 * @throws DataExtractionException
	 */
	private function smithing(SmithingTransformRecipe $recipe) : SmithingTransformRecipeData{
		$items = $this->requireItems();

		return new SmithingTransformRecipeData(
			$items->recipeIngredient($recipe->getTemplate()),
			$items->recipeIngredient($recipe->getInput()),
			$items->recipeIngredient($recipe->getAddition()),
			$items->itemStack($recipe->getOutput()),
			$recipe->getBlockName()
		);
	}

	/**
	 * @throws DataExtractionException
	 */
	private function smithingTrim(SmithingTrimRecipe $recipe) : SmithingTrimRecipeData{
		$items = $this->requireItems();

		return new SmithingTrimRecipeData(
			$items->recipeIngredient($recipe->getTemplate()),
			$items->recipeIngredient($recipe->getInput()),
			$items->recipeIngredient($recipe->getAddition()),
			$recipe->getBlockName()
		);
	}

	/**
	 * @param RecipeIngredient[]|null             $ingredients
	 * @phpstan-param list<RecipeIngredient>|null $ingredients
	 *
	 * @return RecipeIngredientData[]
	 * @phpstan-return list<RecipeIngredientData>
	 *
	 * @throws DataExtractionException
	 */
	private function unlockingIngredients(?array $ingredients) : array{
		$items = $this->requireItems();

		return $ingredients === null ? [] : array_map(fn(RecipeIngredient $input) => $items->recipeIngredient($input), $ingredients);
	}

	/**
	 * @throws DataExtractionException
	 */
	private function requireItems() : ItemStackSerializer{
		return $this->items ?? throw new DataExtractionException("Destination item registry not received");
	}

	/**
	 * @throws DataExtractionException
	 */
	private function makeDirectory(string $directory) : void{
		if(!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)){
			throw new DataExtractionException("Failed to create directory $directory");
		}
	}

	/**
	 * @throws DataExtractionException
	 */
	private function write(string $file, mixed $contents) : void{
		try{
			$json = json_encode($contents, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
		}catch(\JsonException $e){
			throw new DataExtractionException("Failed to encode JSON for $file: " . $e->getMessage(), 0, $e);
		}
		if(@file_put_contents($file, $json . "\n") === false){
			throw new DataExtractionException("Failed to write $file");
		}
	}
}
