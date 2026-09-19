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

final class PotionTypeRecipeData{
	/** @required */
	public RecipeIngredientData $input;

	/** @required */
	public RecipeIngredientData $ingredient;

	/** @required */
	public ItemStackData $output;

	public function __construct(RecipeIngredientData $input, RecipeIngredientData $ingredient, ItemStackData $output){
		$this->input = $input;
		$this->ingredient = $ingredient;
		$this->output = $output;
	}
}
