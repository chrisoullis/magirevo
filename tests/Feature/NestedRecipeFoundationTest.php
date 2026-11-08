<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeRevision;
use App\Models\RecipeRevisionDependency;
use App\Models\RecipeYield;
use App\Models\Unit;
use App\Models\User;
use App\Services\RecipeContentGuard;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use Database\Seeders\DifficultySeeder;
use Database\Seeders\ScalingModeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NestedRecipeFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_components_share_group_ordering_and_bilingual_notes_with_regular_ingredients(): void
    {
        $source = $this->publishedRevision();
        $parent = Recipe::factory()->create();
        $group = $parent->ingredientGroups()->create(['en' => ['name' => 'Sauce'], 'el' => ['name' => 'Σάλτσα']]);
        $first = RecipeIngredient::factory()->for($parent)->create(['ingredient_group_id' => $group->id, 'sort_order' => 0]);
        $component = RecipeIngredient::factory()->for($parent)->component($source)->create([
            'ingredient_group_id' => $group->id, 'sort_order' => 1,
            'en' => ['preparation_note' => 'Chilled'], 'el' => ['preparation_note' => 'Κρύο'],
        ])->fresh();
        $last = RecipeIngredient::factory()->for($parent)->create(['ingredient_group_id' => $group->id, 'sort_order' => 2]);

        $this->assertSame([$first->id, $component->id, $last->id], $parent->recipeIngredients()->orderBy('sort_order')->pluck('id')->all());
        $this->assertTrue($component->isComponent());
        $this->assertTrue($component->componentRecipe->is($source->recipe));
        $this->assertTrue($component->componentRevision->is($source));
        $this->assertTrue($component->ingredientGroup->is($group));
        $this->assertSame('Chilled', $component->getTranslation('en', false)->preparation_note);
        $this->assertSame('Κρύο', $component->getTranslation('el', false)->preparation_note);
        $this->assertSame([$component->id], $parent->components()->pluck('id')->all());
        $this->assertSame([$component->id], $source->recipe->usedAsComponentIn()->pluck('id')->all());
        $uuid = $component->line_uuid;
        $component->update(['sort_order' => 3]);
        $this->assertSame($uuid, $component->fresh()->line_uuid);
        $this->assertTrue(Str::isUuid($uuid));
    }

    public function test_upgrade_backfills_line_identities_without_changing_retained_revision_payloads(): void
    {
        $schema = 'nested_upgrade_'.bin2hex(random_bytes(8));
        $previous = config('database.connections.pgsql.search_path');
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        DB::statement('SET LOCAL search_path TO "'.$schema.'"');
        config(['database.connections.pgsql.search_path' => [$schema]]);
        try {
            // Forward-only replay in a namespace rolled back with this test; never undo applied migrations.
            foreach (glob(database_path('migrations/*.php')) as $path) {
                $name = basename($path);
                if ($name >= '2026_11_07' || str_contains($name, 'add_multilingual_search_indexes')) {
                    continue;
                }
                (require $path)->up();
            }
            $this->seed([DifficultySeeder::class, ScalingModeSeeder::class]);
            $recipe = Recipe::factory()->create();
            $ingredient = Ingredient::factory()->create();
            $id = DB::table('recipe_ingredients')->insertGetId([
                'recipe_id' => $recipe->id, 'ingredient_id' => $ingredient->id, 'scaling_mode' => 'linear',
                'quantity' => '1', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $revision = RecipeRevision::create([
                'recipe_id' => $recipe->id, 'revision_number' => 1, 'status' => 'published', 'published_at' => now(),
                'schema_version' => 1, 'payload' => ['legacy' => 'unchanged'], 'content_hash' => hash('sha256', 'legacy'),
            ]);
            $before = $revision->fresh()->getRawOriginal();
            $this->assertFalse(Schema::hasColumn('recipe_ingredients', 'line_uuid'));

            (require database_path('migrations/2026_11_07_144302_add_nested_recipe_foundation.php'))->up();

            $line = DB::table('recipe_ingredients')->find($id);
            $this->assertSame('ingredient', $line->line_type);
            $this->assertTrue(Str::isUuid($line->line_uuid));
            $this->assertNull($line->component_recipe_id);
            $this->assertSame($before, $revision->fresh()->getRawOriginal());
        } finally {
            DB::statement('SET LOCAL search_path TO public');
            config(['database.connections.pgsql.search_path' => $previous]);
        }
    }

    #[TestWith(['line_uuid'])]
    #[TestWith(['recipe_id'])]
    public function test_line_identity_cannot_be_reassigned(string $field): void
    {
        $line = RecipeIngredient::factory()->create()->fresh();
        $value = $field === 'line_uuid' ? (string) Str::uuid() : Recipe::factory()->create()->id;
        $this->expectException(QueryException::class);
        DB::table('recipe_ingredients')->where('id', $line->id)->update([$field => $value]);
    }

    public function test_a_component_cannot_reference_a_revision_from_another_recipe(): void
    {
        $source = $this->publishedRevision();
        $other = Recipe::factory()->create();
        $this->expectException(QueryException::class);
        RecipeIngredient::factory()->component($source)->create(['component_recipe_id' => $other->id]);
    }

    public function test_a_component_cannot_reference_a_mutable_draft(): void
    {
        $source = $this->publishedRevision();
        $draft = app(RecipeRevisionService::class)->createDraft(auth()->user(), $source->recipe);
        $this->expectException(QueryException::class);
        RecipeIngredient::factory()->component($draft)->create();
    }

    public function test_a_component_cannot_reference_its_own_recipe(): void
    {
        $source = $this->publishedRevision();
        $this->expectException(QueryException::class);
        RecipeIngredient::factory()->for($source->recipe)->component($source)->create();
    }

    public function test_a_component_cannot_use_another_parents_group(): void
    {
        $source = $this->publishedRevision();
        $group = $source->recipe->ingredientGroups()->create();
        $this->expectException(QueryException::class);
        RecipeIngredient::factory()->component($source)->create(['ingredient_group_id' => $group->id]);
    }

    #[TestWith(['ingredient_id', 'ingredient'])]
    #[TestWith(['quantity_basis', null])]
    #[TestWith(['quantity_basis', 'unknown'])]
    #[TestWith(['shopping_mode', null])]
    #[TestWith(['shopping_mode', 'unknown'])]
    #[TestWith(['shopping_mode', 'prepared'])]
    #[TestWith(['quantity', '0'])]
    #[TestWith(['quantity', null])]
    public function test_component_rows_reject_inconsistent_fields(string $field, ?string $value): void
    {
        $source = $this->publishedRevision();
        $value = $value === 'ingredient' ? Ingredient::factory()->create()->id : $value;
        $this->expectException(QueryException::class);
        RecipeIngredient::factory()->component($source)->create([$field => $value]);
    }

    public function test_manual_component_amounts_remain_explicitly_unquantified(): void
    {
        $line = RecipeIngredient::factory()->component($this->publishedRevision())->create(['quantity' => null, 'scaling_mode' => 'manual']);
        $this->assertNull($line->fresh()->quantity);
        $this->assertSame('manual', $line->scaling_mode);
    }

    public function test_superseded_published_revisions_remain_valid_component_sources(): void
    {
        $source = $this->publishedRevision();
        $source->update(['status' => 'superseded']);
        $line = RecipeIngredient::factory()->component($source)->create();
        $this->assertTrue($line->componentRevision->is($source));
    }

    #[TestWith(['weight', 'volume'])]
    #[TestWith(['volume', 'mass'])]
    #[TestWith(['whole_batch', 'mass'])]
    #[TestWith(['servings', 'volume'])]
    public function test_component_units_cannot_contradict_the_quantity_basis(string $basis, string $family): void
    {
        $source = $this->publishedRevision();
        $unit = Unit::factory()->create(['unit_type' => $family, 'base_conversion' => 1]);
        $this->expectException(QueryException::class);
        RecipeIngredient::factory()->component($source)->create(['quantity_basis' => $basis, 'unit_id' => $unit->id]);
    }

    public function test_prepared_mapping_does_not_replace_the_component_reference(): void
    {
        $source = $this->publishedRevision();
        $ingredient = Ingredient::factory()->create();
        $unit = Unit::factory()->create(['unit_type' => 'mass', 'base_conversion' => 1]);
        $line = RecipeIngredient::factory()->component($source)->create([
            'shopping_mode' => 'prepared', 'prepared_ingredient_id' => $ingredient->id,
            'prepared_quantity_per_batch' => '500', 'prepared_unit_id' => $unit->id,
        ])->fresh();
        $this->assertNull($line->ingredient_id);
        $this->assertTrue($line->preparedIngredient->is($ingredient));
        $this->assertTrue($line->preparedUnit->is($unit));
        $this->assertSame('500.0000', $line->prepared_quantity_per_batch);
        $this->assertTrue($line->componentRevision->is($source));
        $this->assertTrue($ingredient->isInUse());
        $this->assertTrue($unit->isInUse());
    }

    #[TestWith(['weight', 'mass'])]
    #[TestWith(['volume', 'volume'])]
    public function test_verified_batch_yield_is_separate_from_serving_count(string $basis, string $family): void
    {
        $recipe = Recipe::factory()->create(['base_servings' => '4']);
        $unit = Unit::factory()->create(['unit_type' => $family, 'base_conversion' => 1]);
        $yield = RecipeYield::factory()->for($recipe)->create(['basis' => $basis, 'unit_id' => $unit->id]);
        $this->assertSame('4.00', $recipe->fresh()->base_servings);
        $this->assertSame('500.0000', $recipe->yields()->firstOrFail()->quantity);
        $this->assertSame(['method' => 'Measured after cooking'], $yield->fresh()->evidence);
        $this->assertTrue($yield->unit->is($unit));
        $this->assertTrue($yield->verifier->is(User::findOrFail($yield->verified_by)));
        $this->assertTrue($unit->isInUse());
    }

    #[TestWith(['quantity', '0'])]
    #[TestWith(['quantity', '-1'])]
    #[TestWith(['basis', 'servings'])]
    #[TestWith(['basis', 'volume'])]
    #[TestWith(['evidence', []])]
    #[TestWith(['verified_by', null])]
    #[TestWith(['verified_at', null])]
    public function test_verified_yields_reject_invalid_measurements_or_missing_verification(string $field, mixed $value): void
    {
        $this->expectException(QueryException::class);
        RecipeYield::factory()->create([$field => $value]);
    }

    public function test_verified_yield_rejects_a_unit_without_a_conversion_factor(): void
    {
        $unit = Unit::factory()->create(['unit_type' => 'count', 'base_conversion' => null]);
        $this->expectException(QueryException::class);
        RecipeYield::factory()->create(['unit_id' => $unit->id]);
    }

    public function test_used_yield_units_cannot_be_redefined(): void
    {
        $yield = RecipeYield::factory()->create();
        $this->expectException(QueryException::class);
        $yield->unit->update(['base_conversion' => 1000]);
    }

    public function test_historical_unit_definitions_remain_protected_after_live_line_removal(): void
    {
        $recipe = Recipe::factory()->complete()->create();
        $unit = Unit::factory()->create();
        $recipe->recipeIngredients()->firstOrFail()->update(['unit_id' => $unit->id]);
        app(RecipePublishingService::class)->publish($recipe);
        $recipe->recipeIngredients()->delete();
        $this->assertTrue($unit->isInUse());
        $this->expectException(QueryException::class);
        $unit->update(['base_conversion' => '1000']);
    }

    public function test_retained_source_relationships_survive_soft_deletion(): void
    {
        $source = $this->publishedRevision();
        $line = RecipeIngredient::factory()->component($source)->create();
        $source->recipe->delete();
        $this->assertTrue($line->fresh()->componentRecipe->trashed());
        $this->assertTrue($line->componentRevision->is($source));
    }

    public function test_published_yields_obey_the_existing_content_guard(): void
    {
        $source = $this->publishedRevision();
        app(RecipeContentGuard::class)->setMode('enforce');
        $this->expectException(QueryException::class);
        RecipeYield::factory()->for($source->recipe)->create();
    }

    public function test_referenced_recipes_cannot_be_hard_deleted(): void
    {
        $source = $this->publishedRevision();
        RecipeIngredient::factory()->component($source)->create();
        $this->expectException(QueryException::class);
        DB::table('recipes')->where('id', $source->recipe_id)->delete();
    }

    public function test_deleting_a_component_line_preserves_the_source_recipe_and_revision(): void
    {
        $source = $this->publishedRevision();
        $line = RecipeIngredient::factory()->component($source)->create();
        $line->delete();
        $this->assertModelMissing($line);
        $this->assertModelExists($source);
        $this->assertModelExists($source->recipe);
    }

    public function test_dependencies_retain_the_exact_source_revision_after_live_line_removal(): void
    {
        $source = $this->publishedRevision();
        $parent = Recipe::factory()->create();
        $line = RecipeIngredient::factory()->for($parent)->component($source)->create()->fresh();
        $revision = $this->draftRevision($parent);
        $dependency = $revision->dependencies()->create([
            'line_uuid' => $line->line_uuid, 'component_recipe_id' => $source->recipe_id, 'component_recipe_revision_id' => $source->id,
        ]);
        $line->delete();
        $this->assertTrue($dependency->fresh()->componentRevision->is($source));
        $this->assertTrue($dependency->revision->is($revision));
        $this->assertTrue($dependency->componentRecipe->is($source->recipe));
        $this->assertSame([$dependency->id], $source->referencedBy()->pluck('id')->all());
    }

    public function test_discarding_a_draft_removes_its_dependency_index_without_deleting_the_source(): void
    {
        $source = $this->publishedRevision();
        $revision = $this->draftRevision(Recipe::factory()->create());
        $dependency = $revision->dependencies()->create([
            'line_uuid' => (string) Str::uuid(), 'component_recipe_id' => $source->recipe_id, 'component_recipe_revision_id' => $source->id,
        ]);
        $revision->delete();
        $this->assertModelMissing($dependency);
        $this->assertModelExists($source);
    }

    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    #[TestWith(['insert'])]
    public function test_dependencies_cannot_change_after_the_parent_revision_is_frozen(string $operation): void
    {
        $source = $this->publishedRevision();
        $revision = $this->draftRevision(Recipe::factory()->create());
        $data = ['recipe_revision_id' => $revision->id, 'line_uuid' => (string) Str::uuid(), 'component_recipe_id' => $source->recipe_id, 'component_recipe_revision_id' => $source->id];
        $dependency = RecipeRevisionDependency::create($data);
        $revision->update(['status' => 'in_review']);
        $this->expectException(QueryException::class);
        match ($operation) {
            'update' => $dependency->update(['line_uuid' => (string) Str::uuid()]),
            'delete' => $dependency->delete(),
            'insert' => RecipeRevisionDependency::create([...$data, 'line_uuid' => (string) Str::uuid()]),
        };
    }

    public function test_schema_rollback_refuses_to_destroy_nested_recipe_data(): void
    {
        RecipeYield::factory()->create();
        $migration = require database_path('migrations/2026_11_07_144302_add_nested_recipe_foundation.php');
        $this->expectException(\LogicException::class);
        $migration->down();
    }

    private function publishedRevision(): RecipeRevision
    {
        $this->actingAs(User::factory()->admin()->create());
        $recipe = Recipe::factory()->complete()->create();
        app(RecipePublishingService::class)->publish($recipe);

        return $recipe->fresh()->currentRevision;
    }

    private function draftRevision(Recipe $recipe): RecipeRevision
    {
        return RecipeRevision::create([
            'recipe_id' => $recipe->id, 'revision_number' => 1, 'status' => 'draft',
            'payload' => [], 'content_hash' => hash('sha256', '[]'), 'schema_version' => 1,
        ]);
    }
}
