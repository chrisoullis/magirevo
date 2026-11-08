<?php

namespace Tests\Feature;

use App\Actions\SyncRecipeContent;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeYield;
use App\Models\Unit;
use App\Models\User;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use App\Services\Recipes\RecipeComponentEligibility;
use App\Services\Recipes\RecipeEditorData;
use App\Services\Recipes\RecipeEditorService;
use App\Services\Recipes\RecipeEditorValidator;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\ContributorRecipeEditorHarness;
use Tests\TestCase;

class NestedRecipeEditorTest extends TestCase
{
    use RefreshDatabase;

    private function data(): array
    {
        return [
            'en' => ['title' => 'Nested soup'], 'prep_minutes' => 0, 'cook_minutes' => 0,
            'rest_minutes' => 0, 'base_servings' => 4, 'difficulty' => 'Easy',
            'ingredients_data' => [], 'steps_data' => [], 'ingredient_groups_data' => [],
        ];
    }

    private function componentLine(Recipe $source): array
    {
        return ['line_type' => 'recipe', 'component_recipe_id' => $source->id,
            'quantity' => 1, 'quantity_basis' => 'whole_batch', 'shopping_mode' => 'scratch', 'scaling_mode' => 'linear'];
    }

    public function test_conversion_preserves_units_yields_and_all_typed_fields(): void
    {
        $payload = ['units' => [12 => ['code' => 'g', 'unit_type' => 'weight', 'base_conversion' => '1']],
            'yields' => [['basis' => 'weight', 'quantity' => '500', 'unit_id' => 12]],
            'ingredients' => [[...$this->componentLine(new Recipe(['id' => 7])), 'line_uuid' => 'opaque',
                'component_recipe_revision_id' => 8, 'prepared_ingredient_id' => 3,
                'prepared_quantity_per_batch' => '2', 'prepared_unit_id' => 12,
                'quantity_max' => null, 'unit_id' => null]]];
        $result = RecipeEditorData::toPayload($payload, RecipeEditorData::fromPayload($payload));
        $this->assertSame($payload['units'], $result['units']);
        $this->assertSame($payload['yields'], $result['yields']);
        $this->assertSame(RecipeEditorData::typedFields($payload['ingredients'][0]), RecipeEditorData::typedFields($result['ingredients'][0]));
    }

    public function test_search_and_preview_never_disclose_unreadable_recipes(): void
    {
        $actor = User::factory()->contributor()->create();
        $source = Recipe::factory()->create(['author_id' => User::factory()->create()->id]);
        $eligibility = app(RecipeComponentEligibility::class);
        $this->assertNull($eligibility->label($actor, $source->id));
        $this->assertArrayNotHasKey($source->id, $eligibility->search($actor, ''));
        $this->actingAs($actor);
        $this->expectException(ValidationException::class);
        app(RecipeEditorService::class)->create($actor, [...$this->data(), 'ingredients_data' => [$this->componentLine($source)]]);
    }

    public function test_private_component_is_snapshotted_and_pin_and_identity_survive_reorder(): void
    {
        $actor = User::factory()->contributor()->create();
        $this->actingAs($actor);
        $source = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $service = app(RecipeEditorService::class);
        $ordinary = ['ingredient_id' => Ingredient::factory()->create()->id, 'scaling_mode' => 'linear'];
        $recipe = $service->create($actor, [...$this->data(), 'ingredients_data' => [$ordinary, $this->componentLine($source)]]);
        $line = $recipe->components()->firstOrFail();
        $this->assertSame('retained', $line->componentRevision->status->value);
        $data = RecipeEditorData::fromRecipe($recipe);
        $data['ingredients_data'] = array_reverse($data['ingredients_data']);
        $data['ingredients_data'][0]['line_uuid'] = 'forged';
        $service->update($actor, $recipe, $data, $service->version($recipe));
        $this->assertSame($line->line_uuid, $line->fresh()->line_uuid);
        $this->assertSame($line->component_recipe_revision_id, $line->fresh()->component_recipe_revision_id);
        $this->assertSame(0, $line->fresh()->sort_order);
        $data = RecipeEditorData::fromRecipe($recipe->fresh());
        array_shift($data['ingredients_data']);
        $service->update($actor, $recipe, $data, $service->version($recipe));
        $this->assertSame(0, $recipe->components()->count());
    }

    public function test_public_component_uses_published_revision_and_rejects_foreign_pin(): void
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);
        $source = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        app(RecipePublishingService::class)->publish($source);
        $service = app(RecipeEditorService::class);
        $recipe = $service->create($actor, [...$this->data(), 'ingredients_data' => [$this->componentLine($source->fresh())]]);
        $line = $recipe->components()->firstOrFail();
        $this->assertSame('published', $line->componentRevision->status->value);
        $data = RecipeEditorData::fromRecipe($recipe);
        $data['ingredients_data'][0]['component_recipe_revision_id'] = 999999;
        $this->expectException(ValidationException::class);
        $service->update($actor, $recipe, $data, $service->version($recipe));
    }

    public function test_prepared_mode_requires_explicit_mapping(): void
    {
        $actor = User::factory()->contributor()->create();
        $this->actingAs($actor);
        $source = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $line = [...$this->componentLine($source), 'shopping_mode' => 'prepared'];
        $this->expectException(ValidationException::class);
        app(RecipeEditorService::class)->create($actor, [...$this->data(), 'ingredients_data' => [$line]]);
    }

    public function test_reviewers_manage_verified_yields_but_contributors_cannot_forge_them(): void
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);
        $unit = Unit::factory()->create(['unit_type' => 'mass']);
        $yield = ['basis' => 'weight', 'quantity' => '500', 'unit_id' => $unit->id, 'evidence' => ['notes' => 'Weighed batch'], 'verified_by' => 999999];
        $service = app(RecipeEditorService::class);
        $recipe = $service->create($actor, [...$this->data(), 'yields_data' => [$yield]]);
        $this->assertSame($actor->id, $recipe->yields()->firstOrFail()->verified_by);
        $contributor = User::factory()->contributor()->create();
        $this->actingAs($contributor);
        $this->expectException(ValidationException::class);
        $service->create($contributor, [...$this->data(), 'yields_data' => [$yield]]);
    }

    public function test_new_revision_component_retains_its_identity_on_subsequent_saves(): void
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);
        $parent = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $source = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        app(RecipePublishingService::class)->publish($parent);
        $revisions = app(RecipeRevisionService::class);
        $revision = $revisions->createDraft($actor, $parent->fresh());
        $data = RecipeEditorData::fromPayload($revision->payload);
        $data['ingredients_data'][] = $this->componentLine($source);
        $editor = app(RecipeEditorService::class);
        $revision = $editor->saveRevision($actor, $revision, $data, $revisions->draftVersion($revision));
        $first = collect($revision->payload['ingredients'])->firstWhere('line_type', 'recipe');
        $revision = $editor->saveRevision($actor, $revision, RecipeEditorData::fromPayload($revision->payload), $revisions->draftVersion($revision));
        $second = collect($revision->payload['ingredients'])->firstWhere('line_type', 'recipe');
        $this->assertSame($first['line_uuid'], $second['line_uuid']);
        $this->assertSame($first['component_recipe_revision_id'], $second['component_recipe_revision_id']);
        $this->assertSame(0, $parent->components()->count());
    }

    public function test_standalone_shared_form_can_save_a_prepared_component(): void
    {
        $actor = User::factory()->contributor()->create();
        $source = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $data = $this->data();
        $data['el'] = ['title' => 'Σούπα'];
        $data['steps_data'] = [['instruction_en' => 'Stir', 'instruction_el' => 'Ανακατέψτε']];
        $data['ingredients_data'] = [[...$this->componentLine($source), 'shopping_mode' => 'prepared',
            'prepared_ingredient_id' => Ingredient::factory()->create()->id,
            'prepared_quantity_per_batch' => '250', 'prepared_unit_id' => Unit::factory()->create()->id]];
        Filament::setCurrentPanel(null);
        $component = Livewire::actingAs($actor)->test(ContributorRecipeEditorHarness::class)
            ->fillForm($data)->call('save')->assertHasNoFormErrors();
        $parent = Recipe::findOrFail($component->get('savedRecipeId'));
        $line = $parent->components()->firstOrFail();
        $this->assertSame('prepared', $line->shopping_mode);
        $this->assertSame('250.0000', $line->prepared_quantity_per_batch);
        $this->assertSame($data['ingredients_data'][0]['prepared_ingredient_id'], $line->prepared_ingredient_id);
    }

    public function test_pinned_preview_uses_snapshot_title_servings_yield_and_unit(): void
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);
        $source = Recipe::factory()->complete()->create(['author_id' => $actor->id, 'base_servings' => 4]);
        $source->translateOrNew('en')->title = 'Original sauce';
        $source->save();
        $yield = RecipeYield::factory()->for($source)->create(['quantity' => '500']);
        $revision = app(RecipeRevisionService::class)->captureComponentSnapshot($actor, $source);
        $unitCode = $revision->payload['units'][$yield->unit_id]['code'];
        $source->translateOrNew('en')->title = 'Changed sauce';
        $source->base_servings = 8;
        $source->save();
        $yield->update(['quantity' => '900']);
        app()->setLocale('en');
        $eligibility = app(RecipeComponentEligibility::class);
        $label = $eligibility->label($actor, $source->id, revisionId: $revision->id);
        $this->assertStringContainsString('Original sauce', $label);
        $this->assertStringContainsString('4 Servings', $label);
        $this->assertStringContainsString('500', $label);
        $this->assertStringContainsString($unitCode, $label);
        $this->assertStringNotContainsString('900', $label);
        $this->assertNull($eligibility->label($actor, $source->id, revisionId: 999999));
        $this->assertNull($eligibility->label(User::factory()->contributor()->create(), $source->id, revisionId: $revision->id));
    }

    public function test_search_filters_inaccessible_recipes_before_hydrating_candidates(): void
    {
        $actor = User::factory()->contributor()->create();
        $owned = Recipe::factory()->create([
            'author_id' => $actor->id,
            'en' => ['title' => 'Catalogue sauce', 'slug' => 'owned-catalogue-sauce'],
        ]);
        $foreign = Recipe::factory()->count(3)->state(fn (): array => [
            'en' => ['title' => 'Catalogue sauce', 'slug' => fake()->unique()->slug()],
        ])->create(['author_id' => User::factory()->create()->id]);
        $retrieved = [];
        Recipe::retrieved(function (Recipe $recipe) use (&$retrieved): void {
            $retrieved[] = $recipe->id;
        });
        $results = app(RecipeComponentEligibility::class)->search($actor, 'Catalogue sauce');
        $this->assertArrayHasKey($owned->id, $results);
        foreach ($foreign as $recipe) {
            $this->assertNotContains($recipe->id, $retrieved);
            $this->assertArrayNotHasKey($recipe->id, $results);
        }
    }

    #[TestWith(['create', 'persistence'])]
    #[TestWith(['update', 'persistence'])]
    #[TestWith(['create', 'validation'])]
    #[TestWith(['update', 'validation'])]
    public function test_component_snapshot_rolls_back_when_save_fails(string $operation, string $failure): void
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);
        $source = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $parent = $operation === 'update' ? Recipe::factory()->complete()->create(['author_id' => $actor->id]) : null;
        $data = $parent ? RecipeEditorData::fromRecipe($parent) : $this->data();
        $data['ingredients_data'][] = $this->componentLine($source);
        $beforeRecipes = Recipe::count();
        $beforeRevisions = $source->revisions()->count();
        if ($failure === 'validation') {
            $data['ingredients_data'][] = [...$this->componentLine($source), 'quantity' => 0];
        } else {
            $this->mock(SyncRecipeContent::class, function ($mock): void {
                $mock->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Persistence failed'));
            });
        }
        $editor = app(RecipeEditorService::class);
        try {
            if ($parent) {
                $editor->update($actor, $parent, $data, $editor->version($parent));
            } else {
                $editor->create($actor, $data);
            }
            $this->fail('Persistence failure must abort the save.');
        } catch (\RuntimeException|ValidationException $exception) {
            if ($failure === 'validation') {
                $this->assertInstanceOf(ValidationException::class, $exception);
            } else {
                $this->assertSame('Persistence failed', $exception->getMessage());
            }
        }
        $this->assertSame($beforeRecipes, Recipe::count());
        $this->assertSame($beforeRevisions, $source->revisions()->count());
    }

    public function test_revision_snapshot_rolls_back_after_later_media_validation_failure(): void
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);
        $source = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $parent = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        app(RecipePublishingService::class)->publish($parent);
        $revisions = app(RecipeRevisionService::class);
        $revision = $revisions->createDraft($actor, $parent->fresh());
        $data = RecipeEditorData::fromPayload($revision->payload);
        $data['ingredients_data'][] = $this->componentLine($source);
        $data['media_data'][] = ['media_id' => 999999, 'role' => 'gallery'];
        $before = $source->revisions()->count();
        try {
            app(RecipeEditorService::class)->saveRevision($actor, $revision, $data, $revisions->draftVersion($revision));
            $this->fail('Foreign media must abort the revision save.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('media_data', $exception->errors());
        }
        $this->assertSame($before, $source->revisions()->count());
    }

    #[TestWith(['whole_batch'])]
    #[TestWith(['servings'])]
    public function test_manual_component_allows_null_quantity_without_measured_yields(string $basis): void
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);
        $source = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $line = [...$this->componentLine($source), 'scaling_mode' => 'manual', 'quantity' => null, 'quantity_basis' => $basis];
        $recipe = app(RecipeEditorService::class)->create($actor, [...$this->data(), 'ingredients_data' => [$line]]);
        $component = $recipe->components()->firstOrFail();
        $this->assertNull($component->quantity);
        $this->assertSame('manual', $component->scaling_mode);
        $this->assertSame($basis, $component->quantity_basis);
    }

    public function test_weight_component_still_requires_a_verified_source_yield(): void
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);
        $source = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $line = [...$this->componentLine($source), 'quantity_basis' => 'weight', 'unit_id' => Unit::factory()->create()->id];
        $this->expectException(ValidationException::class);
        app(RecipeEditorService::class)->create($actor, [...$this->data(), 'ingredients_data' => [$line]]);
    }

    #[TestWith([null, 'linear'])]
    #[TestWith([0, 'manual'])]
    #[TestWith([-1, 'manual'])]
    public function test_null_automatic_or_nonpositive_component_quantities_are_rejected(?int $quantity, string $scaling): void
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);
        $source = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $line = [...$this->componentLine($source), 'quantity' => $quantity, 'scaling_mode' => $scaling];
        $this->expectException(ValidationException::class);
        app(RecipeEditorService::class)->create($actor, [...$this->data(), 'ingredients_data' => [$line]]);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_legacy_row_type_defaults_to_ingredient_in_shared_form(bool $explicitNull): void
    {
        $actor = User::factory()->contributor()->create();
        $data = $this->data();
        $data['el'] = ['title' => 'Σούπα'];
        $data['steps_data'] = [['instruction_en' => 'Stir', 'instruction_el' => 'Ανακατέψτε']];
        $data['ingredients_data'] = [['ingredient_id' => Ingredient::factory()->create()->id, 'scaling_mode' => 'linear']];
        if ($explicitNull) {
            $data['ingredients_data'][0]['line_type'] = null;
        }
        Filament::setCurrentPanel(null);
        $component = Livewire::actingAs($actor)->test(ContributorRecipeEditorHarness::class)
            ->fillForm($data);
        $line = array_values($component->get('data.ingredients_data'))[0];
        $this->assertSame($data['ingredients_data'][0]['ingredient_id'], $line['ingredient_id']);
        $component->call('save')->assertHasNoFormErrors();
        $recipe = Recipe::findOrFail($component->get('savedRecipeId'));
        $this->assertSame('ingredient', $recipe->recipeIngredients()->firstOrFail()->line_type);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_validator_defaults_legacy_row_type_before_conditional_rules(bool $explicitNull): void
    {
        $actor = User::factory()->contributor()->create();
        $this->actingAs($actor);
        $line = ['ingredient_id' => Ingredient::factory()->create()->id, 'scaling_mode' => 'linear'];
        if ($explicitNull) {
            $line['line_type'] = null;
        }
        $validator = app(RecipeEditorValidator::class);
        $validated = $validator->validate([...$this->data(), 'ingredients_data' => [$line]], new Recipe, $actor);
        $this->assertSame('ingredient', $validated['ingredients_data'][0]['line_type']);
        unset($line['ingredient_id']);
        $line['component_recipe_id'] = Recipe::factory()->create(['author_id' => $actor->id])->id;
        try {
            $validator->validate([...$this->data(), 'ingredients_data' => [$line]], new Recipe, $actor);
            $this->fail('Component fields cannot implicitly select the recipe branch.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ingredients_data.0.ingredient_id', $exception->errors());
        }
    }

    public function test_switching_row_types_clears_incompatible_selections(): void
    {
        $actor = User::factory()->contributor()->create();
        $ingredient = Ingredient::factory()->create();
        Filament::setCurrentPanel(null);
        $component = Livewire::actingAs($actor)->test(ContributorRecipeEditorHarness::class)
            ->fillForm([...$this->data(), 'ingredients_data' => [[
                'line_type' => 'ingredient', 'ingredient_id' => $ingredient->id, 'scaling_mode' => 'linear',
            ]]]);
        $key = array_key_first($component->get('data.ingredients_data'));
        $component->set("data.ingredients_data.$key.line_type", 'recipe')
            ->assertSet("data.ingredients_data.$key.ingredient_id", null);
        $source = Recipe::factory()->create(['author_id' => $actor->id]);
        $component->set("data.ingredients_data.$key.component_recipe_id", $source->id)
            ->set("data.ingredients_data.$key.component_recipe_revision_id", 999999)
            ->set("data.ingredients_data.$key.line_type", null)
            ->assertSet("data.ingredients_data.$key.component_recipe_id", null)
            ->assertSet("data.ingredients_data.$key.component_recipe_revision_id", null);
    }
}
