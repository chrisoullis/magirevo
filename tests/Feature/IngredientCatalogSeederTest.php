<?php

namespace Tests\Feature;

use App\Enums\DietaryCompatibility;
use App\Models\Ingredient;
use App\Models\IngredientCatalogGroup;
use App\Models\IngredientDietaryProfile;
use App\Models\Media;
use App\Models\RecipeIngredient;
use App\Models\Tag;
use App\Models\User;
use App\Services\IngredientDietaryResolver;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DifficultySeeder;
use Database\Seeders\IngredientCatalogSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\ScalingModeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class IngredientCatalogSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_fresh_catalogue_has_760_unique_bilingual_grouped_ingredients_and_stable_reruns(): void
    {
        $this->artisan('db:seed', ['--class' => 'IngredientCatalogSeeder'])->assertSuccessful();
        $before = $this->snapshot();
        $this->travel(1)->hours();

        $this->artisan('db:seed', ['--class' => 'IngredientCatalogSeeder'])->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('ingredients', 760);
        $this->assertDatabaseCount('ingredient_translations', 1520);
        $this->assertDatabaseCount('ingredient_catalog_groups', 16);
        $this->assertDatabaseCount('ingredient_catalog_group_translations', 32);
        $this->assertSame(760, Ingredient::query()->distinct()->count('code'));
        $this->assertSame(0, Ingredient::query()->whereNull('catalog_group_id')->count());
        $this->assertSame(760, Ingredient::query()->where('is_active', true)->count());
        $this->assertSame(0, DB::table('ingredient_translations')->whereNull('slug')->count());
        $this->assertSame(760, DB::table('ingredient_translations')->where('locale', 'en')->count());
        $this->assertSame(760, DB::table('ingredient_translations')->where('locale', 'el')->count());
        $this->assertSame([
            'baking' => 46, 'dairy_eggs' => 49, 'fish_seafood' => 50, 'fruits' => 60,
            'grains_pasta' => 55, 'herbs_spices' => 88, 'legumes' => 35, 'meat_poultry' => 50,
            'mushrooms' => 20, 'nuts_seeds' => 35, 'oils_fats_vinegars' => 30, 'plant_alternatives' => 25,
            'sauces_condiments' => 52, 'specialty' => 49, 'sweeteners' => 30, 'vegetables' => 86,
        ], DB::table('ingredients')->join('ingredient_catalog_groups as g', 'g.id', '=', 'ingredients.catalog_group_id')
            ->selectRaw('g.code, count(*) as total')->groupBy('g.code')->orderBy('g.code')->pluck('total', 'code')->all());
    }

    public function test_legacy_ids_custom_text_units_states_recipe_lines_images_and_dietary_evidence_are_preserved(): void
    {
        $this->seed([DifficultySeeder::class, ScalingModeSeeder::class, IngredientSeeder::class]);
        $group = IngredientCatalogGroup::factory()->create(['code' => 'my-group']);
        $ingredient = Ingredient::query()->where('code', 'garlic')->firstOrFail();
        $ingredient->update(['is_active' => false, 'catalog_group_id' => $group->id]);
        $ingredient->translations()->where('locale', 'en')->update([
            'name' => 'My garlic name', 'plural_name' => 'My garlic cloves', 'slug' => 'my-garlic',
            'description' => 'Handwritten description', 'storage_tips' => 'My storage notes',
        ]);
        $line = RecipeIngredient::factory()->create(['ingredient_id' => $ingredient->id, 'quantity' => '2.5000']);
        $reviewer = User::factory()->admin()->create();
        $image = Media::factory()->remote()->create();
        $ingredient->media()->attach($image, ['role' => 'primary', 'rights_basis' => 'own_work', 'rights_reference' => 'Uploader photograph', 'rights_confirmed_by' => $reviewer->id, 'rights_confirmed_at' => now()]);
        $profile = IngredientDietaryProfile::query()->create(['ingredient_id' => $ingredient->id, 'composition_status' => 'unknown', 'origin_classification' => 'unknown', 'evidence' => ['Unreviewed notes']]);
        $custom = Ingredient::factory()->create(['code' => 'custom-family-ingredient']);
        $before = $ingredient->fresh()->getAttributes();
        $text = $ingredient->translations()->where('locale', 'en')->firstOrFail()->getAttributes();
        $beforeLine = $line->fresh()->getAttributes();
        $beforeImage = DB::table('ingredient_media')->get()->toJson();
        $beforeProfile = $profile->fresh()->getAttributes();

        $this->seed(IngredientCatalogSeeder::class);
        $this->seed(IngredientCatalogSeeder::class);
        $this->seed(IngredientSeeder::class);

        $this->assertSame($before, $ingredient->fresh()->getAttributes());
        $this->assertSame($text, $ingredient->translations()->where('locale', 'en')->firstOrFail()->getAttributes());
        $this->assertSame($beforeLine, $line->fresh()->getAttributes());
        $this->assertSame($beforeImage, DB::table('ingredient_media')->get()->toJson());
        $this->assertSame($beforeProfile, $profile->fresh()->getAttributes());
        $this->assertModelExists($custom);
        $this->assertDatabaseCount('ingredients', 761);
        $this->assertDatabaseCount('recipe_dietary_claims', 0);
    }

    public function test_missing_translation_and_slug_are_filled_without_replacing_names_or_descriptions(): void
    {
        $ingredient = Ingredient::factory()->create(['code' => 'zucchini']);
        $translation = $ingredient->translations()->create(['locale' => 'en', 'name' => 'My courgette', 'description' => 'My own description']);

        $this->seed(IngredientCatalogSeeder::class);

        $this->assertSame('My courgette', $translation->fresh()->name);
        $this->assertSame('My own description', $translation->fresh()->description);
        $this->assertSame('my-courgette', $translation->fresh()->slug);
        $this->assertSame('Κολοκύθι', $ingredient->fresh()->translate('el')->name);
        $this->assertSame('vegetables', $ingredient->fresh()->catalogGroup->code);
    }

    public function test_custom_group_translations_order_and_disabled_state_are_preserved(): void
    {
        $group = IngredientCatalogGroup::factory()->create(['code' => 'vegetables', 'is_active' => false, 'sort_order' => 901, 'en' => ['name' => 'Our vegetables', 'description' => 'Our grouping notes']]);
        $before = $group->fresh()->getAttributes();

        $this->seed(IngredientCatalogSeeder::class);

        $this->assertSame($before, $group->fresh()->getAttributes());
        $this->assertSame('Our vegetables', $group->fresh()->translate('en')->name);
        $this->assertSame('Our grouping notes', $group->fresh()->translate('en')->description);
        $this->assertSame('Λαχανικά και φυλλώδη χόρτα', $group->fresh()->translate('el')->name);
    }

    public function test_slug_collisions_do_not_change_the_existing_owner(): void
    {
        $custom = Ingredient::factory()->create(['en' => ['name' => 'Custom tomato label', 'slug' => 'zucchini']]);

        $this->seed(IngredientCatalogSeeder::class);

        $this->assertSame('zucchini', $custom->fresh()->translate('en')->slug);
        $this->assertSame('zucchini-zucchini-1', Ingredient::query()->where('code', 'zucchini')->firstOrFail()->translate('en')->slug);
    }

    public function test_existing_name_under_another_code_aborts_without_merging_or_partial_writes(): void
    {
        $custom = Ingredient::factory()->create(['code' => 'our-zucchini', 'en' => ['name' => 'Zucchini']]);

        try {
            $this->seed(IngredientCatalogSeeder::class);
            $this->fail('A conflicting identity must be reviewed manually.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('belongs to existing code [our-zucchini]', $exception->getMessage());
        }

        $this->assertModelExists($custom);
        $this->assertDatabaseCount('ingredients', 1);
        $this->assertDatabaseCount('ingredient_catalog_groups', 0);
        $this->assertDatabaseCount('ingredient_aliases', 0);
    }

    public function test_synonyms_are_aliases_and_meaningful_ingredient_forms_remain_distinct(): void
    {
        $this->seed(IngredientCatalogSeeder::class);

        $this->assertSame(['zucchini'], Ingredient::query()->whereHas('aliases', fn ($q) => $q->where('alias', 'Courgette'))->pluck('code')->all());
        $this->assertSame(['eggplant'], Ingredient::query()->whereHas('aliases', fn ($q) => $q->where('alias', 'Aubergine'))->pluck('code')->all());
        $this->assertSame(['coriander-leaves'], Ingredient::query()->whereHas('aliases', fn ($q) => $q->where('alias', 'Cilantro'))->pluck('code')->all());
        $this->assertDatabaseMissing('ingredients', ['code' => 'courgette']);
        $this->assertDatabaseMissing('ingredients', ['code' => 'aubergine']);
        $this->assertSame(8, Ingredient::query()->whereIn('code', ['fresh-ginger', 'ground-ginger', 'garlic', 'garlic-powder', 'tomato', 'canned-chopped-tomatoes', 'coriander-leaves', 'coriander-seeds'])->count());
        $this->assertSame('Γλυκιά πιπεριά', Ingredient::query()->where('code', 'bell-pepper')->firstOrFail()->translate('el')->name);
        $this->assertSame('Φύλλα κόλιανδρου', Ingredient::query()->where('code', 'coriander-leaves')->firstOrFail()->translate('el')->name);
        $this->assertSame('Toasted sesame seeds', Ingredient::query()->where('code', 'sesame-seeds')->firstOrFail()->translate('en')->name);
        $this->assertSame(8, Ingredient::query()->whereIn('code', ['halloumi', 'fresh-anari', 'dried-anari', 'cypriot-trahana', 'kolokasi', 'poulles', 'bulgur', 'carob-syrup'])->count());
    }

    public function test_case_variant_existing_alias_does_not_duplicate_or_get_rewritten(): void
    {
        $ingredient = Ingredient::factory()->create(['code' => 'zucchini']);
        $alias = $ingredient->aliases()->create(['locale' => 'en', 'alias' => 'COURGETTE']);
        $before = $alias->fresh()->getAttributes();

        $this->seed(IngredientCatalogSeeder::class);

        $this->assertSame($before, $alias->fresh()->getAttributes());
        $this->assertSame(1, $ingredient->aliases()->where('locale', 'en')->whereRaw('lower(alias) = ?', ['courgette'])->count());
    }

    public function test_seeding_never_infers_dietary_compatibility_or_adds_media_or_substitutions(): void
    {
        $this->seed([DifficultySeeder::class, ScalingModeSeeder::class, IngredientCatalogSeeder::class]);
        $ingredient = Ingredient::query()->where('code', 'firm-tofu')->firstOrFail();
        $line = RecipeIngredient::factory()->create(['ingredient_id' => $ingredient->id]);
        $tag = Tag::factory()->dietary()->create(['code' => 'vegan']);

        $this->assertSame(DietaryCompatibility::Unknown, app(IngredientDietaryResolver::class)->resolve($line->recipe, $tag));
        foreach (['ingredient_dietary_profiles', 'ingredient_dietary_assessments', 'ingredient_allergen_assessments', 'ingredient_components', 'ingredient_substitutions', 'recipe_dietary_claims', 'dietary_claim_audits', 'ingredient_media', 'media'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_production_installation_seeds_the_catalogue_without_demo_recipes(): void
    {
        $this->app['env'] = 'production';
        config(['admin.email' => null, 'admin.password' => null]);

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
        $before = $this->snapshot();
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('ingredients', 760);
        $this->assertDatabaseCount('ingredient_translations', 1520);
        $this->assertDatabaseCount('recipes', 0);
        $this->assertDatabaseCount('recipe_ingredients', 0);
        $this->assertDatabaseCount('recipe_dietary_claims', 0);
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['ingredients', 'ingredient_translations', 'ingredient_catalog_groups', 'ingredient_catalog_group_translations', 'ingredient_aliases'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }
}
