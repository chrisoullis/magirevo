<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeYield;
use App\Models\Unit;
use App\Models\User;
use App\Services\IngredientConsolidator;
use App\Services\RecipeComponentResolver;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use App\Services\RecipeRevisionSnapshotter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NestedRecipeResolverTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['whole_batch', '0.5', null, '100.0000'])]
    #[TestWith(['servings', '1', null, '100.0000'])]
    #[TestWith(['weight', '125', 'mass', '100.0000'])]
    #[TestWith(['volume', '125', 'volume', '100.0000'])]
    public function test_nested_amounts_scale_against_the_referenced_batch(string $basis, string $quantity, ?string $family, string $expected): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$source, $ingredient, $unit] = $this->source($family);
        $parent = Recipe::factory()->create(['author_id' => auth()->id(), 'base_servings' => '4']);
        RecipeIngredient::factory()->for($parent)->component($source)->create([
            'quantity_basis' => $basis, 'quantity' => $quantity, 'unit_id' => $family ? $unit->id : null,
        ]);
        $result = app(RecipeComponentResolver::class)->resolve($parent, auth()->user(), '8');
        $this->assertSame([], $result['unresolved']);
        $this->assertCount(1, $result['lines']);
        $this->assertSame($ingredient->id, $result['lines'][0]->ingredientId);
        $this->assertSame($expected, $result['lines'][0]->quantity);
        $this->assertSame($source->id, $result['components'][0]['revision_id']);
    }

    public function test_repeated_occurrences_add_their_amounts_without_duplicate_expansion(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$source] = $this->source();
        $parent = Recipe::factory()->create(['author_id' => auth()->id()]);
        RecipeIngredient::factory()->count(2)->for($parent)->component($source)->create(['quantity' => '0.5']);
        $result = app(RecipeComponentResolver::class)->resolve($parent, auth()->user());
        $this->assertSame([], $result['unresolved']);
        $this->assertCount(2, $result['lines']);
        $this->assertCount(2, $result['components']);
        $total = app(IngredientConsolidator::class)->consolidate($result['lines']);
        $this->assertSame('100.0000', $total[0]->quantity);
        $this->assertNotSame($result['components'][0]['path'], $result['components'][1]['path']);
    }

    public function test_tiny_batch_fractions_and_ranges_round_only_final_leaf_amounts(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$source] = $this->source(leafQuantity: '100000');
        $parent = Recipe::factory()->create(['author_id' => auth()->id(), 'base_servings' => '2']);
        RecipeIngredient::factory()->for($parent)->component($source)->create(['quantity' => '0.0001', 'quantity_max' => '0.0002']);
        $result = app(RecipeComponentResolver::class)->resolve($parent, auth()->user(), '0.2');
        $this->assertSame([], $result['unresolved']);
        $this->assertSame('1.0000', $result['lines'][0]->quantity);
        $this->assertSame('2.0000', $result['lines'][0]->quantityMax);
    }

    public function test_prepared_mode_replaces_leaves_and_a_mode_override_restores_scratch_requirements(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$source, $raw, $unit] = $this->source();
        $prepared = Ingredient::factory()->create();
        $parent = Recipe::factory()->create(['author_id' => auth()->id()]);
        $line = RecipeIngredient::factory()->for($parent)->component($source)->create([
            'shopping_mode' => 'prepared', 'prepared_ingredient_id' => $prepared->id,
            'prepared_quantity_per_batch' => '250', 'prepared_unit_id' => $unit->id, 'quantity' => '0.5',
        ])->fresh();
        $resolver = app(RecipeComponentResolver::class);
        $ready = $resolver->resolve($parent, auth()->user());
        $scratch = $resolver->resolve($parent, auth()->user(), null, [$line->line_uuid => 'scratch']);
        $this->assertSame([], $ready['unresolved']);
        $this->assertCount(1, $ready['lines']);
        $this->assertSame($prepared->id, $ready['lines'][0]->ingredientId);
        $this->assertSame('125.0000', $ready['lines'][0]->quantity);
        $this->assertCount(1, $scratch['lines']);
        $this->assertSame($raw->id, $scratch['lines'][0]->ingredientId);
        $this->assertSame('50.0000', $scratch['lines'][0]->quantity);
    }

    public function test_repeating_serving_fractions_round_at_the_leaf_without_throwing(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$source] = $this->source(baseServings: '3');
        $parent = Recipe::factory()->create(['author_id' => auth()->id()]);
        RecipeIngredient::factory()->for($parent)->component($source)->create(['quantity_basis' => 'servings', 'quantity' => '1']);

        $result = app(RecipeComponentResolver::class)->resolve($parent, auth()->user());

        $this->assertSame([], $result['unresolved']);
        $this->assertSame('33.3333', $result['lines'][0]->quantity);
    }

    public function test_nested_shopping_sources_retain_each_exact_revision_and_occurrence(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$source] = $this->source();
        $middle = Recipe::factory()->complete()->create(['author_id' => auth()->id()]);
        $middle->recipeIngredients()->delete();
        $child = RecipeIngredient::factory()->for($middle)->component($source)->create()->fresh();
        app(RecipePublishingService::class)->publish($middle);
        $middle->refresh();
        $root = Recipe::factory()->create(['author_id' => auth()->id()]);
        $occurrence = RecipeIngredient::factory()->for($root)->component($middle->currentRevision)->create()->fresh();
        $snapshot = app(RecipeRevisionService::class)->captureComponentSnapshot(auth()->user(), $root);

        $result = app(RecipeComponentResolver::class)->resolve($snapshot, auth()->user());

        $this->assertSame([], $result['unresolved']);
        $provenance = $result['lines'][0]->source;
        $this->assertSame($source->id, $provenance['recipe_revision_id']);
        $this->assertSame([$snapshot->id, $middle->current_revision_id, $source->id], array_column($provenance['revision_path'], 'recipe_revision_id'));
        $this->assertSame([$occurrence->line_uuid, $child->line_uuid], $provenance['component_path']);
    }

    public function test_positive_amounts_below_supported_precision_do_not_become_zero_shopping_lines(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$source] = $this->source(leafQuantity: '0.0001');
        $parent = Recipe::factory()->create(['author_id' => auth()->id()]);
        RecipeIngredient::factory()->for($parent)->component($source)->create(['quantity' => '0.0001']);

        $result = app(RecipeComponentResolver::class)->resolve($parent, auth()->user());

        $this->assertSame([], $result['lines']);
        $this->assertNotEmpty($result['unresolved']);
    }

    public function test_repeated_occurrences_reuse_revision_and_ingredient_queries_per_resolution(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$source] = $this->source();
        $parent = Recipe::factory()->create(['author_id' => auth()->id()]);
        RecipeIngredient::factory()->count(20)->for($parent)->component($source)->create();
        $payload = app(RecipeRevisionSnapshotter::class)->serialize($parent->id);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(RecipeComponentResolver::class)->assertValidPayload($payload, $parent->id, auth()->user());
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame(1, $queries->filter(fn ($query) => str_contains($query['query'], 'from "recipe_revisions"'))->count());
        $this->assertSame(1, $queries->filter(fn ($query) => str_contains($query['query'], 'from "ingredients"'))->count());
    }

    public function test_missing_yield_is_unresolved_without_exposing_partial_ingredients(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$source, , $unit] = $this->source();
        $parent = Recipe::factory()->create(['author_id' => auth()->id()]);
        RecipeIngredient::factory()->for($parent)->create();
        RecipeIngredient::factory()->for($parent)->component($source)->create(['quantity_basis' => 'weight', 'quantity' => '100', 'unit_id' => $unit->id]);
        $result = app(RecipeComponentResolver::class)->resolve($parent, auth()->user());
        $this->assertSame([], $result['lines']);
        $this->assertNotEmpty($result['unresolved']);
    }

    public function test_manual_handling_is_visible_but_does_not_invent_a_shopping_total(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$source] = $this->source();
        $parent = Recipe::factory()->create(['author_id' => auth()->id()]);
        RecipeIngredient::factory()->for($parent)->component($source)->create(['quantity' => null, 'scaling_mode' => 'manual']);
        $resolver = app(RecipeComponentResolver::class);
        $this->assertTrue($resolver->canResolve($parent, auth()->user()));
        $shopping = $resolver->resolve($parent, auth()->user());
        $display = $resolver->resolve($parent, auth()->user(), null, [], 'display');
        $this->assertSame([], $shopping['lines']);
        $this->assertSame('manual_quantity', $shopping['unresolved'][0]['code']);
        $this->assertSame([], $display['unresolved']);
        $this->assertCount(1, $display['components']);
    }

    public function test_revoked_source_access_fails_closed_for_anonymous_and_non_owner_views(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$source] = $this->source();
        $parent = Recipe::factory()->create(['author_id' => auth()->id()]);
        RecipeIngredient::factory()->for($parent)->component($source)->create();
        DB::table('recipes')->where('id', $source->recipe_id)->update(['visibility' => 'private']);
        $result = app(RecipeComponentResolver::class)->resolve($parent, User::factory()->create());
        $this->assertSame([], $result['lines']);
        $this->assertSame([], $result['components']);
        $this->assertNotEmpty($result['unresolved']);
    }

    public function test_a_public_parent_fails_closed_even_for_staff_when_its_source_becomes_private(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$source] = $this->source();
        $parent = Recipe::factory()->complete()->create(['author_id' => auth()->id()]);
        RecipeIngredient::factory()->for($parent)->component($source)->create();
        app(RecipePublishingService::class)->publish($parent);
        DB::table('recipes')->where('id', $source->recipe_id)->update(['visibility' => 'private']);
        foreach ([null, auth()->user()] as $viewer) {
            $result = app(RecipeComponentResolver::class)->resolve($parent->fresh(), $viewer);
            $this->assertSame([], $result['lines']);
            $this->assertSame([], $result['components']);
            $this->assertNotEmpty($result['unresolved']);
        }
    }

    public function test_source_live_edits_cannot_change_a_pinned_component_amount(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$source] = $this->source();
        $parent = Recipe::factory()->create(['author_id' => auth()->id()]);
        RecipeIngredient::factory()->for($parent)->component($source)->create();
        $source->recipe->recipeIngredients()->firstOrFail()->update(['quantity' => '999']);
        $result = app(RecipeComponentResolver::class)->resolve($parent, auth()->user());
        $this->assertSame([], $result['unresolved']);
        $this->assertSame('100.0000', $result['lines'][0]->quantity);
    }

    public function test_circular_dependency_validation_uses_recipe_identity(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$source] = $this->source();
        $parent = Recipe::factory()->create(['author_id' => auth()->id()]);
        RecipeIngredient::factory()->for($parent)->component($source)->create();
        $payload = app(RecipeRevisionSnapshotter::class)->serialize($parent->id);
        $this->expectException(ValidationException::class);
        app(RecipeComponentResolver::class)->assertValidPayload($payload, $source->recipe_id, auth()->user());
    }

    public function test_draft_revision_content_is_never_available_through_the_resolver(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$source] = $this->source();
        $revision = app(RecipeRevisionService::class)->createDraft(auth()->user(), $source->recipe);
        $result = app(RecipeComponentResolver::class)->resolve($revision);
        $this->assertSame([], $result['lines']);
        $this->assertSame([], $result['components']);
        $this->assertNotEmpty($result['unresolved']);
    }

    public function test_expansion_limits_bound_repeated_ingredient_occurrences(): void
    {
        $ingredient = Ingredient::factory()->create();
        $payload = ['ingredients' => array_fill(0, RecipeComponentResolver::MAX_EXPANDED_LINES + 1, ['ingredient_id' => $ingredient->id])];
        $this->expectException(ValidationException::class);
        app(RecipeComponentResolver::class)->assertValidPayload($payload, 0);
    }

    public function test_a_deleted_historical_ingredient_makes_the_component_unresolved(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$source, $ingredient] = $this->source();
        $parent = Recipe::factory()->create(['author_id' => auth()->id()]);
        RecipeIngredient::factory()->for($parent)->component($source)->create();
        $source->recipe->recipeIngredients()->delete();
        $ingredient->delete();
        $result = app(RecipeComponentResolver::class)->resolve($parent, auth()->user());
        $this->assertSame([], $result['lines']);
        $this->assertSame([], $result['components']);
        $this->assertNotEmpty($result['unresolved']);
    }

    private function source(?string $yieldFamily = null, string $leafQuantity = '100', string $baseServings = '2'): array
    {
        $recipe = Recipe::factory()->complete()->create(['base_servings' => $baseServings]);
        $unit = Unit::factory()->create(['unit_type' => $yieldFamily ?? 'mass', 'base_conversion' => 1]);
        $line = $recipe->recipeIngredients()->firstOrFail();
        $line->update(['quantity' => $leafQuantity, 'unit_id' => $unit->id]);
        if ($yieldFamily !== null) {
            RecipeYield::factory()->for($recipe)->create(['basis' => $yieldFamily === 'mass' ? 'weight' : 'volume', 'quantity' => '250', 'unit_id' => $unit->id]);
        }
        app(RecipePublishingService::class)->publish($recipe);

        return [$recipe->fresh()->currentRevision, $line->ingredient, $unit];
    }
}
