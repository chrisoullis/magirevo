<?php

namespace Tests\Feature;

use App\Livewire\IngredientIndex;
use App\Models\Ingredient;
use App\Models\IngredientCatalogGroup;
use App\Models\IngredientMedia;
use App\Models\IngredientSubstitution;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use App\Services\IngredientImageService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IngredientEncyclopediaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_localized_index_and_detail_show_educational_fields_and_safe_language_links(): void
    {
        $ingredient = $this->ingredient('zucchini', ['en' => ['name' => 'Zucchini', 'slug' => 'zucchini', 'description' => 'A summer vegetable.', 'culinary_uses' => 'Roasting', 'storage_tips' => 'Keep cool.'], 'el' => ['name' => 'Κολοκύθι', 'slug' => 'kolokythi', 'preparation_tips' => 'Πλύνετε καλά.']]);

        $this->get('/en/ingredients')->assertOk()->assertSee('Ingredient Encyclopedia')->assertSee('Zucchini');
        $this->get('/el/ingredients')->assertOk()->assertSee('Εγκυκλοπαίδεια Υλικών')->assertSee('Κολοκύθι');
        $this->get('/en/ingredients/zucchini')->assertOk()->assertSee('Roasting')->assertSee('Keep cool.')
            ->assertSee('/el/ingredients/kolokythi')->assertDontSee('Πλύνετε καλά.');
        $this->get('/el/ingredients/kolokythi')->assertOk()->assertSee('Πλύνετε καλά.')->assertDontSee('Keep cool.');
        $this->get('/en/ingredients/kolokythi')->assertNotFound();
    }

    public function test_inactive_ingredients_and_groups_are_hidden_even_from_logged_in_administrators(): void
    {
        $ingredient = $this->ingredient('hidden-ingredient');
        $ingredient->update(['is_active' => false]);
        $this->get('/en/ingredients/hidden-ingredient')->assertNotFound();
        Livewire::test(IngredientIndex::class, ['locale' => 'en'])->assertViewHas('ingredients', fn ($items) => $items->total() === 0);
        $ingredient->update(['is_active' => true]);
        $ingredient->catalogGroup->update(['is_active' => false]);
        $this->actingAs(User::factory()->admin()->create())->get('/en/ingredients/hidden-ingredient')->assertNotFound();
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/en/ingredients/hidden-ingredient');
    }

    public function test_missing_localized_slug_or_translation_never_falls_back_to_the_other_language(): void
    {
        $ingredient = $this->ingredient('one-language');
        $ingredient->translations()->where('locale', 'el')->update(['slug' => null]);

        $this->get('/en/ingredients/one-language')->assertOk()->assertDontSee('<link rel="alternate" hreflang="el"', false);
        $this->get('/el/ingredients/one-language-el')->assertNotFound();
        Livewire::test(IngredientIndex::class, ['locale' => 'el'])->assertViewHas('ingredients', fn ($items) => $items->total() === 0);
        $ingredient->translations()->where('locale', 'el')->delete();
        $this->get('/en/ingredients/one-language')->assertOk()->assertSee('/el/ingredients');
    }

    /** @return array<string, array{string}> */
    public static function searchTerms(): array
    {
        return ['English name' => ['Zucchini'], 'English alias' => ['Courgette'], 'Greek name without accent' => ['κολοκυθι'], 'Greek alias without accent' => ['κολοκυθακι']];
    }

    #[DataProvider('searchTerms')]
    public function test_bilingual_accent_insensitive_name_and_alias_search(string $term): void
    {
        $ingredient = $this->ingredient('zucchini', ['en' => ['name' => 'Zucchini', 'slug' => 'zucchini'], 'el' => ['name' => 'Κολοκύθι', 'slug' => 'kolokythi']]);
        $ingredient->aliases()->createMany([['locale' => 'en', 'alias' => 'Courgette'], ['locale' => 'el', 'alias' => 'Κολοκυθάκι']]);
        $this->ingredient('unrelated');

        Livewire::test(IngredientIndex::class, ['locale' => 'en'])->set('search', $term)
            ->assertViewHas('ingredients', fn ($items) => $items->pluck('id')->all() === [$ingredient->id]);
    }

    public function test_search_treats_wildcards_as_literal_and_group_parameters_are_bound(): void
    {
        $this->ingredient('tomato');
        Livewire::test(IngredientIndex::class, ['locale' => 'en'])->set('search', '%')->assertViewHas('ingredients', fn ($items) => $items->total() === 0)
            ->set('search', '')->set('group', "' OR 1=1 --")->assertViewHas('ingredients', fn ($items) => $items->total() === 0);
        $this->assertDatabaseCount('ingredients', 1);
    }

    public function test_pagination_is_bounded_and_filter_changes_return_to_the_first_page(): void
    {
        $group = $this->group('first-group');
        for ($i = 0; $i < 30; $i++) {
            $this->ingredient('item-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), group: $group);
        }
        $other = $this->ingredient('other-group-item');

        Livewire::test(IngredientIndex::class, ['locale' => 'en'])->assertViewHas('ingredients', fn ($items) => $items->count() === 24 && $items->total() === 31)
            ->call('nextPage')->assertViewHas('ingredients', fn ($items) => $items->currentPage() === 2)
            ->set('group', $other->catalogGroup->code)->assertViewHas('ingredients', fn ($items) => $items->currentPage() === 1 && $items->total() === 1);
    }

    public function test_recipe_links_and_counts_never_include_private_shared_pending_or_future_recipes(): void
    {
        $ingredient = $this->ingredient('tomato');
        $public = $this->recipe($ingredient, 'Public recipe');
        $this->recipe($ingredient, 'Private secret', ['visibility' => 'private']);
        $this->recipe($ingredient, 'Shared secret', ['visibility' => 'shared']);
        $this->recipe($ingredient, 'Pending secret', ['status' => 'pending', 'visibility' => 'private']);
        $this->recipe($ingredient, 'Future secret', ['published_at' => now()->addDay()]);

        foreach ([null, User::factory()->admin()->create()] as $viewer) {
            if ($viewer) {
                $this->actingAs($viewer);
            }
            $this->get('/en/ingredients/tomato')->assertOk()->assertSee('Public recipe')->assertDontSee('Private secret')->assertDontSee('Shared secret')->assertDontSee('Pending secret')->assertDontSee('Future secret')
                ->assertViewHas('recipes', fn ($items) => $items->pluck('id')->all() === [$public->id] && $items->total() === 1);
        }
    }

    public function test_only_general_approved_substitutions_with_visible_replacements_are_public(): void
    {
        $ingredient = $this->ingredient('tomato');
        $replacement = $this->ingredient('approved-alternative');
        IngredientSubstitution::factory()->approved()->create(['ingredient_id' => $ingredient->id, 'replacement_ingredient_id' => $replacement->id, 'en' => ['explanation' => 'General culinary alternative']]);
        $pending = $this->ingredient('pending-alternative');
        IngredientSubstitution::factory()->create(['ingredient_id' => $ingredient->id, 'replacement_ingredient_id' => $pending->id]);
        $inactive = $this->ingredient('inactive-alternative', ['is_active' => false]);
        IngredientSubstitution::factory()->approved()->create(['ingredient_id' => $ingredient->id, 'replacement_ingredient_id' => $inactive->id]);
        $specific = $this->ingredient('private-recipe-alternative');
        $private = $this->recipe($ingredient, 'Private recipe', ['visibility' => 'private']);
        IngredientSubstitution::factory()->approved()->create(['ingredient_id' => $ingredient->id, 'replacement_ingredient_id' => $specific->id, 'recipe_id' => $private->id, 'en' => ['explanation' => 'Private explanation']]);

        $this->get('/en/ingredients/tomato')->assertOk()->assertSee('General culinary alternative')->assertDontSee('Private explanation')->assertDontSee('pending-alternative')->assertDontSee('private-recipe-alternative')->assertDontSee('inactive-alternative')
            ->assertViewHas('substitutions', fn ($items) => $items->total() === 1);
    }

    public function test_public_photo_delivery_is_versioned_conditional_and_rechecks_visibility_before_304(): void
    {
        Storage::fake('local');
        $ingredient = $this->ingredient('tomato');
        $photo = $this->photo($ingredient);
        $url = $photo->publicUrl('thumbnail');

        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertStringContainsString('must-revalidate', $response->headers->get('Cache-Control'));
        $this->get($url, ['If-None-Match' => $response->headers->get('ETag')])->assertNotModified();
        $this->get('/media/'.$photo->media_id)->assertNotFound();
        $ingredient->update(['is_active' => false]);
        $this->get($url, ['If-None-Match' => $response->headers->get('ETag')])->assertNotFound();
    }

    public function test_public_photos_require_bilingual_alt_text_and_no_recipe_or_revision_references(): void
    {
        Storage::fake('local');
        $ingredient = $this->ingredient('tomato');
        $photo = $this->photo($ingredient);
        $url = $photo->publicUrl();
        $photo->media->translations()->where('locale', 'el')->delete();
        $this->get($url)->assertNotFound();
        $this->get('/en/ingredients/tomato')->assertOk()->assertSee('ingredient-placeholder.svg')->assertDontSee('Photo caption secret');
        $photo->media->translations()->create(['locale' => 'el', 'alt_text' => 'Ντομάτες']);
        $private = $this->recipe($ingredient, 'Private recipe', ['visibility' => 'private']);
        $private->media()->attach($photo->media_id, ['role' => 'cover']);
        $this->get($url)->assertNotFound();
        $this->get('/en/ingredients/tomato')->assertOk()->assertDontSee('Photo caption secret');
    }

    public function test_unknown_versions_variants_and_missing_photo_files_return_404(): void
    {
        Storage::fake('local');
        $photo = $this->photo($this->ingredient('tomato'));
        $this->get('/ingredient-images/'.$photo->id.'/display/'.str_repeat('0', 32))->assertNotFound();
        $this->get('/ingredient-images/'.$photo->id.'/original/'.$photo->publicVersion())->assertNotFound();
        Storage::disk('local')->delete($photo->media->file_path);
        $this->get($photo->publicUrl())->assertNotFound();
    }

    public function test_educational_and_alias_text_is_escaped_and_internal_dietary_metadata_is_not_published(): void
    {
        $payload = '<script>alert("ingredient")</script>';
        $ingredient = $this->ingredient('compound', ['en' => ['name' => 'Compound ingredient', 'slug' => 'compound', 'description' => $payload, 'storage_tips' => $payload]]);
        $ingredient->aliases()->create(['locale' => 'en', 'alias' => $payload]);
        $ingredient->dietaryProfile()->create(['composition_status' => 'unknown', 'origin_classification' => 'unknown', 'evidence' => ['Internal private dietary note']]);

        $this->get('/en/ingredients/compound')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee($payload, false)->assertDontSee('Internal private dietary note');
        $this->assertDatabaseCount('recipe_dietary_claims', 0);
    }

    public function test_sitemap_lists_only_visible_localized_ingredient_pages(): void
    {
        $visible = $this->ingredient('visible');
        $visible->translations()->where('locale', 'el')->update(['slug' => null]);
        $this->ingredient('inactive', ['is_active' => false]);

        $this->get('/sitemap.xml')->assertOk()->assertSee('/en/ingredients/visible')->assertDontSee('/el/ingredients/visible-el')->assertDontSee('/en/ingredients/inactive');
        $this->get('/fr/ingredients')->assertNotFound();
    }

    public function test_index_query_count_stays_bounded_as_more_ingredients_and_photos_are_added(): void
    {
        Storage::fake('local');
        $group = $this->group('vegetables');
        $first = $this->ingredient('first', group: $group);
        $this->photo($first);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/en/ingredients')->assertOk();
        $single = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 25; $i++) {
            $ingredient = $this->ingredient('vegetable-'.$i, group: $group);
            if ($i < 3) {
                $this->photo($ingredient);
            }
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/en/ingredients')->assertOk();
        $multiple = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($single + 3, $multiple);
        $this->assertLessThan(25, $multiple);
    }

    public function test_recipe_cards_use_batched_queries_on_ingredient_pages(): void
    {
        $ingredient = $this->ingredient('tomato');
        $this->recipe($ingredient, 'First recipe');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/en/ingredients/tomato')->assertOk();
        $single = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 8; $i++) {
            $this->recipe($ingredient, 'Recipe '.$i);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/en/ingredients/tomato')->assertOk();
        $multiple = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($single + 3, $multiple);
    }

    private function group(string $code): IngredientCatalogGroup
    {
        return IngredientCatalogGroup::factory()->create(['code' => $code, 'en' => ['name' => 'Vegetables'], 'el' => ['name' => 'Λαχανικά']]);
    }

    private function ingredient(string $code, array $attributes = [], ?IngredientCatalogGroup $group = null): Ingredient
    {
        $group ??= $this->group('group-'.$code);

        return Ingredient::factory()->create(array_replace([
            'code' => $code, 'catalog_group_id' => $group->id,
            'en' => ['name' => $code, 'slug' => $code], 'el' => ['name' => 'Υλικό '.$code, 'slug' => $code.'-el'],
        ], $attributes));
    }

    private function recipe(Ingredient $ingredient, string $title, array $attributes = []): Recipe
    {
        $recipe = Recipe::factory()->published()->create($attributes);
        $recipe->translations()->where('locale', 'en')->update(['title' => $title]);
        RecipeIngredient::factory()->create(['recipe_id' => $recipe->id, 'ingredient_id' => $ingredient->id]);

        return $recipe;
    }

    private function photo(Ingredient $ingredient): IngredientMedia
    {
        return app(IngredientImageService::class)->upload(User::factory()->admin()->create(), $ingredient, UploadedFile::fake()->image('vegetable.png', 800, 600), [
            'role' => 'primary', 'rights_basis' => 'own_work', 'rights_reference' => 'Photographed by uploader',
            'alt_text_en' => 'Fresh vegetables', 'alt_text_el' => 'Φρέσκα λαχανικά', 'caption_en' => 'Photo caption secret',
        ]);
    }
}
