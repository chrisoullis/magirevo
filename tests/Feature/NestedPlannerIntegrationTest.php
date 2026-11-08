<?php

namespace Tests\Feature;

use App\Enums\MealType;
use App\Models\Ingredient;
use App\Models\IngredientSubstitution;
use App\Models\MealPlan;
use App\Models\MealPlanEntryComponentMode;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeRevision;
use App\Models\User;
use App\Services\MealPlanService;
use App\Services\MealPlanTemplateService;
use App\Services\PlanIngredientCollector;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use App\Services\ShoppingListService;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NestedPlannerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function nestedPlan(): array
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);
        $source = Recipe::factory()->complete()->create(['author_id' => $actor->id, 'base_servings' => 2]);
        $raw = $source->recipeIngredients()->firstOrFail();
        $raw->update(['quantity' => '100', 'unit_id' => null]);
        app(RecipePublishingService::class)->publish($source);
        $revision = $source->fresh()->currentRevision;
        $prepared = Ingredient::factory()->create();
        $root = Recipe::factory()->create(['author_id' => $actor->id, 'visibility' => 'private', 'base_servings' => 2]);
        $component = RecipeIngredient::factory()->for($root)->component($revision)->create([
            'quantity' => '1', 'prepared_ingredient_id' => $prepared->id, 'prepared_quantity_per_batch' => '1',
        ])->fresh();
        $plan = MealPlan::factory()->create(['created_by' => $actor->id]);
        $entry = app(MealPlanService::class)->addEntry($actor, $plan, $root, $plan->week_start, MealType::Dinner, 2);

        return [$actor, $plan, $entry, $component, $raw, $prepared];
    }

    public function test_private_roots_are_pinned_and_live_edits_do_not_change_requirements(): void
    {
        [$actor, $plan, $entry, $component, $raw] = $this->nestedPlan();
        $pin = RecipeRevision::findOrFail($entry->planned_recipe_revision_id);
        $this->assertSame('retained', $pin->getRawOriginal('status'));
        $component->update(['quantity' => '3']);
        $result = app(PlanIngredientCollector::class)->collect($plan);
        $this->assertSame([], $result['excluded_entry_ids']);
        $this->assertCount(1, $result['lines']);
        $this->assertSame($raw->ingredient_id, $result['lines'][0]->ingredientId);
        $this->assertTrue(BigDecimal::of($result['lines'][0]->quantity)->isEqualTo('100'));
        $this->assertCount(1, app(MealPlanService::class)->weekView($actor, $plan, 'en')->flatten(2)->first()['components']);
    }

    public function test_modes_are_per_entry_and_saved_without_regenerating_or_expanding_prepared_leaves(): void
    {
        [$actor, $plan, $first, $component, $raw, $prepared] = $this->nestedPlan();
        $second = app(MealPlanService::class)->addEntry($actor, $plan, $first->recipe, $plan->week_start, MealType::Lunch, 2);
        $lists = app(ShoppingListService::class);
        $list = $lists->ensureList($actor, $plan);
        $lists->generate($actor, $list);
        $before = $list->items()->get()->toArray();
        $lists->setComponentMode($actor, $first, $component->line_uuid, 'prepared');
        $this->assertSame($before, $list->items()->get()->toArray());
        $this->assertSame(1, MealPlanEntryComponentMode::where('meal_plan_entry_id', $first->id)->count());
        $this->assertSame(0, MealPlanEntryComponentMode::where('meal_plan_entry_id', $second->id)->count());
        $lines = collect(app(PlanIngredientCollector::class)->collect($plan)['lines']);
        $this->assertCount(2, $lines);
        $this->assertSame([$raw->ingredient_id, $prepared->id], $lines->pluck('ingredientId')->sort()->values()->all());
        $lists->generate($actor, $list);
        $this->assertSame(2, $list->items()->where('is_manual', false)->count());
    }

    public function test_repeated_occurrences_contribute_once_each(): void
    {
        [$actor, $plan, $entry, $component] = $this->nestedPlan();
        RecipeIngredient::factory()->for($entry->recipe)->component($component->componentRevision)->create(['quantity' => '1']);
        app(MealPlanService::class)->acknowledgeRecipeUpdate($actor, $entry, $entry->version);
        $result = app(PlanIngredientCollector::class)->collect($plan);
        $this->assertCount(2, $result['lines']);
        $this->assertSame(200.0, collect($result['lines'])->sum(fn ($line) => (float) $line->quantity));
        $this->assertNotSame($result['lines'][0]->source['path'], $result['lines'][1]->source['path']);
    }

    public function test_manual_nested_quantity_excludes_the_whole_entry(): void
    {
        [$actor, $plan, $entry, $component] = $this->nestedPlan();
        $component->update(['quantity' => null, 'scaling_mode' => 'manual']);
        $entry = app(MealPlanService::class)->acknowledgeRecipeUpdate($actor, $entry, $entry->version);
        $result = app(PlanIngredientCollector::class)->collect($plan);
        $this->assertSame([], $result['lines']);
        $this->assertSame([$entry->id], $result['excluded_entry_ids']);
    }

    public function test_generated_items_retain_exact_revision_paths_only_on_explicit_regeneration(): void
    {
        [$actor, $plan, $entry, $component, $raw] = $this->nestedPlan();
        $lists = app(ShoppingListService::class);
        $list = $lists->ensureList($actor, $plan);
        $lists->generate($actor, $list);
        $item = $list->items()->where('ingredient_id', $raw->ingredient_id)->firstOrFail();
        $sources = $item->source_requirements;
        $this->assertCount(1, $sources);
        $this->assertSame($entry->id, $sources[0]['entry_id']);
        $this->assertSame($component->component_recipe_revision_id, $sources[0]['recipe_revision_id']);
        $this->assertSame([$entry->planned_recipe_revision_id, $component->component_recipe_revision_id], array_column($sources[0]['revision_path'], 'recipe_revision_id'));
        $this->assertArrayNotHasKey('source_requirements', $item->toArray());
        $version = $item->version;
        $lists->generate($actor, $list);
        $this->assertSame($version, $item->fresh()->version);
        $component->update(['quantity' => '3']);
        $this->assertSame($sources, $item->fresh()->source_requirements);
        $this->assertSame('100.0000', $item->fresh()->quantity);
    }

    public function test_recipe_specific_substitutions_cannot_jump_from_a_new_child_revision_to_an_old_pin(): void
    {
        [$actor, , $entry, , $raw] = $this->nestedPlan();
        $child = $raw->recipe->fresh();
        $revisions = app(RecipeRevisionService::class);
        $draft = $revisions->createDraft($actor, $child);
        $payload = $draft->payload;
        $payload['translations']['en']['summary'] = 'New child revision.';
        $published = $revisions->approve(User::factory()->admin()->create(), $revisions->submit($actor, $revisions->saveDraft($actor, $draft, $payload)));
        $substitution = IngredientSubstitution::factory()->approved()->create([
            'ingredient_id' => $raw->ingredient_id, 'replacement_ingredient_id' => Ingredient::factory()->create()->id,
            'recipe_id' => $child->id,
        ]);
        $this->assertSame($published->id, $substitution->validated_revision_id);

        $this->expectException(ValidationException::class);
        app(MealPlanService::class)->validatedSubstitutionChoices($entry->recipe, [$raw->id => [
            'substitution_id' => $substitution->id, 'replacement_ingredient_id' => $substitution->replacement_ingredient_id,
        ]], $actor);
    }

    public function test_templates_pin_private_roots_when_applied(): void
    {
        [$actor, $plan] = $this->nestedPlan();
        $templates = app(MealPlanTemplateService::class);
        $template = $templates->saveFromPlan($actor, $plan, 'Nested week')['template'];
        $target = MealPlan::factory()->create(['created_by' => $actor->id, 'week_start' => $plan->week_start->addWeek()]);
        $this->assertSame(1, $templates->apply($actor, $template, $target)['added']);
        $this->assertNotNull($target->entries()->firstOrFail()->planned_recipe_revision_id);
        $this->assertSame([], app(PlanIngredientCollector::class)->collect($target)['excluded_entry_ids']);
    }
}
