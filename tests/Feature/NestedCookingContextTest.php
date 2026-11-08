<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ScalingMode;
use App\Models\User;
use App\Services\CookingSessionService;
use App\Services\RecipePublishingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class NestedCookingContextTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cooking.sessions_enabled' => true]);
    }

    private function recipe(): array
    {
        $owner = User::factory()->contributor()->create();
        $this->actingAs(User::factory()->admin()->create());
        $child = Recipe::factory()->complete()->create(['author_id' => $owner->id]);
        $child->steps()->first()->update(['duration_minutes' => 2]);
        app(RecipePublishingService::class)->publish($child);
        $parent = Recipe::factory()->complete()->create(['author_id' => $owner->id]);
        $parent->steps()->first()->update(['duration_minutes' => 1]);
        $first = RecipeIngredient::factory()->for($parent)->component($child->currentRevision)->create()->fresh();
        $second = RecipeIngredient::factory()->for($parent)->component($child->currentRevision)->create()->fresh();
        $this->actingAs($owner);
        $session = app(CookingSessionService::class)->start($owner, $parent->id, (string) Str::uuid());

        return [$owner, $parent, $child, $first->line_uuid, $second->line_uuid, $session];
    }

    public function test_component_progress_and_timers_resume_without_changing_parent_state(): void
    {
        [$owner, , $child, $path, $other, $session] = $this->recipe();
        $service = app(CookingSessionService::class);
        $session = $service->update($owner, $session->id, 1, ['action' => 'timer_start', 'step' => 's0']);
        $parent = $session->state;
        $session = $service->update($owner, $session->id, $session->version, ['action' => 'context_open', 'context' => $path]);
        $session = $service->update($owner, $session->id, $session->version, ['action' => 'timer_start', 'context' => $path, 'step' => 's0']);
        $session = $service->update($owner, $session->id, $session->version, ['action' => 'ingredient', 'context' => $path, 'ingredient' => 'i0', 'checked' => true]);

        $response = $this->getJson('/el/cooking-sessions/'.$session->id)->assertOk()
            ->assertJsonPath('data.state.active_context', $path)
            ->assertJsonPath('data.state.contexts.'.$path.'.steps.s0.status', 'waiting')
            ->assertJsonPath('data.state.contexts.'.$path.'.checked_ingredients.i0', true)
            ->assertJsonPath('data.contexts.'.$path.'.revision_id', $child->current_revision_id)
            ->assertJsonPath('data.contexts.'.$path.'.content.steps.0.translations.el.instruction', 'Μαγειρέψτε το.')
            ->assertJsonPath('data.contexts.'.$path.'.media', []);
        $this->assertSame($parent['steps'], $session->state['steps']);
        $this->assertSame($parent['timers'], $session->state['timers']);
        $this->assertArrayNotHasKey($other, $response->json('data.state.contexts'));
        $session = $service->update($owner, $session->id, $session->version, ['action' => 'context_close']);
        $this->assertNull($session->state['active_context']);
        $this->assertSame($parent['active_step'], $session->state['active_step']);
        $this->assertArrayHasKey('s0', $session->state['contexts'][$path]['timers']);
    }

    public function test_an_unfinished_child_keeps_the_session_resumable_after_parent_completion(): void
    {
        [$owner, $parent, , $path, , $session] = $this->recipe();
        $service = app(CookingSessionService::class);
        $session = $service->update($owner, $session->id, 1, ['action' => 'context_open', 'context' => $path]);
        $session = $service->update($owner, $session->id, $session->version, ['action' => 'timer_start', 'context' => $path, 'step' => 's0']);
        $session = $service->update($owner, $session->id, $session->version, ['action' => 'step_status', 'step' => 's0', 'status' => 'completed']);

        $this->assertSame('active', $session->status);
        $this->assertSame($session->id, $service->latest($owner, $parent->id)->id);
        $session = $service->update($owner, $session->id, $session->version, ['action' => 'step_status', 'context' => $path, 'step' => 's0', 'status' => 'completed']);
        $this->assertSame('completed', $session->status);
    }

    public function test_private_sessions_with_no_public_slug_still_expose_authorized_child_contexts(): void
    {
        [, $parent, , $path, , $session] = $this->recipe();
        $parent->translations()->delete();

        $this->getJson('/el/cooking-sessions/'.$session->id)->assertOk()
            ->assertJsonPath('data.components.0.preview_url', null)
            ->assertJsonPath('data.contexts.'.$path.'.content.steps.0.translations.el.instruction', 'Μαγειρέψτε το.');
    }

    public function test_manual_component_context_does_not_guess_ingredient_amounts(): void
    {
        [$owner, $parent, , $path] = $this->recipe();
        ScalingMode::query()->firstOrCreate(['code' => 'manual'], ['sort_order' => 30]);
        $parent->components()->where('line_uuid', $path)->update(['quantity' => null, 'scaling_mode' => 'manual']);
        $session = app(CookingSessionService::class)->start($owner, $parent->id, (string) Str::uuid());

        $this->getJson('/en/cooking-sessions/'.$session->id)->assertOk()
            ->assertJsonPath('data.contexts.'.$path.'.manual_quantity', true)
            ->assertJsonPath('data.contexts.'.$path.'.ingredients', [])
            ->assertJsonPath('data.contexts.'.$path.'.components', [])
            ->assertJsonPath('data.components.0.can_use_prepared', false);
    }

    public function test_unknown_contexts_and_independent_component_servings_are_rejected(): void
    {
        [, , , $path, , $session] = $this->recipe();
        $this->patchJson('/en/cooking-sessions/'.$session->id, ['version' => 1,
            'event' => ['action' => 'context_open', 'context' => (string) Str::uuid()]])->assertUnprocessable()->assertJsonValidationErrors('context');
        $this->patchJson('/en/cooking-sessions/'.$session->id, ['version' => 1,
            'event' => ['action' => 'servings', 'context' => $path, 'servings' => 10]])->assertUnprocessable()->assertJsonValidationErrors('context');
        $this->assertSame(1, $session->fresh()->version);
    }

    public function test_revoking_a_child_blocks_the_entire_pinned_context_session(): void
    {
        [$owner, , $child, $path, , $session] = $this->recipe();
        $session = app(CookingSessionService::class)->update($owner, $session->id, 1, ['action' => 'context_open', 'context' => $path]);
        app(RecipePublishingService::class)->withdraw($owner, $child);

        $this->getJson('/en/cooking-sessions/'.$session->id)->assertNotFound();
        $this->patchJson('/en/cooking-sessions/'.$session->id, ['version' => $session->version,
            'event' => ['action' => 'timer_start', 'context' => $path, 'step' => 's0']])->assertNotFound();
    }
}
