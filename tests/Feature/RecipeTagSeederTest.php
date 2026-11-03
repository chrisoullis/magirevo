<?php

namespace Tests\Feature;

use App\Enums\DietaryClaimStatus;
use App\Filament\Resources\Recipes\Pages\CreateRecipe;
use App\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Livewire\RecipeIndex;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\TagTranslation;
use App\Models\User;
use App\Services\DietaryClaimService;
use App\Services\DietaryClaimVisibility;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DifficultySeeder;
use Database\Seeders\RecipeTagSeeder;
use Database\Seeders\ScalingModeSeeder;
use Database\Seeders\TagSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class RecipeTagSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_catalogue_is_bilingual_and_repeated_seeding_changes_no_records(): void
    {
        $this->artisan('db:seed', ['--class' => 'RecipeTagSeeder'])->assertSuccessful();
        $before = $this->catalogueSnapshot();
        $this->travel(1)->hours();

        $this->artisan('db:seed', ['--class' => 'RecipeTagSeeder'])->assertSuccessful();

        $this->assertSame($before, $this->catalogueSnapshot());
        $this->assertDatabaseCount('tags', 338);
        $this->assertDatabaseCount('tag_translations', 676);
        $this->assertSame(338, Tag::query()->distinct()->count('code'));
        $this->assertSame(338, TagTranslation::query()->where('locale', 'en')->count());
        $this->assertSame(338, TagTranslation::query()->where('locale', 'el')->count());
        $this->assertSame([
            'cooking_method' => 32, 'cuisine' => 39, 'dietary_claim' => 30, 'equipment' => 22,
            'flavour' => 29, 'main_ingredient' => 50, 'occasion' => 26, 'practical' => 27,
            'preparation_time' => 18, 'recipe_style' => 29, 'season' => 16, 'texture' => 20,
        ], Tag::query()->selectRaw('group_code, count(*) as total')->groupBy('group_code')->orderBy('group_code')->pluck('total', 'group_code')->all());
        $this->assertDatabaseHas('tag_translations', ['locale' => 'el', 'name' => 'Κυπριακή']);
        $this->assertDatabaseHas('tag_translations', ['locale' => 'en', 'name' => "New Year's Eve"]);
        $this->assertDatabaseCount('recipe_tag', 0);
        $this->assertDatabaseCount('recipe_dietary_claims', 0);
    }

    public function test_upgrade_preserves_custom_text_disabled_states_associations_and_claims(): void
    {
        $this->seed([DifficultySeeder::class, ScalingModeSeeder::class, TagSeeder::class]);
        $custom = Tag::factory()->create(['code' => 'my-custom-tag']);
        $budget = Tag::query()->where('code', 'budget-friendly')->firstOrFail();
        $budget->update(['is_active' => false, 'sort_order' => 987, 'group_code' => 'occasion']);
        $budget->translations()->where('locale', 'en')->update(['name' => 'My own name', 'slug' => 'my-own-slug', 'description' => 'My own description']);
        $owner = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->create(['author_id' => $owner->id]);
        $vegetarian = Tag::query()->where('code', 'vegetarian')->firstOrFail();
        $recipe->tags()->attach([$budget->id, $vegetarian->id, $custom->id]);
        $claim = app(DietaryClaimService::class)->suggest($owner, $recipe, $vegetarian);
        $beforeTag = $budget->fresh()->getAttributes();
        $beforeText = $budget->translations()->orderBy('id')->get()->map->getAttributes()->all();
        $beforePivot = DB::table('recipe_tag')->orderBy('tag_id')->get()->toJson();
        $beforeClaim = $claim->fresh()->getAttributes();
        $beforeAudits = DB::table('dietary_claim_audits')->orderBy('id')->get()->toJson();

        $this->seed(RecipeTagSeeder::class);
        $this->seed(RecipeTagSeeder::class);

        $this->assertSame($beforeTag, $budget->fresh()->getAttributes());
        $this->assertSame($beforeText, $budget->translations()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($beforePivot, DB::table('recipe_tag')->orderBy('tag_id')->get()->toJson());
        $this->assertSame($beforeClaim, $claim->fresh()->getAttributes());
        $this->assertSame($beforeAudits, DB::table('dietary_claim_audits')->orderBy('id')->get()->toJson());
        $this->assertSame(DietaryClaimStatus::Suggested, $claim->fresh()->status);
        $this->assertModelExists($custom);
        $this->assertTrue($vegetarian->fresh()->is_public_enabled);
        $this->assertDatabaseCount('tags', 341);
    }

    public function test_missing_translations_are_added_without_replacing_existing_translations(): void
    {
        $tag = Tag::factory()->create(['code' => 'cypriot', 'classification' => 'cuisine']);
        $translation = $tag->translations()->create(['locale' => 'en', 'name' => 'My Cypriot label', 'slug' => 'my-cypriot-label', 'description' => 'Custom description']);
        $before = $translation->fresh()->getAttributes();

        $this->seed(RecipeTagSeeder::class);

        $this->assertSame($before, $translation->fresh()->getAttributes());
        $this->assertDatabaseHas('tag_translations', ['tag_id' => $tag->id, 'locale' => 'el', 'name' => 'Κυπριακή']);
        $this->assertSame('cuisine', $tag->fresh()->group_code);
    }

    public function test_existing_localized_slugs_are_preserved_when_new_slugs_collide(): void
    {
        $custom = Tag::factory()->create();
        $custom->translations()->createMany([
            ['locale' => 'en', 'name' => 'A custom tag', 'slug' => 'cypriot'],
            ['locale' => 'el', 'name' => 'Προσαρμοσμένη ετικέτα', 'slug' => 'kipriaki'],
        ]);

        $this->seed(RecipeTagSeeder::class);
        $this->seed(RecipeTagSeeder::class);

        $tag = Tag::query()->where('code', 'cypriot')->firstOrFail();
        $this->assertSame('cypriot-cypriot-1', $tag->translate('en')->slug);
        $this->assertDatabaseHas('tag_translations', ['tag_id' => $custom->id, 'locale' => 'en', 'slug' => 'cypriot']);
        $this->assertDatabaseCount('tag_translations', 678);
    }

    public function test_classification_conflicts_abort_the_entire_seed_without_reclassifying(): void
    {
        $tag = Tag::factory()->create(['code' => 'vegetarian', 'classification' => 'descriptive']);

        try {
            $this->seed(RecipeTagSeeder::class);
            $this->fail('A dietary code with a conflicting classification must require manual review.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Classification conflict for tag [vegetarian]', $exception->getMessage());
        }

        $this->assertDatabaseCount('tags', 1);
        $this->assertDatabaseCount('tag_translations', 0);
        $this->assertSame('descriptive', $tag->fresh()->classification->value);
        $this->assertNull($tag->fresh()->group_code);
    }

    public function test_dietary_catalogue_entries_never_create_claims_or_become_public_through_a_pivot(): void
    {
        $this->seed([DifficultySeeder::class, ScalingModeSeeder::class, RecipeTagSeeder::class]);
        config(['dietary.public_release_enabled' => true, 'dietary.public_claim_codes' => ['vegetarian', 'vegan', 'high-protein', 'gluten-free']]);
        $recipe = Recipe::factory()->published()->create();
        $recipe->tags()->attach(Tag::query()->whereIn('code', ['cypriot', 'vegetarian', 'vegan', 'high-protein', 'gluten-free'])->pluck('id'));

        $this->seed(RecipeTagSeeder::class);

        $this->assertSame(0, Tag::query()->where('group_code', 'dietary_claim')->where('is_public_enabled', true)->count());
        $this->assertSame(['cypriot'], app(DietaryClaimVisibility::class)->publicTags($recipe->fresh())->pluck('code')->all());
        $this->assertSame([], app(DietaryClaimVisibility::class)->publicClaimIndex());
        $this->assertDatabaseCount('recipe_dietary_claims', 0);
        $this->assertDatabaseCount('dietary_claim_audits', 0);
        Livewire::test(RecipeIndex::class, ['locale' => 'en'])
            ->assertSee('Cuisine &amp; Region', false)->assertSee('Cypriot')->assertDontSee('High-Protein')
            ->set('tag', 'vegetarian')->assertViewHas('recipes', fn ($recipes) => $recipes->isEmpty())
            ->set('tag', 'cypriot')->assertViewHas('recipes', fn ($recipes) => $recipes->modelKeys() === [$recipe->id]);
    }

    public function test_dietary_group_cannot_be_published_as_an_editorial_tag_even_if_misclassified(): void
    {
        $this->seed([DifficultySeeder::class, ScalingModeSeeder::class]);
        $tag = Tag::factory()->create(['group_code' => 'dietary_claim']);
        $tag->translations()->create(['locale' => 'en', 'name' => 'Unverified claim', 'slug' => 'unverified-claim']);
        $recipe = Recipe::factory()->published()->create();
        $recipe->tags()->attach($tag);

        $this->assertCount(0, app(DietaryClaimVisibility::class)->publicTags($recipe));
        Livewire::test(RecipeIndex::class, ['locale' => 'en'])
            ->set('search', 'Unverified claim')->assertViewHas('recipes', fn ($recipes) => $recipes->isEmpty())
            ->set('search', '')->set('tag', $tag->code)->assertViewHas('recipes', fn ($recipes) => $recipes->isEmpty());
    }

    public function test_database_seeder_in_production_adds_catalogue_without_demo_recipes_or_claims(): void
    {
        $this->app['env'] = 'production';
        config(['admin.email' => null, 'admin.password' => null]);

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertDatabaseCount('tags', 340);
        $this->assertDatabaseCount('tag_translations', 680);
        $this->assertDatabaseCount('recipes', 0);
        $this->assertDatabaseCount('recipe_tag', 0);
        $this->assertDatabaseCount('recipe_dietary_claims', 0);
    }

    public function test_tag_groups_are_filterable_in_filament_with_existing_permissions(): void
    {
        $this->requireFilament();
        $this->seed(RecipeTagSeeder::class);
        $cuisine = Tag::query()->where('code', 'cypriot')->firstOrFail();
        $equipment = Tag::query()->where('code', 'air-fryer')->firstOrFail();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(ListTags::class)->filterTable('group_code', 'cuisine')
            ->assertCanSeeTableRecords([$cuisine])->assertCanNotSeeTableRecords([$equipment]);
        $this->actingAs(User::factory()->create())->get('/admin/tags')->assertForbidden();
        $editor = User::factory()->editor()->create();
        $this->assertFalse($editor->can('create', Tag::class));
        $this->assertTrue($admin->can('create', Tag::class));
    }

    public function test_recipe_editor_searches_bilingual_ordinary_tags_and_rejects_dietary_selections(): void
    {
        $this->requireFilament();
        $this->seed([DifficultySeeder::class, ScalingModeSeeder::class, RecipeTagSeeder::class]);
        $admin = User::factory()->admin()->create();
        $dietary = Tag::query()->where('code', 'vegetarian')->firstOrFail();
        $component = Livewire::actingAs($admin)->test(CreateRecipe::class);
        $field = $component->instance()->form->getComponent('tags', withHidden: true);
        $this->assertNotNull($field);
        $results = $field->getSearchResults('Κυπριακή');
        $this->assertContains('Cuisine & Region · Cypriot', $results);
        $this->assertSame([], $field->getSearchResults('vegetarian'));

        $component->fillForm([
            'en' => ['title' => 'Tag test', 'slug' => 'tag-test'],
            'el' => ['title' => 'Δοκιμή', 'slug' => 'dokimi'],
            'tags' => [$dietary->id],
        ])->call('create')->assertHasFormErrors(['tags.0']);
        $this->assertDatabaseCount('recipe_dietary_claims', 0);
    }

    public function test_saving_ordinary_editor_tags_keeps_existing_dietary_associations_without_creating_claims(): void
    {
        $this->requireFilament();
        $this->seed([DifficultySeeder::class, ScalingModeSeeder::class, RecipeTagSeeder::class]);
        $admin = User::factory()->admin()->create();
        $recipe = Recipe::factory()->complete()->create();
        $dietary = Tag::query()->where('code', 'vegetarian')->firstOrFail();
        $ordinary = Tag::query()->where('code', 'cypriot')->firstOrFail();
        $recipe->tags()->attach($dietary);

        Livewire::actingAs($admin)->test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
            ->fillForm(['tags' => [$ordinary->id]])->call('save')->assertHasNoFormErrors();

        $this->assertDatabaseHas('recipe_tag', ['recipe_id' => $recipe->id, 'tag_id' => $ordinary->id]);
        $this->assertDatabaseHas('recipe_tag', ['recipe_id' => $recipe->id, 'tag_id' => $dietary->id]);
        $this->assertDatabaseCount('recipe_dietary_claims', 0);
    }

    private function requireFilament(): void
    {
        if (! extension_loaded('intl')) {
            $this->markTestSkipped('Filament rendering requires intl.');
        }

        Filament::setCurrentPanel('admin');
    }

    /** @return array<string, mixed> */
    private function catalogueSnapshot(): array
    {
        return [
            'tags' => DB::table('tags')->orderBy('id')->get()->toJson(),
            'translations' => DB::table('tag_translations')->orderBy('id')->get()->toJson(),
        ];
    }
}
