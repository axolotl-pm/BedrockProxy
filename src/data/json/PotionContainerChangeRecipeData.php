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

namespace pocketmine\bedrockproxy\data\json;

final class PotionContainerChangeRecipeData{
	/** @required */
	public string $input_item_name;

	/** @required */
	public RecipeIngredientData $ingredient;

	/** @required */
	public string $output_item_name;

	public function __construct(string $input_item_name, RecipeIngredientData $ingredient, string $output_item_name){
		$this->input_item_name = $input_item_name;
		$this->ingredient = $ingredient;
		$this->output_item_name = $output_item_name;
	}
}
