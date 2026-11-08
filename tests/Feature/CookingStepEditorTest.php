<?php

namespace Tests\Feature;

use App\Actions\SyncRecipeContent;
use App\Models\Difficulty;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeStepGroup;
use App\Models\ScalingMode;
use App\Models\User;
use App\Services\Recipes\RecipeEditorData;
use App\Services\Recipes\RecipeEditorService;
use App\Services\Recipes\RecipeStepGroups;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\ContributorRecipeEditorHarness;
use Tests\TestCase;

class CookingStepEditorTest extends TestCase
{
    use DatabaseTransactions;

    private function data(): array
    {
        return [
            'en' => ['title' => 'Pie'], 'prep_minutes' => 0, 'cook_minutes' => 0,
            'rest_minutes' => 0, 'base_servings' => 4, 'ingredients_data' => [],
            'step_groups_data' => [
                ['key' => 'dough', 'translations' => ['en' => ['title' => 'Dough'], 'el' => ['title' => 'Ζύμη']]],
                ['key' => 'filling', 'translations' => ['en' => ['title' => 'Filling'], 'el' => ['title' => 'Γέμιση']]],
            ],
            'steps_data' => [
                ['instruction_en' => 'Mix.', 'instruction_el' => 'Ανακατέψτε.', 'step_group_key' => 'dough'],
                ['instruction_en' => 'Chop.', 'instruction_el' => 'Κόψτε.', 'step_group_key' => 'filling'],
            ],
        ];
    }

    public function test_shared_service_creates_groups_and_membership(): void
    {
        $actor = User::factory()->contributor()->create();
        $this->actingAs($actor);

        $recipe = app(RecipeEditorService::class)->create($actor, $this->data());

        $this->assertSame(['Dough', 'Filling'], $recipe->stepGroups->map(fn ($group) => $group->translate('en')->title)->all());
        $this->assertSame($recipe->stepGroups->modelKeys(), $recipe->steps()->pluck('recipe_step_group_id')->all());
    }

    public function test_reordering_form_groups_preserves_membership_in_the_payload(): void
    {
        $data = $this->data();
        $data['step_groups_data'] = array_reverse($data['step_groups_data']);

        $payload = RecipeEditorData::toPayload([], $data);

        $this->assertSame([1, 0], array_column($payload['steps'], 'step_group_index'));
        $this->assertSame('Filling', $payload['step_groups'][0]['translations']['en']['title']);
        $this->assertArrayNotHasKey('key', $payload['step_groups'][0]);
    }

    public function test_legacy_payloads_open_without_groups_and_keep_step_media(): void
    {
        $payload = ['steps' => [['id' => 12, 'translations' => ['en' => ['instruction' => 'Mix.']], 'media' => [['media_id' => 9, 'kind' => 'video', 'sort_order' => 0]]]]];
        $data = RecipeEditorData::fromPayload($payload);

        $this->assertSame([], $data['step_groups_data']);
        $this->assertNull($data['steps_data'][0]['step_group_key']);
        $this->assertSame($payload['steps'][0]['media'], RecipeEditorData::toPayload($payload, $data)['steps'][0]['media']);
    }

    public function test_group_roundtrip_keeps_step_membership_after_a_form_reorder(): void
    {
        $payload = RecipeEditorData::toPayload([], $this->data());
        $data = RecipeEditorData::fromPayload($payload);
        $data['step_groups_data'] = array_reverse($data['step_groups_data']);

        $result = RecipeEditorData::toPayload($payload, $data);

        $this->assertSame([1, 0], array_column($result['steps'], 'step_group_index'));
    }

    public function test_unknown_group_keys_cannot_silently_ungroup_steps(): void
    {
        $actor = User::factory()->contributor()->create();
        $this->actingAs($actor);
        $data = $this->data();
        $data['steps_data'][0]['step_group_key'] = 'foreign';

        $this->expectException(ValidationException::class);
        app(RecipeEditorService::class)->create($actor, $data);
    }

    public function test_duplicate_group_keys_are_rejected(): void
    {
        $actor = User::factory()->contributor()->create();
        $this->actingAs($actor);
        $data = $this->data();
        $data['step_groups_data'][1]['key'] = 'dough';

        $this->expectException(ValidationException::class);
        app(RecipeEditorService::class)->create($actor, $data);
    }

    public function test_another_recipes_group_cannot_be_submitted_to_the_shared_editor(): void
    {
        $actor = User::factory()->contributor()->create();
        $this->actingAs($actor);
        $other = RecipeStepGroup::factory()->create();
        $data = $this->data();
        $data['step_groups_data'][0]['id'] = $other->id;

        $this->expectException(ValidationException::class);
        app(RecipeEditorService::class)->create($actor, $data);
    }

    public function test_sync_reorders_and_moves_steps_without_recreating_ids(): void
    {
        $actor = User::factory()->contributor()->create();
        $this->actingAs($actor);
        $recipe = app(RecipeEditorService::class)->create($actor, $this->data());
        $data = $this->data();
        foreach ($recipe->stepGroups as $index => $group) {
            $data['step_groups_data'][$index]['id'] = $group->id;
        }
        foreach ($recipe->steps as $index => $step) {
            $data['steps_data'][$index]['id'] = $step->id;
        }
        $data['step_groups_data'] = array_reverse($data['step_groups_data']);
        $steps = array_reverse($data['steps_data']);
        $steps[0]['step_group_key'] = 'dough';

        app(SyncRecipeContent::class)->handle($recipe, $steps, [], null, $data['step_groups_data']);

        $this->assertSame(array_reverse($recipe->steps->modelKeys()), $recipe->steps()->pluck('id')->all());
        $this->assertSame(['Filling', 'Dough'], $recipe->fresh()->stepGroups->map(fn ($g) => $g->translate('en')->title)->all());
        $this->assertSame([$recipe->stepGroups[0]->id, $recipe->stepGroups[0]->id], $recipe->steps()->pluck('recipe_step_group_id')->all());
    }

    public function test_add_step_action_uses_the_selected_group_without_resetting_other_steps(): void
    {
        config(['cooking.step_groups_enabled' => true]);
        $actor = User::factory()->contributor()->create();
        Filament::setCurrentPanel(null);
        $component = Livewire::actingAs($actor)->test(ContributorRecipeEditorHarness::class)->fillForm($this->data());
        $groups = $component->get('data.step_groups_data');
        $key = array_key_first($groups);

        $component->callAction(TestAction::make('addGroupStep')->schemaComponent('step_groups_data')->arguments(['item' => $key]));

        $steps = array_values($component->get('data.steps_data'));
        $this->assertCount(3, $steps);
        $this->assertSame('dough', $steps[2]['step_group_key']);
        $this->assertSame('Mix.', $steps[0]['instruction_en']);
    }

    public function test_delete_group_action_keeps_its_steps_ungrouped(): void
    {
        config(['cooking.step_groups_enabled' => true]);
        $actor = User::factory()->contributor()->create();
        Filament::setCurrentPanel(null);
        $component = Livewire::actingAs($actor)->test(ContributorRecipeEditorHarness::class)->fillForm($this->data());
        $key = array_key_first($component->get('data.step_groups_data'));

        $component->callAction(TestAction::make('delete')->schemaComponent('step_groups_data')->arguments(['item' => $key]));

        $steps = array_values($component->get('data.steps_data'));
        $this->assertCount(1, $component->get('data.step_groups_data'));
        $this->assertCount(2, $steps);
        $this->assertNull($steps[0]['step_group_key']);
        $this->assertSame('filling', $steps[1]['step_group_key']);
    }

    public function test_empty_groups_are_compatible_with_a_database_awaiting_the_group_migration(): void
    {
        Schema::shouldReceive('hasTable')->with('recipe_step_groups')->andReturn(false);
        $groups = app(RecipeStepGroups::class);

        $this->assertSame([], $groups->sync(new Recipe, []));
        $groups->removeUnused(new Recipe, []);
    }

    public function test_nonempty_groups_require_the_additive_migration(): void
    {
        Schema::shouldReceive('hasTable')->with('recipe_step_groups')->andReturn(false);

        $this->expectException(ValidationException::class);
        app(RecipeStepGroups::class)->sync(new Recipe, $this->data()['step_groups_data']);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_standalone_shared_form_preserves_group_membership_with_visible_or_hidden_controls(bool $enabled): void
    {
        config(['cooking.step_groups_enabled' => $enabled]);
        $actor = User::factory()->contributor()->create();
        Filament::setCurrentPanel(null);

        $component = Livewire::actingAs($actor)->test(ContributorRecipeEditorHarness::class)
            ->fillForm([...$this->data(), 'el' => ['title' => 'Πίτα'], 'difficulty' => Difficulty::factory()->create()->code, 'ingredients_data' => [
                ['ingredient_id' => Ingredient::factory()->create()->id, 'scaling_mode' => ScalingMode::factory()->create()->code],
            ]])->call('save')->assertHasNoFormErrors();

        $recipe = Recipe::findOrFail($component->get('savedRecipeId'));
        $this->assertCount(2, $recipe->stepGroups);
        $this->assertSame($recipe->stepGroups->modelKeys(), $recipe->steps()->pluck('recipe_step_group_id')->all());
    }
}
