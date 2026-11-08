<?php

namespace Tests\Feature;

use App\Livewire\PublicSearch;
use App\Livewire\RecipeIndex;
use App\Models\Ingredient;
use App\Models\IngredientCatalogGroup;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Tag;
use App\Services\RecipePublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PublicSearchTest extends TestCase
{
    use RefreshDatabase;

    private function recipe(): Recipe
    {
        $recipe = Recipe::factory()->complete()->create();
        foreach ($recipe->categories as $category) {
            $category->update(['en' => ['name' => 'Lemon favourites', 'slug' => 'lemon-favourites-'.$category->id], 'el' => ['name' => 'Αγαπημένα λεμονιού', 'slug' => 'lemoni-'.$category->id]]);
        }
        $recipe->update(['en' => ['title' => 'Lemon chicken'], 'el' => ['title' => 'Κοτόπουλο λεμονάτο']]);

        return app(RecipePublishingService::class)->publish($recipe)->fresh();
    }

    public function test_search_matches_greek_without_accents_and_english_from_either_interface_language(): void
    {
        $recipe = $this->recipe();

        Livewire::test(PublicSearch::class, ['locale' => 'en'])->set('search', 'ΚΟΤΟΠΟΥΛΟ')->assertViewHas('suggestions', fn ($items) => collect($items)->contains('title', 'Lemon chicken'));
        Livewire::test(RecipeIndex::class, ['locale' => 'el'])->set('search', 'Lemon chicken')->assertViewHas('recipes', fn ($items) => $items->pluck('id')->all() === [$recipe->id]);
    }

    public function test_ingredient_aliases_return_both_ingredients_and_matching_recipes(): void
    {
        $recipe = $this->recipe();
        $group = IngredientCatalogGroup::factory()->create(['en' => ['name' => 'Vegetables'], 'el' => ['name' => 'Λαχανικά']]);
        $ingredient = Ingredient::factory()->create(['catalog_group_id' => $group->id, 'en' => ['name' => 'Courgette', 'slug' => 'courgette'], 'el' => ['name' => 'Κολοκύθι', 'slug' => 'kolokythi']]);
        $ingredient->aliases()->create(['locale' => 'en', 'alias' => 'Zucchini']);
        RecipeIngredient::factory()->create(['recipe_id' => $recipe->id, 'ingredient_id' => $ingredient->id]);

        Livewire::test(PublicSearch::class, ['locale' => 'el'])->set('search', 'Zucchini')
            ->assertViewHas('suggestions', fn ($items) => collect($items)->contains('kind', 'ingredient') && collect($items)->contains('kind', 'recipe'));
        Livewire::test(RecipeIndex::class, ['locale' => 'en'])->set('search', 'Zucchini')->assertViewHas('recipes', fn ($items) => $items->pluck('id')->all() === [$recipe->id]);
    }

    public function test_search_suggests_public_categories_and_editorial_tags_without_exposing_dietary_claim_tags(): void
    {
        $recipe = $this->recipe();
        $tag = Tag::factory()->create(['code' => 'lemon-cuisine', 'group_code' => 'cuisine', 'en' => ['name' => 'Lemon cuisine', 'slug' => 'lemon-cuisine'], 'el' => ['name' => 'Λεμόνι', 'slug' => 'lemoni']]);
        $recipe->tags()->attach($tag);
        $claim = Tag::factory()->create(['code' => 'lemon-vegan', 'classification' => 'diet_style', 'en' => ['name' => 'Lemon vegan', 'slug' => 'lemon-vegan']]);
        $recipe->tags()->attach($claim);

        Livewire::test(PublicSearch::class, ['locale' => 'en'])->set('search', 'Lemon')
            ->assertViewHas('suggestions', fn ($items) => collect($items)->contains('kind', 'category') && collect($items)->contains('kind', 'tag') && ! collect($items)->contains('title', 'Lemon vegan'));
    }

    public function test_search_never_suggests_private_pending_future_or_deleted_recipes(): void
    {
        $private = Recipe::factory()->complete()->create(['en' => ['title' => 'Secret lemon']]);
        $pending = Recipe::factory()->complete()->create(['status' => 'pending', 'en' => ['title' => 'Secret pending']]);
        $public = $this->recipe();
        $public->update(['published_at' => now()->addDay()]);
        $deleted = $this->recipe();
        $deleted->delete();

        Livewire::test(PublicSearch::class, ['locale' => 'en'])->set('search', 'lemon')->assertViewHas('suggestions', fn ($items) => collect($items)->where('kind', 'recipe')->isEmpty());
        Livewire::test(PublicSearch::class, ['locale' => 'en'])->set('search', 'secret')->assertViewHas('suggestions', fn ($items) => collect($items)->where('kind', 'recipe')->isEmpty());
    }

    public function test_search_requires_two_characters_and_rejects_oversized_input(): void
    {
        $this->recipe();

        Livewire::test(PublicSearch::class, ['locale' => 'en'])->set('search', 'L')->assertViewHas('suggestions', [])
            ->set('search', str_repeat('a', 101))->assertHasErrors(['search']);
    }

    public function test_like_wildcards_are_literal_and_do_not_match_every_recipe(): void
    {
        $this->recipe();

        Livewire::test(PublicSearch::class, ['locale' => 'en'])->set('search', '%%')->assertViewHas('suggestions', []);
    }

    public function test_cuisine_and_seasonal_shortcuts_filter_the_existing_catalogue(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 3));
        $local = $this->recipe();
        $world = $this->recipe();
        $greek = Tag::factory()->create(['code' => 'greek', 'group_code' => 'cuisine', 'classification' => 'cuisine']);
        $italian = Tag::factory()->create(['code' => 'italian', 'group_code' => 'cuisine', 'classification' => 'cuisine']);
        $autumn = Tag::factory()->create(['code' => 'autumn', 'group_code' => 'season']);
        $local->tags()->attach([$greek->id, $autumn->id]);
        $world->tags()->attach($italian->id);

        Livewire::test(RecipeIndex::class, ['locale' => 'en'])->set('collection', 'local')
            ->assertViewHas('recipes', fn ($items) => $items->pluck('id')->all() === [$local->id])
            ->set('collection', 'world')->assertViewHas('recipes', fn ($items) => $items->pluck('id')->all() === [$world->id])
            ->set('collection', 'seasonal')->assertViewHas('recipes', fn ($items) => $items->pluck('id')->all() === [$local->id]);
    }
}
