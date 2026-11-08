<?php

namespace Tests\Feature;

use App\Livewire\RecipeIndex;
use App\Models\Ingredient;
use App\Models\IngredientCatalogGroup;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeRating;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function ingredient(string $code): Ingredient
    {
        $group = IngredientCatalogGroup::factory()->create(['en' => ['name' => 'Vegetables'], 'el' => ['name' => 'Λαχανικά']]);

        return Ingredient::factory()->create(['code' => $code, 'catalog_group_id' => $group->id,
            'en' => ['name' => ucfirst($code), 'slug' => $code], 'el' => ['name' => 'Υλικό '.$code, 'slug' => $code.'-el']]);
    }

    private function recipe(array $ingredients = [], array $attributes = []): Recipe
    {
        $recipe = Recipe::factory()->published()->create($attributes);
        foreach ($ingredients as $ingredient) {
            RecipeIngredient::factory()->create(['recipe_id' => $recipe->id, 'ingredient_id' => $ingredient->id]);
        }

        return $recipe;
    }

    private function rating(Recipe $recipe, int $rating, string $status = 'active'): void
    {
        (new RecipeRating)->forceFill(['recipe_id' => $recipe->id, 'user_id' => User::factory()->create()->id,
            'rating' => $rating, 'rating_status' => $status, 'review_status' => 'pending', 'moderation_reason' => $status === 'removed' ? 'Removed by moderator' : null])->save();
    }

    public function test_ingredient_cuisine_method_and_time_filters_intersect_without_exposing_private_recipes(): void
    {
        $tomato = $this->ingredient('tomato');
        $onion = $this->ingredient('onion');
        $match = $this->recipe([$tomato, $onion], ['prep_minutes' => 10, 'cook_minutes' => 10]);
        $this->recipe([$tomato]);
        $private = $this->recipe([$tomato, $onion], ['visibility' => 'private']);
        $cuisine = Tag::factory()->create(['code' => 'greek', 'group_code' => 'cuisine', 'classification' => 'cuisine']);
        $method = Tag::factory()->create(['code' => 'roasting', 'group_code' => 'cooking_method']);
        $match->tags()->attach([$cuisine->id, $method->id]);
        $private->tags()->attach([$cuisine->id, $method->id]);

        Livewire::test(RecipeIndex::class, ['locale' => 'en'])->set('ingredients', ['tomato', 'onion'])
            ->set('cuisine', 'greek')->set('method', 'roasting')->set('time', '30')
            ->assertViewHas('recipes', fn ($items) => $items->modelKeys() === [$match->id])
            ->set('method', 'unknown')->assertViewHas('recipes', fn ($items) => $items->isEmpty())
            ->call('clearFilters')->assertSet('ingredients', [])->assertSet('cuisine', '')->assertSet('method', '')
            ->assertViewHas('recipes', fn ($items) => $items->total() === 2);
    }

    public function test_ratings_sort_and_minimum_use_active_votes_and_leave_unrated_recipes_last(): void
    {
        $high = $this->recipe();
        $low = $this->recipe();
        $unrated = $this->recipe();
        $this->rating($high, 5);
        $this->rating($high, 1, 'removed');
        $this->rating($low, 3);
        Livewire::test(RecipeIndex::class, ['locale' => 'en'])->set('sort', 'rating')
            ->assertViewHas('recipes', fn ($items) => $items->modelKeys() === [$high->id, $low->id, $unrated->id])
            ->set('rating', '4')->assertViewHas('recipes', fn ($items) => $items->modelKeys() === [$high->id] && (float) $items->first()->public_rating_average === 5.0);
    }

    public function test_dietary_association_alone_never_qualifies_as_a_verified_filter(): void
    {
        config(['dietary.public_release_enabled' => true, 'dietary.public_claim_codes' => ['vegetarian']]);
        $tag = Tag::factory()->dietary()->create(['code' => 'vegetarian']);
        $this->recipe()->tags()->attach($tag);
        Livewire::test(RecipeIndex::class, ['locale' => 'en'])->set('dietary', 'vegetarian')
            ->assertViewHas('recipes', fn ($items) => $items->isEmpty())->assertViewHas('recipeClaims', []);
    }

    public function test_ingredient_suggestions_only_include_public_catalogue_items_used_in_public_recipes(): void
    {
        $visible = $this->ingredient('tomato');
        $private = $this->ingredient('tomatillo');
        $unused = $this->ingredient('tomato-unused');
        $this->recipe([$visible]);
        $this->recipe([$private], ['visibility' => 'private']);
        Livewire::test(RecipeIndex::class, ['locale' => 'en'])->set('ingredientSearch', 'tomat')
            ->assertViewHas('ingredientResults', fn ($items) => $items->modelKeys() === [$visible->id])
            ->call('selectIngredient', $visible->code)->assertSet('ingredients', ['tomato'])->assertSet('ingredientSearch', '');
        Livewire::test(RecipeIndex::class, ['locale' => 'en'])->call('selectIngredient', $unused->code)->assertNotFound();
    }

    public function test_invalid_filter_shapes_and_limits_are_rejected(): void
    {
        $this->getJson('/en?ingredients=tomato')->assertUnprocessable();
        $this->getJson('/en?sort=invalid')->assertUnprocessable();
        $this->getJson('/en?rating=6')->assertUnprocessable();
        Livewire::test(RecipeIndex::class, ['locale' => 'en'])->set('ingredients', ['a', 'b', 'c', 'd', 'e', 'f'])->assertHasErrors('ingredients');
        Livewire::test(RecipeIndex::class, ['locale' => 'en'])->set('ingredients', ['a', 'a'])->assertHasErrors('ingredients.0');
    }
}
