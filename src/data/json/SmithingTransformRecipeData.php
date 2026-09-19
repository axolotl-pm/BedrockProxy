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

final class SmithingTransformRecipeData{

	/** @required */
	public RecipeIngredientData $template;
	/** @required */
	public RecipeIngredientData $input;
	/** @required */
	public RecipeIngredientData $addition;
	/** @required */
	public ItemStackData $output;
	/** @required */
	public string $block;

	public function __construct(RecipeIngredientData $template, RecipeIngredientData $input, RecipeIngredientData $addition, ItemStackData $output, string $block){
		$this->template = $template;
		$this->input = $input;
		$this->addition = $addition;
		$this->output = $output;
		$this->block = $block;
	}
}
