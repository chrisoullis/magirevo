<?php

namespace Tests\Feature;

use App\Enums\MealType;
use App\Livewire\MealPlanner;
use App\Livewire\RecipeServings;
use App\Livewire\ShoppingListPage;
use App\Livewire\WhatCanICook;
use App\Models\Ingredient;
use App\Models\IngredientCatalogGroup;
use App\Models\IngredientSubstitution;
use App\Models\MealPlan;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingList;
use App\Models\User;
use App\Services\IngredientImageService;
use App\Services\MealPlanService;
use App\Services\RecipeContentPresenter;
use App\Services\ShoppingListService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class IngredientEncyclopediaIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_recipe_ingredient_links_follow_the_requested_language_and_preserve_scaling(): void
    {
        $ingredient = $this->ingredient('zucchini');
        $recipe = $this->recipe($ingredient);
        $line = $recipe->recipeIngredients()->firstOrFail();
        $before = $line->getAttributes();
        Livewire::test(RecipeServings::class, ['recipeId' => $recipe->id, 'locale' => 'el', 'baseServings' => 2])
            ->assertSee('href="/el/ingredients/zucchini-el"', false)->call('increase')
            ->assertViewHas('groups', fn ($groups) => $groups[0]['items'][0]['quantity'] === '3');
        $this->assertSame($before, $line->fresh()->getAttributes());
        $this->assertDatabaseCount('recipe_dietary_claims', 0);
        $ingredient->catalogGroup->update(['is_active' => false]);
        Livewire::test(RecipeServings::class, ['recipeId' => $recipe->id, 'locale' => 'el', 'baseServings' => 2])
            ->assertDontSee('/el/ingredients/zucchini-el');
    }

    public function test_recipe_photo_uses_only_authorized_catalogue_media(): void
    {
        Storage::fake('local');
        $ingredient = $this->ingredient('tomato');
        $recipe = $this->recipe($ingredient);
        $photo = app(IngredientImageService::class)->upload(User::factory()->admin()->create(), $ingredient, UploadedFile::fake()->image('tomato.png', 800, 600), [
            'role' => 'primary', 'rights_basis' => 'own_work', 'rights_reference' => 'Original photo',
            'alt_text_en' => 'Tomato', 'alt_text_el' => 'Ντομάτα',
        ]);
        app()->setLocale('en');
        $presenter = app(RecipeContentPresenter::class);
        $this->assertSame($photo->publicUrl('thumbnail'), $presenter->ingredientGroups($recipe)[0]['items'][0]['image_url']);
        $photo->media->translations()->where('locale', 'el')->delete();
        $this->assertNull($presenter->ingredientGroups($recipe)[0]['items'][0]['image_url']);
    }

    public function test_alias_search_in_cooking_preserves_recipe_visibility_and_selection_rules(): void
    {
        $visible = $this->ingredient('zucchini');
        $visible->aliases()->createMany([['locale' => 'en', 'alias' => 'Courgette'], ['locale' => 'el', 'alias' => 'Κολοκυθάκι']]);
        $hidden = $this->ingredient('private-only');
        $hidden->aliases()->create(['locale' => 'en', 'alias' => 'Secret courgette']);
        $this->recipe($visible);
        $this->recipe($hidden, false);
        Livewire::test(WhatCanICook::class, ['locale' => 'en'])->set('ingredientSearch', 'Courgette')
            ->assertViewHas('ingredientOptions', fn ($items) => $items->pluck('id')->all() === [$visible->id])
            ->call('selectIngredient', $visible->id)->assertSee('href="/en/ingredients/zucchini"', false)
            ->set('ingredientSearch', 'κολοκυθακι')->call('removeIngredient', $visible->id)
            ->assertViewHas('ingredientOptions', fn ($items) => $items->pluck('id')->all() === [$visible->id])
            ->set('ingredientSearch', 'Secret courgette')->assertViewHas('ingredientOptions', fn ($items) => $items->isEmpty());
    }

    public function test_encyclopedia_cooking_link_preselects_only_an_available_ingredient(): void
    {
        $ingredient = $this->ingredient('tomato');
        $this->recipe($ingredient);
        $this->get('/en/ingredients/tomato')->assertOk()->assertSee('/en/what-can-i-cook?ingredient=tomato');
        $this->get('/en/what-can-i-cook?ingredient=tomato')->assertOk()->assertSee('href="/en/ingredients/tomato"', false);
        $hidden = $this->ingredient('secret');
        $this->recipe($hidden, false);
        $this->get('/en/what-can-i-cook?ingredient=secret')->assertOk()->assertDontSee('/en/ingredients/secret');
        $this->get('/en/what-can-i-cook?ingredient[]=tomato')->assertOk();
    }

    public function test_approved_substitution_links_do_not_make_private_personal_changes_public(): void
    {
        $original = $this->ingredient('yogurt');
        $replacement = $this->ingredient('sour-cream');
        $personal = $this->ingredient('personal-secret');
        $recipe = $this->recipe($original);
        IngredientSubstitution::factory()->approved()->create(['ingredient_id' => $original->id, 'replacement_ingredient_id' => $replacement->id]);
        $member = User::factory()->create();
        $member->recipeIngredientChanges()->create(['recipe_id' => $recipe->id, 'recipe_ingredient_id' => $recipe->recipeIngredients()->first()->id, 'alternative_ingredient_id' => $personal->id, 'note' => 'Private change']);
        Livewire::actingAs($member)->test(RecipeServings::class, ['recipeId' => $recipe->id, 'locale' => 'en', 'baseServings' => 2])
            ->assertSee('/en/ingredients/sour-cream')->assertSee('/en/ingredients/personal-secret');
        auth()->logout();
        Livewire::test(RecipeServings::class, ['recipeId' => $recipe->id, 'locale' => 'en', 'baseServings' => 2])
            ->assertSee('/en/ingredients/sour-cream')->assertDontSee('/en/ingredients/personal-secret')->assertDontSee('Private change');
        $this->assertDatabaseCount('recipe_dietary_claims', 0);
    }

    public function test_personal_substitution_selector_uses_aliases_without_publishing_the_choice(): void
    {
        $original = $this->ingredient('tomato');
        $alternative = $this->ingredient('zucchini');
        $alternative->aliases()->create(['locale' => 'en', 'alias' => 'Courgette']);
        $recipe = $this->recipe($original);
        $line = $recipe->recipeIngredients()->firstOrFail();
        Livewire::actingAs(User::factory()->create())->test(RecipeServings::class, ['recipeId' => $recipe->id, 'locale' => 'en', 'baseServings' => 2])
            ->call('editPersonalChange', $line->id)->set('ingredientSearch', 'Courgette')
            ->assertViewHas('ingredientOptions', fn ($items) => array_keys($items) === [$alternative->id]);
        $this->assertDatabaseCount('user_recipe_ingredient_changes', 0);
    }

    public function test_shopping_links_use_explicit_ingredient_ids_and_preserve_item_state(): void
    {
        $owner = User::factory()->create();
        $ingredient = $this->ingredient('tomato');
        $plan = MealPlan::factory()->create(['created_by' => $owner->id]);
        $list = ShoppingList::factory()->create(['meal_plan_id' => $plan->id]);
        $linked = $list->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => '2.5', 'is_manual' => true]);
        $manual = $list->items()->create(['custom_name' => 'tomato', 'is_manual' => true]);
        $before = $linked->fresh()->getAttributes();
        $service = app(ShoppingListService::class);
        $items = $service->itemsView($owner, $list, 'el')->keyBy('id');
        $this->assertSame('/el/ingredients/tomato-el', $items[$linked->id]['ingredient_url']);
        $this->assertNull($items[$manual->id]['ingredient_url']);
        $this->assertSame($before, $linked->fresh()->getAttributes());
        Livewire::actingAs($owner)->test(ShoppingListPage::class, ['locale' => 'el'])->assertSee('/el/ingredients/tomato-el');
        $ingredient->update(['is_active' => false]);
        $this->assertNull($service->itemsView($owner, $list, 'en')->keyBy('id')[$linked->id]['ingredient_url']);
        $this->expectException(AuthorizationException::class);
        $service->itemsView(User::factory()->create(), $list, 'en');
    }

    public function test_meal_plan_links_hide_unavailable_recipe_ingredients_and_substitutions(): void
    {
        $owner = User::factory()->create();
        $ingredient = $this->ingredient('tomato');
        $replacement = $this->ingredient('zucchini');
        $recipe = $this->recipe($ingredient);
        $secret = $this->ingredient('private-secret');
        $unavailable = $this->recipe($secret, false);
        $plan = MealPlan::factory()->create(['created_by' => $owner->id]);
        $substitution = IngredientSubstitution::factory()->approved()->create(['ingredient_id' => $ingredient->id, 'replacement_ingredient_id' => $replacement->id]);
        $service = app(MealPlanService::class);
        $entry = $service->addEntry($owner, $plan, $recipe, $plan->week_start, MealType::Dinner, 2, confirmedSubstitutions: [$ingredient->id => ['substitution_id' => $substitution->id, 'replacement_ingredient_id' => $replacement->id]]);
        $hidden = MealPlanEntry::factory()->create(['meal_plan_id' => $plan->id, 'recipe_id' => $unavailable->id]);
        $view = $service->weekView($owner, $plan, 'el')->flatMap(fn ($day) => $day->flatten(1))->keyBy('id');
        $this->assertSame('/el/ingredients/tomato-el', $view[$entry->id]['ingredients'][0]['url']);
        $this->assertSame('/el/ingredients/zucchini-el', $view[$entry->id]['substitutions'][0]['replacement_url']);
        $this->assertSame([], $view[$hidden->id]['ingredients']);
        $this->assertSame([], $view[$hidden->id]['substitutions']);
        Livewire::actingAs($owner)->test(MealPlanner::class, ['locale' => 'el'])->assertSee('/el/ingredients/tomato-el')->assertSee('/el/ingredients/zucchini-el')->assertDontSee('private-secret');
        $substitution->update(['status' => 'pending']);
        $view = $service->weekView($owner, $plan, 'en')->flatMap(fn ($day) => $day->flatten(1))->keyBy('id');
        $this->assertSame([], $view[$entry->id]['substitutions']);
        $this->assertDatabaseCount('recipe_dietary_claims', 0);
    }

    public function test_recipe_link_queries_remain_bounded_as_ingredient_lines_grow(): void
    {
        $ingredient = $this->ingredient('tomato');
        $recipe = $this->recipe($ingredient);
        $presenter = app(RecipeContentPresenter::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $presenter->ingredientGroups($recipe);
        $single = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 12; $i++) {
            RecipeIngredient::factory()->create(['recipe_id' => $recipe->id, 'ingredient_id' => $this->ingredient('extra-'.$i)->id]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $presenter->ingredientGroups($recipe);
        $multiple = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($single + 3, $multiple);
    }

    public function test_planner_and_shopping_ingredient_links_use_batched_queries(): void
    {
        $owner = User::factory()->create();
        $plan = MealPlan::factory()->create(['created_by' => $owner->id]);
        $list = ShoppingList::factory()->create(['meal_plan_id' => $plan->id]);
        $addIngredient = function (int $number) use ($plan, $list): void {
            $ingredient = $this->ingredient('planned-'.$number);
            MealPlanEntry::factory()->create(['meal_plan_id' => $plan->id, 'recipe_id' => $this->recipe($ingredient)->id]);
            $list->items()->create(['ingredient_id' => $ingredient->id, 'is_manual' => true]);
        };
        $read = function () use ($owner, $plan, $list): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            app(MealPlanService::class)->weekView($owner, $plan, 'en');
            app(ShoppingListService::class)->itemsView($owner, $list, 'en');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $addIngredient(0);
        $single = $read();
        for ($i = 1; $i <= 8; $i++) {
            $addIngredient($i);
        }
        $this->assertLessThanOrEqual($single + 3, $read());
    }

    private function ingredient(string $code): Ingredient
    {
        return Ingredient::factory()->create([
            'code' => $code, 'catalog_group_id' => IngredientCatalogGroup::factory()->create(['en' => ['name' => 'Vegetables'], 'el' => ['name' => 'Λαχανικά']])->id,
            'en' => ['name' => $code, 'slug' => $code], 'el' => ['name' => 'Υλικό '.$code, 'slug' => $code.'-el'],
        ]);
    }

    private function recipe(Ingredient $ingredient, bool $public = true): Recipe
    {
        $factory = $public ? Recipe::factory()->published() : Recipe::factory();
        $recipe = $factory->create(['base_servings' => 2]);
        RecipeIngredient::factory()->create(['recipe_id' => $recipe->id, 'ingredient_id' => $ingredient->id, 'unit_id' => null, 'quantity' => '2']);

        return $recipe;
    }
}
