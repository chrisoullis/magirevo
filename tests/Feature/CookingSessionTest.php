<?php

namespace Tests\Feature;

use App\Models\CookingSession;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeRevision;
use App\Models\RecipeShare;
use App\Models\RecipeStepGroup;
use App\Models\User;
use App\Services\CookingSessionService;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use App\Services\RecipeRevisionSnapshotter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CookingSessionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cooking.sessions_enabled' => true]);
    }

    private function recipe(User $owner): Recipe
    {
        $recipe = Recipe::factory()->complete()->create(['author_id' => $owner->id]);
        $dough = RecipeStepGroup::factory()->for($recipe)->create([
            'en' => ['title' => 'Dough'], 'el' => ['title' => 'Ζύμη'],
        ]);
        $filling = RecipeStepGroup::factory()->for($recipe)->create([
            'position' => 1, 'en' => ['title' => 'Filling'], 'el' => ['title' => 'Γέμιση'],
        ]);
        $recipe->steps()->first()->update(['recipe_step_group_id' => $dough->id, 'duration_minutes' => 1]);
        $recipe->steps()->create([
            'step_number' => 2, 'recipe_step_group_id' => $filling->id, 'duration_minutes' => 2,
            'en' => ['instruction' => 'Chop the filling.'], 'el' => ['instruction' => 'Κόψτε τη γέμιση.'],
        ]);

        return $recipe;
    }

    private function start(User $actor, Recipe $recipe): CookingSession
    {
        return app(CookingSessionService::class)->start($actor, $recipe->id, (string) Str::uuid());
    }

    private function event(User $actor, CookingSession $session, array $event): CookingSession
    {
        return app(CookingSessionService::class)->update($actor, $session->id, $session->version, $event);
    }

    public function test_authenticated_users_can_start_and_resume_a_private_immutable_session(): void
    {
        $owner = User::factory()->contributor()->create();
        $recipe = $this->recipe($owner);
        $response = $this->actingAs($owner)->postJson('/en/recipes/'.$recipe->id.'/cooking-sessions', [
            'request_key' => (string) Str::uuid(), 'servings' => '6.5',
        ])->assertCreated()->assertJsonPath('data.servings', '6.50')->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.content.step_groups.0.translations.el.title', 'Ζύμη')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $id = $response->json('data.id');
        $session = CookingSession::findOrFail($id);
        $this->assertSame('retained', $session->revision->getRawOriginal('status'));
        $this->assertSame('draft', $recipe->fresh()->status);
        $this->assertNull($recipe->fresh()->current_revision_id);
        $this->assertInstanceOf(\stdClass::class, json_decode($response->getContent())->data->state->timers);

        $this->getJson('/el/cooking-sessions/'.$id)->assertOk()->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.content.steps.0.translations.en.instruction', 'Cook it.');
        $this->getJson('/en/recipes/'.$recipe->id.'/cooking-session')->assertOk()->assertJsonPath('data.id', $id);
        $this->patchJson('/en/cooking-sessions/'.$id, ['version' => 1, 'event' => ['action' => 'visit', 'step' => 's1']])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.state.active_step', 's1');
        $this->getJson('/el/cooking-sessions/'.$id)->assertOk()->assertJsonPath('data.state.last_steps.g1', 's1');
    }

    public function test_private_edits_do_not_change_an_existing_sessions_instructions_or_groups(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->recipe($owner);
        $session = $this->start($owner, $recipe);
        $payload = $session->revision->payload;
        $recipe->steps()->first()->fill(['en' => ['instruction' => 'Changed instructions']])->save();
        $recipe->stepGroups()->first()->fill(['en' => ['title' => 'Changed group']])->save();

        $resumed = app(CookingSessionService::class)->read($owner, $session->id);
        $another = $this->start($owner, $recipe);

        $this->assertSame($payload, $resumed->revision->payload);
        $this->assertNotSame($resumed->recipe_revision_id, $another->recipe_revision_id);
        $this->assertSame('Changed instructions', $another->revision->payload['steps'][0]['translations']['en']['instruction']);
    }

    public function test_start_retries_are_idempotent_without_resetting_progress(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->recipe($owner);
        $key = (string) Str::uuid();
        $service = app(CookingSessionService::class);
        $session = $service->start($owner, $recipe->id, $key);
        $session = $this->event($owner, $session, ['action' => 'step_status', 'step' => 's0', 'status' => 'completed']);

        $retried = $service->start($owner, $recipe->id, $key, '9');

        $this->assertSame($session->id, $retried->id);
        $this->assertSame($session->state, $retried->state);
        $this->assertSame('2.00', $retried->servings);
        $this->assertSame(1, CookingSession::where('user_id', $owner->id)->count());
    }

    public function test_distinct_sessions_have_independent_progress(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->recipe($owner);
        $a = $this->start($owner, $recipe);
        $b = $this->start($owner, $recipe);

        $a = $this->event($owner, $a, ['action' => 'timer_start', 'step' => 's0']);

        $this->assertSame([], $b->fresh()->state['timers']);
        $this->assertSame('not_started', $b->fresh()->state['steps']['s0']['status']);
        $this->assertSame('waiting', $a->state['steps']['s0']['status']);
    }

    public function test_switching_groups_keeps_each_last_step_and_does_not_complete_steps(): void
    {
        $owner = User::factory()->create();
        $session = $this->start($owner, $this->recipe($owner));
        $session = $this->event($owner, $session, ['action' => 'timer_start', 'step' => 's0']);
        $deadline = $session->state['timers']['s0']['deadline_ms'];

        $session = $this->event($owner, $session, ['action' => 'visit', 'step' => 's1']);
        $session = $this->event($owner, $session, ['action' => 'visit', 'step' => 's0']);

        $this->assertSame('s0', $session->state['last_steps']['g0']);
        $this->assertSame('s1', $session->state['last_steps']['g1']);
        $this->assertSame('waiting', $session->state['steps']['s0']['status']);
        $this->assertSame('in_progress', $session->state['steps']['s1']['status']);
        $this->assertSame($deadline, $session->state['timers']['s0']['deadline_ms']);
        $this->assertSame('active', $session->status);
    }

    public function test_multiple_timers_survive_resume_and_expiry_never_completes_a_step(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $session = $this->start($owner, $this->recipe($owner));
        $session = $this->event($owner, $session, ['action' => 'timer_start', 'step' => 's0']);
        $session = $this->event($owner, $session, ['action' => 'timer_start', 'step' => 's1']);
        $version = $session->version;
        $stored = $session->state;
        $this->travel(61)->seconds();

        $response = $this->actingAs($owner)->getJson('/en/cooking-sessions/'.$session->id)->assertOk();

        $response->assertJsonPath('data.state.timers.s0.status', 'finished')->assertJsonPath('data.state.timers.s0.remaining_ms', 0)
            ->assertJsonPath('data.state.timers.s1.status', 'running')->assertJsonPath('data.state.steps.s0.status', 'waiting');
        $this->assertSame($version, $session->fresh()->version);
        $this->assertSame($stored, $session->fresh()->state);
    }

    public function test_pause_resume_restart_cancel_and_duplicate_start_use_server_time(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $session = $this->start($owner, $this->recipe($owner));
        $session = $this->event($owner, $session, ['action' => 'timer_start', 'step' => 's0']);
        $deadline = $session->state['timers']['s0']['deadline_ms'];
        $version = $session->version;
        $session = $this->event($owner, $session, ['action' => 'timer_start', 'step' => 's0']);
        $this->assertSame($version, $session->version);
        $this->assertSame($deadline, $session->state['timers']['s0']['deadline_ms']);
        $this->travel(10)->seconds();
        $session = $this->event($owner, $session, ['action' => 'timer_pause', 'step' => 's0']);
        $this->assertSame(50000, $session->state['timers']['s0']['remaining_ms']);
        $this->travel(90)->seconds();
        $session = $this->event($owner, $session, ['action' => 'timer_resume', 'step' => 's0']);
        $this->assertSame(now()->getTimestampMs() + 50000, $session->state['timers']['s0']['deadline_ms']);
        $session = $this->event($owner, $session, ['action' => 'timer_restart', 'step' => 's0']);
        $this->assertSame(now()->getTimestampMs() + 60000, $session->state['timers']['s0']['deadline_ms']);
        $session = $this->event($owner, $session, ['action' => 'timer_cancel', 'step' => 's0']);
        $this->assertSame([], $session->state['timers']);
        $this->assertSame('in_progress', $session->state['steps']['s0']['status']);
    }

    public function test_completed_steps_can_reopen_without_losing_other_groups_progress(): void
    {
        $owner = User::factory()->create();
        $session = $this->start($owner, $this->recipe($owner));
        foreach (['s0', 's1'] as $key) {
            $session = $this->event($owner, $session, ['action' => 'step_status', 'step' => $key, 'status' => 'completed']);
        }
        $this->assertSame('completed', $session->status);

        $session = $this->event($owner, $session, ['action' => 'step_status', 'step' => 's0', 'status' => 'in_progress']);

        $this->assertSame('active', $session->status);
        $this->assertNull($session->state['steps']['s0']['completed_at']);
        $this->assertSame('completed', $session->state['steps']['s1']['status']);
    }

    public function test_servings_and_ingredient_checks_resume_on_another_device(): void
    {
        $owner = User::factory()->create();
        $session = $this->start($owner, $this->recipe($owner));
        $session = $this->event($owner, $session, ['action' => 'ingredient', 'ingredient' => 'i0', 'checked' => true]);
        $session = $this->event($owner, $session, ['action' => 'servings', 'servings' => '7.25']);

        $this->actingAs($owner)->getJson('/el/cooking-sessions/'.$session->id)->assertOk()
            ->assertJsonPath('data.servings', '7.25')->assertJsonPath('data.state.checked_ingredients.i0', true);
        $session = $this->event($owner, $session, ['action' => 'ingredient', 'ingredient' => 'i0', 'checked' => false]);
        $this->assertSame([], $session->state['checked_ingredients']);
    }

    public function test_stale_devices_receive_conflict_without_overwriting_progress(): void
    {
        $owner = User::factory()->create();
        $session = $this->start($owner, $this->recipe($owner));
        $oldVersion = $session->version;
        $session = $this->event($owner, $session, ['action' => 'visit', 'step' => 's1']);
        $state = $session->state;

        $this->actingAs($owner)->patchJson('/en/cooking-sessions/'.$session->id, [
            'version' => $oldVersion, 'event' => ['action' => 'step_status', 'step' => 's0', 'status' => 'completed'],
        ])->assertConflict();

        $this->assertSame($state, $session->fresh()->state);
        $this->assertSame($oldVersion + 1, $session->fresh()->version);
    }

    public function test_users_cannot_read_or_update_another_users_session_even_with_moderation_permissions(): void
    {
        $owner = User::factory()->create();
        $session = $this->start($owner, $this->recipe($owner));
        $other = User::factory()->admin()->create();

        $this->actingAs($other)->getJson('/en/cooking-sessions/'.$session->id)->assertNotFound();
        $this->patchJson('/en/cooking-sessions/'.$session->id, ['version' => 1, 'event' => ['action' => 'visit', 'step' => 's1']])->assertNotFound();
        $this->assertSame(1, $session->fresh()->version);
    }

    public function test_session_endpoints_require_authentication_and_verification(): void
    {
        $this->getJson('/en/cooking-sessions/'.Str::uuid())->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())->getJson('/en/cooking-sessions/'.Str::uuid())->assertForbidden();
    }

    public function test_feature_flag_keeps_unmigrated_application_endpoints_inactive(): void
    {
        config(['cooking.sessions_enabled' => false]);
        $this->actingAs(User::factory()->create())->getJson('/en/cooking-sessions/'.Str::uuid())->assertNotFound();
    }

    public function test_sessions_cannot_expose_an_inaccessible_private_recipe(): void
    {
        $recipe = $this->recipe(User::factory()->create());
        $this->actingAs(User::factory()->create())->postJson('/en/recipes/'.$recipe->id.'/cooking-sessions', ['request_key' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_shared_recipe_revocation_blocks_saved_sessions(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->recipe($owner);
        $recipe->update(['visibility' => 'shared']);
        $viewer = User::factory()->create();
        $share = RecipeShare::create(['recipe_id' => $recipe->id, 'user_id' => $viewer->id, 'shared_by' => $owner->id]);
        $session = $this->start($viewer, $recipe);
        $share->update(['revoked_at' => now()]);

        $this->actingAs($viewer)->getJson('/en/cooking-sessions/'.$session->id)->assertNotFound();
        $this->patchJson('/en/cooking-sessions/'.$session->id, ['version' => 1, 'event' => ['action' => 'visit', 'step' => 's1']])->assertNotFound();
        $this->assertSame(1, $session->fresh()->version);
    }

    public function test_public_recipe_withdrawal_blocks_even_the_session_owner(): void
    {
        $owner = User::factory()->contributor()->create();
        $recipe = $this->recipe($owner);
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($recipe);
        $session = $this->start($owner, $recipe->fresh());
        app(RecipePublishingService::class)->withdraw($owner, $recipe->fresh());

        $this->actingAs($owner)->getJson('/en/cooking-sessions/'.$session->id)->assertNotFound();
    }

    public function test_deleted_recipes_are_unavailable_to_cooking_sessions(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->recipe($owner);
        $session = $this->start($owner, $recipe);
        $recipe->delete();

        $this->actingAs($owner)->getJson('/en/cooking-sessions/'.$session->id)->assertNotFound();
    }

    #[TestWith([['action' => 'visit', 'step' => 's999']])]
    #[TestWith([['action' => 'ingredient', 'ingredient' => 'i999', 'checked' => true]])]
    #[TestWith([['action' => 'step_status', 'step' => 's0', 'status' => 'visited']])]
    #[TestWith([['action' => 'timer_start', 'step' => 's0', 'deadline_ms' => 123]])]
    #[TestWith([['action' => 'servings', 'servings' => -1]])]
    public function test_invalid_or_forged_events_do_not_change_session_state(array $event): void
    {
        $owner = User::factory()->create();
        $session = $this->start($owner, $this->recipe($owner));
        $state = $session->state;

        $this->actingAs($owner)->patchJson('/en/cooking-sessions/'.$session->id, ['version' => 1, 'event' => $event])->assertUnprocessable();

        $this->assertSame($state, $session->fresh()->state);
        $this->assertSame(1, $session->fresh()->version);
    }

    #[TestWith(['user_id'])]
    #[TestWith(['recipe_id'])]
    #[TestWith(['recipe_revision_id'])]
    #[TestWith(['content_hash'])]
    public function test_database_rejects_changes_to_ownership_or_content_identity(string $field): void
    {
        $session = CookingSession::factory()->create();
        $value = $field === 'content_hash' ? str_repeat('b', 64) : 999999;

        $this->expectException(QueryException::class);
        DB::table('cooking_sessions')->where('id', $session->id)->update([$field => $value]);
    }

    public function test_database_requires_version_advancement_for_progress_changes(): void
    {
        $session = CookingSession::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('cooking_sessions')->where('id', $session->id)->update(['servings' => '9']);
    }

    public function test_new_publications_do_not_rebind_active_sessions_and_new_null_ids_get_stable_keys(): void
    {
        $author = User::factory()->contributor()->create();
        $recipe = $this->recipe($author);
        $reviewer = User::factory()->admin()->create();
        $this->actingAs($reviewer);
        app(RecipePublishingService::class)->publish($recipe);
        $viewer = User::factory()->create();
        $session = $this->start($viewer, $recipe->fresh());
        $oldPayload = $session->revision->payload;
        $revisions = app(RecipeRevisionService::class);
        $this->actingAs($author);
        $draft = $revisions->createDraft($author, $recipe->fresh());
        $payload = $draft->payload;
        $payload['steps'][0]['translations']['en']['instruction'] = 'Revised dough instructions.';
        $payload['steps'][] = ['id' => null, 'step_group_index' => 0, 'duration_minutes' => 3,
            'translations' => ['en' => ['instruction' => 'Bake.'], 'el' => ['instruction' => 'Ψήστε.']], 'media' => []];
        $draft = $revisions->saveDraft($author, $draft, $payload);
        $draft = $revisions->submit($author, $draft);
        $this->actingAs($reviewer);
        $published = $revisions->approve($reviewer, $draft);

        $resumed = app(CookingSessionService::class)->read($viewer, $session->id);
        $new = $this->start($viewer, $recipe->fresh());

        $this->assertSame($oldPayload, $resumed->revision->payload);
        $this->assertNotSame($session->recipe_revision_id, $new->recipe_revision_id);
        $this->assertSame($published->id, $new->recipe_revision_id);
        $this->assertNull($new->revision->payload['steps'][2]['id']);
        $this->assertArrayHasKey('s2', $new->state['steps']);
        $this->actingAs($viewer)->getJson('/en/cooking-sessions/'.$session->id)->assertOk()
            ->assertJsonPath('data.content.steps.0.translations.en.instruction', 'Cook it.');
    }

    public function test_an_archived_nested_source_blocks_historical_cooking_content(): void
    {
        $owner = User::factory()->create();
        $child = $this->recipe($owner);
        $retained = app(RecipeRevisionService::class)->captureComponentSnapshot($owner, $child);
        $parent = $this->recipe($owner);
        RecipeIngredient::factory()->for($parent)->component($retained)->create();
        $session = $this->start($owner, $parent);
        $child->update(['status' => 'archived']);

        $this->actingAs($owner)->getJson('/en/cooking-sessions/'.$session->id)->assertNotFound();
    }

    public function test_historical_private_media_requires_an_owned_accessible_session_and_uses_pinned_metadata(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $recipe = $this->recipe($owner);
        $recipe->update(['visibility' => 'shared']);
        $viewer = User::factory()->create();
        $other = User::factory()->create();
        $share = RecipeShare::create(['recipe_id' => $recipe->id, 'user_id' => $viewer->id, 'shared_by' => $owner->id]);
        RecipeShare::create(['recipe_id' => $recipe->id, 'user_id' => $other->id, 'shared_by' => $owner->id]);
        $image = Media::factory()->create(['disk' => 'local', 'file_path' => 'session/photo.webp',
            'en' => ['alt_text' => 'Pinned dough'], 'el' => ['alt_text' => 'Ζύμη']]);
        Storage::disk('local')->put($image->file_path, 'image bytes');
        $step = $recipe->steps()->first();
        $step->media()->attach($image, ['kind' => 'image', 'sort_order' => 0]);
        $session = $this->start($viewer, $recipe);
        $step->media()->detach($image);
        $image->fill(['en' => ['alt_text' => 'Later metadata']])->save();

        $response = $this->actingAs($viewer)->getJson('/en/cooking-sessions/'.$session->id)->assertOk();
        $asset = collect($response->json('data.media'))->firstWhere('id', $image->id);
        $this->assertSame('Pinned dough', $asset['translations']['en']['alt_text']);
        $this->assertArrayNotHasKey('file_path', $asset);
        $this->get('/media/'.$image->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($other)->get('/media/'.$image->id)->assertNotFound();
        $share->update(['revoked_at' => now()]);
        $this->actingAs($viewer)->get('/media/'.$image->id)->assertNotFound();
    }

    public function test_archived_private_root_is_unavailable_even_to_its_owner(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->recipe($owner);
        $session = $this->start($owner, $recipe);
        $recipe->update(['status' => 'archived']);

        $this->actingAs($owner)->getJson('/en/cooking-sessions/'.$session->id)->assertNotFound();
    }

    public function test_ungrouped_legacy_steps_keep_their_order_and_mixed_steps_start_with_ungrouped(): void
    {
        $owner = User::factory()->create();
        $legacy = Recipe::factory()->complete()->create(['author_id' => $owner->id]);
        $legacySession = $this->start($owner, $legacy);
        $this->assertSame('s0', $legacySession->state['active_step']);
        $this->assertSame(['ungrouped' => 's0'], $legacySession->state['last_steps']);
        $recipe = $this->recipe($owner);
        $recipe->steps()->create(['step_number' => 3, 'en' => ['instruction' => 'Start here.'], 'el' => ['instruction' => 'Ξεκινήστε εδώ.']]);

        $mixed = $this->start($owner, $recipe);

        $this->assertSame('s2', $mixed->state['active_step']);
        $this->assertSame(['ungrouped' => 's2'], $mixed->state['last_steps']);
    }

    public function test_a_nonce_cannot_be_reused_to_start_a_different_recipe(): void
    {
        $owner = User::factory()->create();
        $session = $this->start($owner, $this->recipe($owner));
        $other = $this->recipe($owner);

        $this->actingAs($owner)->postJson('/en/recipes/'.$other->id.'/cooking-sessions', ['request_key' => $session->request_key])->assertConflict();
    }

    public function test_a_stale_page_content_hash_cannot_silently_start_changed_content(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->recipe($owner);
        $before = RecipeRevision::where('recipe_id', $recipe->id)->count();

        $this->actingAs($owner)->postJson('/en/recipes/'.$recipe->id.'/cooking-sessions', [
            'request_key' => (string) Str::uuid(), 'content_hash' => str_repeat('0', 64),
        ])->assertConflict();

        $this->assertSame($before, RecipeRevision::where('recipe_id', $recipe->id)->count());
        $this->assertSame(0, CookingSession::where('recipe_id', $recipe->id)->count());
    }

    public function test_noop_updates_keep_the_version_and_do_not_reset_timers(): void
    {
        $owner = User::factory()->create();
        $session = $this->start($owner, $this->recipe($owner));
        $session = $this->event($owner, $session, ['action' => 'step_status', 'step' => 's0', 'status' => 'completed']);
        $version = $session->version;
        $session = $this->event($owner, $session, ['action' => 'step_status', 'step' => 's0', 'status' => 'completed']);
        $session = $this->event($owner, $session, ['action' => 'servings', 'servings' => 2]);

        $this->assertSame($version, $session->version);
    }

    public function test_database_rejects_a_revision_from_another_recipe(): void
    {
        $source = CookingSession::factory()->create();
        $other = Recipe::factory()->complete()->create();

        $this->expectException(QueryException::class);
        CookingSession::factory()->create(['recipe_id' => $other->id, 'recipe_revision_id' => $source->recipe_revision_id]);
    }

    public function test_database_rejects_a_mutable_draft_as_session_content(): void
    {
        $source = CookingSession::factory()->create();
        $revision = $source->revision;
        $draft = RecipeRevision::create([
            'recipe_id' => $source->recipe_id, 'revision_number' => 99, 'status' => 'draft',
            'payload' => $revision->payload, 'content_hash' => $revision->content_hash,
            'media_snapshot' => $revision->media_snapshot, 'media_snapshot_hash' => $revision->media_snapshot_hash, 'schema_version' => 3,
        ]);

        $this->expectException(QueryException::class);
        CookingSession::factory()->create(['recipe_id' => $source->recipe_id, 'recipe_revision_id' => $draft->id]);
    }

    public function test_starting_without_steps_rolls_back_the_private_snapshot(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->create(['author_id' => $owner->id]);

        $this->actingAs($owner)->postJson('/en/recipes/'.$recipe->id.'/cooking-sessions', ['request_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('recipe');

        $this->assertSame(0, RecipeRevision::where('recipe_id', $recipe->id)->count());
        $this->assertSame(0, CookingSession::where('recipe_id', $recipe->id)->count());
    }

    public function test_latest_session_is_empty_for_accessible_recipes_without_progress(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->recipe($owner);

        $this->actingAs($owner)->getJson('/en/recipes/'.$recipe->id.'/cooking-session')->assertOk()->assertJsonPath('data', null);
    }

    public function test_reader_text_size_persists_and_invalid_timer_duration_is_rejected(): void
    {
        $owner = User::factory()->create();
        $session = $this->start($owner, $this->recipe($owner));
        $session = $this->event($owner, $session, ['action' => 'size', 'size' => 4]);

        $this->actingAs($owner)->getJson('/en/cooking-sessions/'.$session->id)->assertOk()->assertJsonPath('data.state.size', 4);
        $this->patchJson('/en/cooking-sessions/'.$session->id, [
            'version' => $session->version, 'event' => ['action' => 'timer_start', 'step' => 's0', 'duration_seconds' => 0],
        ])->assertUnprocessable()->assertJsonValidationErrors('duration_seconds');
        $this->assertSame([], $session->fresh()->state['timers']);
    }

    #[TestWith([1])]
    #[TestWith([2])]
    public function test_legacy_published_snapshots_are_pinned_without_upgrading_immutable_history(int $version): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->complete()->create(['author_id' => $owner->id]);
        $snapshots = app(RecipeRevisionSnapshotter::class);
        $payload = $snapshots->forSchema($snapshots->serialize($recipe->id), $version);
        $media = $snapshots->snapshotMedia($payload);
        $legacy = RecipeRevision::create([
            'recipe_id' => $recipe->id, 'revision_number' => 1, 'status' => 'published', 'published_at' => now(),
            'schema_version' => $version, 'payload' => $payload, 'content_hash' => $snapshots->hash($payload),
            'media_snapshot' => $media, 'media_snapshot_hash' => $snapshots->mediaSnapshotHash($media),
        ]);
        DB::table('recipes')->where('id', $recipe->id)->update([
            'status' => 'published', 'visibility' => 'public', 'published_at' => now(), 'current_revision_id' => $legacy->id,
        ]);
        $before = $legacy->fresh()->getRawOriginal();
        $viewer = User::factory()->create();

        $session = $this->start($viewer, $recipe->fresh());

        $this->assertSame($legacy->id, $session->recipe_revision_id);
        $this->assertSame($before, $legacy->fresh()->getRawOriginal());
        $this->actingAs($viewer)->getJson('/en/cooking-sessions/'.$session->id)->assertOk()
            ->assertJsonPath('data.schema_version', $version)->assertJsonPath('data.state.active_step', 's0');
    }

    public function test_rollback_refuses_to_destroy_cooking_progress(): void
    {
        CookingSession::factory()->create();
        $migration = require database_path('migrations/2026_11_07_210749_create_cooking_sessions_table.php');

        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
