<?php

namespace Tests\Feature;

use App\Livewire\MealPlanner;
use App\Livewire\WhatCanICook;
use App\Models\Category;
use App\Models\Difficulty;
use App\Models\Ingredient;
use App\Models\IngredientSubstitution;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingList;
use App\Models\User;
use App\Services\ShoppingListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class WhatCanICookTest extends TestCase
{
    use RefreshDatabase;

    public function test_localized_page_renders_in_english_and_greek(): void
    {
        $this->get('/en/what-can-i-cook')
            ->assertOk()
            ->assertSee('What Can I Cook?');

        $this->get('/el/what-can-i-cook')
            ->assertOk()
            ->assertSee('Τι να μαγειρέψω;');
    }

    public function test_ingredient_search_maps_english_and_greek_names_to_the_same_id(): void
    {
        $ingredient = $this->ingredient('Flour', 'Αλεύρι');
        $this->publicRecipe('Flour loaf', [$ingredient]);

        Livewire::test(WhatCanICook::class, ['locale' => 'en'])
            ->set('ingredientSearch', 'Flour')
            ->assertSee('Flour')
            ->call('selectIngredient', $ingredient->getKey())
            ->assertSet('selectedIngredientIds', [$ingredient->getKey()]);

        Livewire::test(WhatCanICook::class, ['locale' => 'el'])
            ->set('ingredientSearch', 'Αλεύρι')
            ->assertSee('Αλεύρι')
            ->call('selectIngredient', $ingredient->getKey())
            ->assertSet('selectedIngredientIds', [$ingredient->getKey()]);
    }

    public function test_matches_count_distinct_required_ingredients_ignore_optional_lines_and_sort_by_coverage(): void
    {
        $flour = $this->ingredient('Flour', 'Αλεύρι');
        $sugar = $this->ingredient('Sugar', 'Ζάχαρη');
        $egg = $this->ingredient('Egg', 'Αυγό');
        $butter = $this->ingredient('Butter', 'Βούτυρο');
        $salt = $this->ingredient('Salt', 'Αλάτι');

        $this->publicRecipe('Complete match', [$flour, $flour, $sugar], [$salt]);
        $this->publicRecipe('Higher coverage', [$flour, $sugar, $egg]);
        $this->publicRecipe('Lower coverage', [$flour, $egg]);
        $this->publicRecipe('More missing', [$flour, $sugar, $egg, $butter]);

        $component = Livewire::test(WhatCanICook::class, ['locale' => 'en'])
            ->set('selectedIngredientIds', [$flour->getKey(), $sugar->getKey(), $sugar->getKey()])
            ->assertSee('2 of 2 required ingredients')
            ->assertSee('You have every required ingredient.')
            ->assertSee('Egg');

        $html = $component->html();
        $this->assertLessThan(strpos($html, 'Lower coverage'), strpos($html, 'Higher coverage'));
        $this->assertLessThan(strpos($html, 'More missing'), strpos($html, 'Lower coverage'));

        $component->set('onlyComplete', true)
            ->assertSee('Complete match')
            ->assertDontSee('Higher coverage')
            ->assertDontSee('Lower coverage')
            ->assertDontSee('More missing');
    }

    public function test_only_approved_substitutions_make_a_recipe_a_possible_match(): void
    {
        $original = $this->ingredient('Yogurt', 'Γιαούρτι');
        $approvedAlternative = $this->ingredient('Sour cream', 'Ξινή κρέμα');
        $pendingAlternative = $this->ingredient('Mayonnaise', 'Μαγιονέζα');
        $possibleRecipe = $this->publicRecipe('Possible with substitution', [$original]);
        $pendingRecipe = $this->publicRecipe('Pending substitution only', [$this->ingredient('Milk', 'Γάλα')]);

        $this->substitution($original, $approvedAlternative, $possibleRecipe, 'Use instead.', 'Χρησιμοποιήστε το αντί αυτού.');
        $this->substitution($pendingRecipe->recipeIngredients()->firstOrFail()->ingredient, $pendingAlternative, $pendingRecipe, 'Pending.', 'Σε αναμονή.', 'pending');

        Livewire::test(WhatCanICook::class, ['locale' => 'en'])
            ->set('selectedIngredientIds', [$approvedAlternative->getKey(), $pendingAlternative->getKey()])
            ->assertSee('Possible with substitution')
            ->assertSee('Possible with an approved substitution')
            ->assertDontSee('Pending substitution only')
            ->assertDontSee('Mayonnaise');
    }

    public function test_confirmed_substitution_is_saved_to_the_planned_entry_without_changing_the_recipe(): void
    {
        $user = User::factory()->create();
        $original = $this->ingredient('Yogurt', 'Γιαούρτι');
        $alternative = $this->ingredient('Sour cream', 'Ξινή κρέμα');
        $recipe = $this->publicRecipe('Planned substitution recipe', [$original]);
        $line = $recipe->recipeIngredients()->firstOrFail();
        $substitution = $this->substitution($original, $alternative, $recipe, 'Use instead.', 'Χρησιμοποιήστε το αντί αυτού.');

        Livewire::actingAs($user)->test(WhatCanICook::class, ['locale' => 'en'])
            ->set('selectedIngredientIds', [$alternative->getKey()])
            ->call('confirmSubstitution', $recipe->getKey(), $original->getKey(), $substitution->getKey())
            ->assertSee('1 of 1 required ingredients')
            ->assertSee('Possible with an approved substitution')
            ->call('addToMealPlan', $recipe->getKey())
            ->assertHasNoErrors();

        $this->assertDatabaseHas('meal_plan_entry_substitutions', [
            'recipe_id' => $recipe->getKey(),
            'recipe_ingredient_id' => $line->getKey(),
            'ingredient_substitution_id' => $substitution->getKey(),
            'replacement_ingredient_id' => $alternative->getKey(),
            'confirmed_by' => $user->getKey(),
        ]);
        $this->assertSame($original->getKey(), $line->fresh()->ingredient_id);
        $this->assertDatabaseHas('meal_plan_entries', [
            'recipe_id' => $recipe->getKey(),
            'servings' => max(1, (int) ($recipe->base_servings ?: 4)),
        ]);

        Livewire::actingAs($user)->test(MealPlanner::class, ['locale' => 'en'])
            ->assertSee('Yogurt')
            ->assertSee('Sour cream');
    }

    public function test_unapproved_or_cross_recipe_substitutions_cannot_be_confirmed(): void
    {
        $original = $this->ingredient('Yogurt', 'Γιαούρτι');
        $alternative = $this->ingredient('Sour cream', 'Ξινή κρέμα');
        $recipe = $this->publicRecipe('Confirmation target', [$original]);
        $otherRecipe = $this->publicRecipe('Other recipe', [$original]);
        $wrongRecipeSubstitution = $this->substitution($original, $alternative, $otherRecipe, 'Only for other recipe.', 'Μόνο για άλλη συνταγή.');
        $pendingSubstitution = $this->substitution($original, $alternative, $recipe, 'Pending.', 'Σε αναμονή.', 'pending');

        Livewire::test(WhatCanICook::class, ['locale' => 'en'])
            ->set('selectedIngredientIds', [$alternative->getKey()])
            ->call('confirmSubstitution', $recipe->getKey(), $original->getKey(), $wrongRecipeSubstitution->getKey())
            ->assertNotFound();

        Livewire::test(WhatCanICook::class, ['locale' => 'en'])
            ->set('selectedIngredientIds', [$alternative->getKey()])
            ->call('confirmSubstitution', $recipe->getKey(), $original->getKey(), $pendingSubstitution->getKey())
            ->assertNotFound();
    }

    public function test_confirmed_substitution_adds_only_the_replacement_to_the_existing_list(): void
    {
        $user = User::factory()->create();
        $original = $this->ingredient('Yogurt', 'Γιαούρτι');
        $alternative = $this->ingredient('Sour cream', 'Ξινή κρέμα');
        $salt = $this->ingredient('Salt', 'Αλάτι');
        $recipe = $this->publicRecipe('Shopping substitution recipe', [$original]);
        $this->publicRecipe('Salt recipe', [$salt]);
        $substitution = $this->substitution($original, $alternative, $recipe, 'Use instead.', 'Χρησιμοποιήστε το αντί αυτού.');
        $plan = MealPlan::factory()->create([
            'created_by' => $user->getKey(),
            'week_start' => MealPlan::weekStartFor(now())->toDateString(),
        ]);
        $list = ShoppingList::factory()->create(['meal_plan_id' => $plan->getKey()]);

        Livewire::actingAs($user)->test(WhatCanICook::class, ['locale' => 'en'])
            ->set('selectedIngredientIds', [$alternative->getKey(), $salt->getKey()])
            ->call('confirmSubstitution', $recipe->getKey(), $original->getKey(), $substitution->getKey())
            ->set('selectedIngredientIds', [$salt->getKey()])
            ->call('addMissingIngredients', $recipe->getKey())
            ->assertHasNoErrors()
            ->call('addMissingIngredients', $recipe->getKey())
            ->assertHasNoErrors();

        $this->assertDatabaseHas('shopping_list_items', [
            'shopping_list_id' => $list->getKey(),
            'ingredient_id' => $alternative->getKey(),
            'quantity' => null,
            'is_manual' => true,
        ]);
        $this->assertDatabaseMissing('shopping_list_items', [
            'shopping_list_id' => $list->getKey(),
            'ingredient_id' => $original->getKey(),
        ]);
        $this->assertSame(1, $list->items()->count());
    }

    public function test_private_recipe_and_its_exclusive_ingredients_are_not_exposed(): void
    {
        $publicIngredient = $this->ingredient('Tomato', 'Ντομάτα');
        $privateIngredient = $this->ingredient('Private truffle', 'Ιδιωτική τρούφα');
        $privateAlternative = $this->ingredient('Private-only substitute', 'Ιδιωτική εναλλακτική');
        $this->publicRecipe('Public tomato soup', [$publicIngredient]);

        $privateRecipe = Recipe::factory()->create(['status' => 'draft', 'visibility' => 'private']);
        $privateRecipe->fill([
            'en' => ['title' => 'Private family recipe', 'slug' => 'private-family-recipe'],
            'el' => ['title' => 'Ιδιωτική οικογενειακή συνταγή', 'slug' => 'idiotiki-oikogeneiaki-syntagi'],
        ])->save();
        $this->addRecipeIngredients($privateRecipe, [$publicIngredient, $privateIngredient]);
        IngredientSubstitution::factory()->approved()->create([
            'ingredient_id' => $privateIngredient->getKey(),
            'replacement_ingredient_id' => $privateAlternative->getKey(),
        ]);

        Livewire::test(WhatCanICook::class, ['locale' => 'en'])
            ->set('ingredientSearch', 'Private truffle')
            ->assertDontSee('Private truffle')
            ->set('ingredientSearch', 'Tomato')
            ->call('selectIngredient', $publicIngredient->getKey())
            ->assertSee('Public tomato soup')
            ->assertDontSee('Private family recipe')
            ->assertDontSee('Private truffle');

        Livewire::test(WhatCanICook::class, ['locale' => 'en'])
            ->set('ingredientSearch', 'Private-only substitute')
            ->assertDontSee('Private-only substitute');

        Livewire::test(WhatCanICook::class, ['locale' => 'en'])
            ->set('selectedIngredientIds', [$privateIngredient->getKey()])
            ->assertDontSee('Private truffle')
            ->assertDontSee('Private family recipe');
    }

    public function test_category_difficulty_and_maximum_time_filters_apply_to_public_matches(): void
    {
        $flour = $this->ingredient('Flour', 'Αλεύρι');
        $category = $this->category('Baking', 'Αρτοποιία');
        $otherCategory = $this->category('Dessert', 'Επιδόρπιο');
        Difficulty::factory()->create(['code' => 'Hard']);

        $target = $this->publicRecipe('Filtered recipe', [$flour], [], [
            'prep_minutes' => 10,
            'cook_minutes' => 20,
            'difficulty' => 'Easy',
        ]);
        $target->categories()->attach($category);

        $wrongCategory = $this->publicRecipe('Other category recipe', [$flour], [], [
            'prep_minutes' => 10,
            'cook_minutes' => 15,
        ]);
        $wrongCategory->categories()->attach($otherCategory);

        $wrongDifficulty = $this->publicRecipe('Other difficulty recipe', [$flour], [], [
            'prep_minutes' => 10,
            'cook_minutes' => 15,
            'difficulty' => 'Hard',
        ]);
        $wrongDifficulty->categories()->attach($category);

        $tooSlow = $this->publicRecipe('Too slow recipe', [$flour], [], [
            'prep_minutes' => 20,
            'cook_minutes' => 20,
        ]);
        $tooSlow->categories()->attach($category);

        Livewire::test(WhatCanICook::class, ['locale' => 'en'])
            ->set('selectedIngredientIds', [$flour->getKey()])
            ->set('category', (string) $category->getKey())
            ->set('difficulty', 'Easy')
            ->set('maximumMinutes', '30')
            ->assertSee('Filtered recipe')
            ->assertDontSee('Other category recipe')
            ->assertDontSee('Other difficulty recipe')
            ->assertDontSee('Too slow recipe');
    }

    public function test_missing_required_ingredients_are_added_to_an_existing_personal_list_without_quantities(): void
    {
        $user = User::factory()->create();
        $flour = $this->ingredient('Flour', 'Αλεύρι');
        $sugar = $this->ingredient('Sugar', 'Ζάχαρη');
        $salt = $this->ingredient('Salt', 'Αλάτι');
        $recipe = $this->publicRecipe('Shopping recipe', [$flour, $sugar], [$salt]);
        $plan = MealPlan::factory()->create([
            'created_by' => $user->getKey(),
            'week_start' => MealPlan::weekStartFor(now())->toDateString(),
        ]);
        $list = ShoppingList::factory()->create(['meal_plan_id' => $plan->getKey()]);

        $this->actingAs($user);

        $component = Livewire::test(WhatCanICook::class, ['locale' => 'en'])
            ->set('selectedIngredientIds', [$flour->getKey()])
            ->call('addMissingIngredients', $recipe->getKey())
            ->assertHasNoErrors();
        $component->call('addMissingIngredients', $recipe->getKey())
            ->assertHasNoErrors();

        $this->assertDatabaseHas('shopping_list_items', [
            'shopping_list_id' => $list->getKey(),
            'ingredient_id' => $sugar->getKey(),
            'quantity' => null,
            'unit_id' => null,
            'is_manual' => true,
        ]);
        $this->assertDatabaseMissing('shopping_list_items', [
            'shopping_list_id' => $list->getKey(),
            'ingredient_id' => $salt->getKey(),
        ]);
        $this->assertSame(1, $list->items()->count());
        $this->assertSame(
            ['Sugar'],
            app(ShoppingListService::class)->itemsView($user, $list, 'en')->pluck('name')->all(),
        );
    }

    /**
     * @param  list<Ingredient>  $required
     * @param  list<Ingredient>  $optional
     * @param  array<string, mixed>  $attributes
     */
    private function publicRecipe(string $title, array $required, array $optional = [], array $attributes = []): Recipe
    {
        $recipe = Recipe::factory()->published()->create($attributes);
        $recipe->translations()->where('locale', 'en')->update([
            'title' => $title,
            'slug' => Str::slug($title).'-'.$recipe->getKey(),
        ]);

        $this->addRecipeIngredients($recipe, $required);
        $this->addRecipeIngredients($recipe, $optional, true);

        return $recipe;
    }

    /**
     * @param  list<Ingredient>  $ingredients
     */
    private function addRecipeIngredients(Recipe $recipe, array $ingredients, bool $optional = false): void
    {
        foreach ($ingredients as $ingredient) {
            RecipeIngredient::factory()->create([
                'recipe_id' => $recipe->getKey(),
                'ingredient_id' => $ingredient->getKey(),
                'is_optional' => $optional,
            ]);
        }
    }

    private function ingredient(string $englishName, string $greekName): Ingredient
    {
        $ingredient = Ingredient::factory()->create();
        $ingredient->fill([
            'en' => ['name' => $englishName],
            'el' => ['name' => $greekName],
        ])->save();

        return $ingredient->fresh();
    }

    private function category(string $englishName, string $greekName): Category
    {
        $category = Category::factory()->create();
        $category->fill([
            'en' => ['name' => $englishName, 'slug' => Str::slug($englishName).'-'.$category->getKey()],
            'el' => ['name' => $greekName, 'slug' => 'category-'.$category->getKey()],
        ])->save();

        return $category;
    }

    private function substitution(
        Ingredient $original,
        Ingredient $alternative,
        Recipe $recipe,
        string $englishExplanation,
        string $greekExplanation,
        string $status = 'approved',
    ): IngredientSubstitution {
        $substitution = IngredientSubstitution::factory()->create([
            'ingredient_id' => $original->getKey(),
            'replacement_ingredient_id' => $alternative->getKey(),
            'recipe_id' => $recipe->getKey(),
            'status' => $status,
        ]);
        $substitution->fill([
            'en' => ['explanation' => $englishExplanation],
            'el' => ['explanation' => $greekExplanation],
        ])->save();

        return $substitution;
    }
}
