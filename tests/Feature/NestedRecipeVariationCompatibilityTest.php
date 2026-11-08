<?php

namespace Tests\Feature;

use App\Livewire\EditRecipeVariation;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeYield;
use App\Models\Unit;
use App\Models\User;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionSnapshotter;
use App\Services\Recipes\RecipeForkService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\CreatesVariationSource;
use Tests\TestCase;

class NestedRecipeVariationCompatibilityTest extends TestCase
{
    use CreatesVariationSource;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['recipe-variations.enabled' => true]);
    }

    private function fork(Recipe $source, User $actor): Recipe
    {
        return app(RecipeForkService::class)->fork($actor, $source, ['idempotency_key' => (string) Str::uuid()]);
    }

    private function nestedSource(): array
    {
        $component = app(RecipePublishingService::class)->publish(Recipe::factory()->complete()->create());
        $source = $this->source(function (Recipe $recipe) use ($component): void {
            RecipeIngredient::factory()->for($recipe)->component($component->currentRevision)->create(['sort_order' => 1]);
            RecipeYield::factory()->for($recipe)->create();
        });

        return [$source, $component];
    }

    public function test_v2_normal_fork_regenerates_line_identity_and_round_trips_the_editor(): void
    {
        $source = $this->source();
        $actor = User::factory()->create();
        $fork = $this->fork($source, $actor);
        $line = $fork->recipeIngredients()->first();
        $this->assertSame('ingredient', $line->line_type);
        $this->assertNotSame($source->recipeIngredients()->first()->line_uuid, $line->line_uuid);
        $this->actingAs($actor);
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $fork->id, 'locale' => 'en'])
            ->set('draft.translations.en.title', 'A v2 variation')->call('save')->assertHasNoErrors();
        $this->assertSame($line->line_uuid, $line->fresh()->line_uuid);
    }

    public function test_fork_keeps_component_revision_references_without_copying_component_content(): void
    {
        [$source, $component] = $this->nestedSource();
        $before = Recipe::query()->count();
        $fork = $this->fork($source, User::factory()->create());
        $sourceLine = $source->components()->first();
        $line = $fork->components()->first();

        $this->assertSame($before + 1, Recipe::query()->count());
        $this->assertSame($source->recipeIngredients()->count(), $fork->recipeIngredients()->count());
        $this->assertSame($component->id, $line->component_recipe_id);
        $this->assertSame($sourceLine->component_recipe_revision_id, $line->component_recipe_revision_id);
        $this->assertNotSame($sourceLine->line_uuid, $line->line_uuid);
        $snapshots = app(RecipeRevisionSnapshotter::class);
        $this->assertSame($snapshots->canonicalJson(['yields' => $source->currentRevision->payload['yields']]), $snapshots->canonicalJson(['yields' => $snapshots->serialize($fork->id)['yields']]));
        $this->assertSame($snapshots->canonicalJson(['units' => $source->currentRevision->payload['units']]), $snapshots->canonicalJson(['units' => $snapshots->serialize($fork->id)['units']]));
        $this->assertSame(0, $fork->revisions()->count());
    }

    public function test_variation_editor_preserves_components_yields_and_pinned_units(): void
    {
        [$source] = $this->nestedSource();
        $actor = User::factory()->create();
        $fork = $this->fork($source, $actor);
        $snapshotter = app(RecipeRevisionSnapshotter::class);
        $before = $snapshotter->serialize($fork->id);
        $line = $fork->components()->first();
        $this->actingAs($actor);
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $fork->id, 'locale' => 'en'])
            ->set('draft.ingredients.1.quantity', '2')->call('save')->assertHasNoErrors();
        $after = $snapshotter->serialize($fork->id);

        $this->assertSame('2.0000', $line->fresh()->quantity);
        $this->assertSame($line->line_uuid, $line->fresh()->line_uuid);
        $this->assertSame($line->component_recipe_revision_id, $line->fresh()->component_recipe_revision_id);
        $this->assertSame($snapshotter->canonicalJson(['yields' => $before['yields']]), $snapshotter->canonicalJson(['yields' => $after['yields']]));
        $this->assertSame($snapshotter->canonicalJson(['units' => $before['units']]), $snapshotter->canonicalJson(['units' => $after['units']]));
        $this->assertSame('1.0000', $source->components()->first()->quantity);
    }

    public function test_fork_pins_normal_unit_definitions_and_rejects_live_redefinition(): void
    {
        $unit = Unit::factory()->create(['base_conversion' => '1']);
        $source = $this->source(fn (Recipe $recipe) => $recipe->recipeIngredients()->first()->update(['unit_id' => $unit->id]));
        try {
            DB::transaction(fn () => DB::table('units')->where('id', $unit->id)->update(['base_conversion' => '10']));
            $this->fail('A retained measurement definition was rewritten.');
        } catch (QueryException) {
            $this->assertSame('1.00000000', $unit->fresh()->base_conversion);
        }
        $actor = User::factory()->create();
        $fork = $this->fork($source, $actor);
        $snapshotter = app(RecipeRevisionSnapshotter::class);
        $this->assertSame('1', $snapshotter->serialize($fork->id)['units'][$unit->id]['base_conversion']);
        $this->actingAs($actor);
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $fork->id, 'locale' => 'en'])
            ->set('draft.units.'.$unit->id.'.base_conversion', '999')->call('save')->assertHasNoErrors();
        $this->assertSame('1', $snapshotter->serialize($fork->id)['units'][$unit->id]['base_conversion']);
    }

    public function test_editor_rejects_forged_line_identity_and_invalid_typed_component_values(): void
    {
        [$source] = $this->nestedSource();
        $actor = User::factory()->create();
        $fork = $this->fork($source, $actor);
        $this->actingAs($actor);
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $fork->id, 'locale' => 'en'])
            ->set('draft.ingredients.0.line_uuid', (string) Str::uuid())->call('save')->assertHasErrors('ingredients.0.line_uuid');
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $fork->id, 'locale' => 'en'])
            ->set('draft.ingredients.1.component_recipe_revision_id', $source->current_revision_id)
            ->call('save')->assertHasErrors('ingredients.1.component_recipe_id');
    }
}
