<?php

namespace Database\Factories;

use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingListItem>
 */
class ShoppingListItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shopping_list_id' => ShoppingList::factory(),
            'custom_name' => fake()->word(),
            'is_manual' => true,
        ];
    }
}
