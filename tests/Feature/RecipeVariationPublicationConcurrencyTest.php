<?php

namespace Tests\Feature;

use App\Actions\StoreRecipeMedia;
use App\Enums\RecipeRevisionStatus;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\User;
use App\Services\RecipeContentGuard;
use App\Services\RecipeRevisionSnapshotter;
use App\Services\Recipes\RecipeForkService;
use App\Services\Recipes\RecipeVariationContent;
use App\Services\Recipes\RecipeVariationPublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CreatesVariationSource;
use Tests\TestCase;

class RecipeVariationPublicationConcurrencyTest extends TestCase
{
    use CreatesVariationSource;
    use RefreshDatabase;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        config(['recipe-variations.enabled' => true, 'recipe-variations.public_submissions_enabled' => true]);
    }

    protected function tearDown(): void
    {
        if (str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
        parent::tearDown();
    }

    private function ready(): array
    {
        Storage::fake('local');
        Storage::fake('public');
        $source = $this->source();
        $permission = $source->reusePermissions()->firstOrFail();
        $grant = $permission->replicate();
        $grant->forceFill(['supersedes_id' => $permission->id, 'mode' => 'personal_and_public_submission', 'allows_public_adaptation' => true])->save();
        $owner = User::factory()->contributor()->create();
        $recipe = app(RecipeForkService::class)->fork($owner, $source, ['idempotency_key' => (string) Str::uuid()]);
        $recipe->steps()->firstOrFail()->translate('en')->update(['instruction' => 'Roast the mushrooms before simmering the soup.']);
        $recipe->steps()->firstOrFail()->translate('el')->update(['instruction' => 'Ψήστε τα μανιτάρια πριν σιγοβράσετε τη σούπα.']);
        app(StoreRecipeMedia::class)->handle($recipe, UploadedFile::fake()->image('race-cover.png', 600, 400), ['en' => ['alt_text' => 'My soup'], 'el' => ['alt_text' => 'Η σούπα μου']], 'cover');
        app(RecipeContentGuard::class)->setMode('enforce');

        return [$owner, $recipe->fresh(), $source];
    }

    private function inReview(): array
    {
        [$owner, $recipe, $source] = $this->ready();
        $publishing = app(RecipeVariationPublishingService::class);
        $revision = $publishing->submit($owner, $recipe, app(RecipeVariationContent::class)->version($recipe), [
            'rights_confirmed' => true, 'meaningful_changes_confirmed' => true,
            'summaries' => ['en' => 'Roasted the mushrooms first and developed a different cooking method.', 'el' => 'Έψησα πρώτα τα μανιτάρια και ανέπτυξα διαφορετική μέθοδο μαγειρέματος.'],
        ]);
        $reviewer = User::factory()->admin()->create();
        $publishing->reviewRights($reviewer, $revision, true, 'Inspected the retained evidence and the applicable license conditions.', true);

        return [$owner, $recipe->fresh(), $source, $revision, $reviewer];
    }

    /** @param list<array{string, int}> $jobs */
    private function startWorkers(Recipe $recipe, ?RecipeRevision $revision, array $jobs): array
    {
        $startAt = microtime(true) + 1.5;
        $processes = [];
        foreach ($jobs as [$action, $actorId]) {
            $process = proc_open([PHP_BINARY, base_path('tests/Support/RecipeVariationPublicationWorker.php'), $action, (string) $actorId, (string) $recipe->id, (string) ($revision?->id ?? 0), (string) $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), [...getenv(), 'XDEBUG_MODE' => 'off', 'APP_ENV' => 'testing']);
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        return $processes;
    }

    private function results(array $processes): array
    {
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $this->assertSame(0, $exit, $error);
            $this->assertJson(trim($output), $error);
            $results[] = json_decode(trim($output), true);
        }

        return $results;
    }

    private function assertPublished(Recipe $recipe, RecipeRevision $revision): void
    {
        $recipe = $recipe->fresh();
        $this->assertSame('public', $recipe->visibility->value);
        $this->assertSame('published', $recipe->status);
        $this->assertSame($revision->id, $recipe->current_revision_id);
        $this->assertSame(RecipeRevisionStatus::Published, $revision->fresh()->status);
        $this->assertSame(1, $recipe->revisions()->where('status', 'published')->count());
        $snapshotter = app(RecipeRevisionSnapshotter::class);
        $this->assertSame($revision->content_hash, $snapshotter->hash($snapshotter->serialize($recipe->id)));
        $this->assertSame(1, $revision->audits()->where('action', 'approved')->count());
    }

    public function test_two_submissions_create_one_frozen_private_revision(): void
    {
        [$owner, $recipe] = $this->ready();
        $results = $this->results($this->startWorkers($recipe, null, [['submit', $owner->id], ['submit', $owner->id]]));
        $this->assertSame(1, count(array_filter($results, fn (array $result): bool => $result['ok'])), json_encode($results));
        $this->assertSame(1, $recipe->revisions()->count());
        $this->assertSame('pending', $recipe->fresh()->status);
        $this->assertSame('private', $recipe->fresh()->visibility->value);
        $revision = $recipe->revisions()->firstOrFail();
        $this->assertSame(RecipeRevisionStatus::InReview, $revision->status);
        $this->assertSame(1, $revision->audits()->where('action', 'submitted')->count());
        $this->assertSame(app(RecipeVariationPublishingService::class)->contextHash($revision->publication_context), $revision->publication_context_hash);
    }

    public function test_two_approvals_publish_exactly_once(): void
    {
        [, $recipe, , $revision, $reviewer] = $this->inReview();
        $other = User::factory()->admin()->create();
        $results = $this->results($this->startWorkers($recipe, $revision, [['approve', $reviewer->id], ['approve', $other->id]]));
        $this->assertSame(1, count(array_filter($results, fn (array $result): bool => $result['ok'])), json_encode($results));
        $this->assertPublished($recipe, $revision);
    }

    public function test_withdrawal_and_approval_leave_one_consistent_outcome(): void
    {
        [$owner, $recipe, , $revision, $reviewer] = $this->inReview();
        $results = $this->results($this->startWorkers($recipe, $revision, [['withdraw', $owner->id], ['approve', $reviewer->id]]));
        $this->assertSame(1, count(array_filter($results, fn (array $result): bool => $result['ok'])), json_encode($results));
        if ($results[1]['ok']) {
            $this->assertPublished($recipe, $revision);
        } else {
            $this->assertSame(RecipeRevisionStatus::Rejected, $revision->fresh()->status);
            $this->assertSame('private', $recipe->fresh()->visibility->value);
            $this->assertSame('draft', $recipe->fresh()->status);
            $this->assertNull($recipe->fresh()->current_revision_id);
            $this->assertSame(0, $revision->audits()->where('action', 'approved')->count());
        }
    }

    public function test_rights_rejection_and_approval_are_serialized(): void
    {
        [, $recipe, , $revision, $reviewer] = $this->inReview();
        $other = User::factory()->admin()->create();
        $results = $this->results($this->startWorkers($recipe, $revision, [['decline_rights', $reviewer->id], ['approve', $other->id]]));
        $this->assertSame(1, count(array_filter($results, fn (array $result): bool => $result['ok'])), json_encode($results));
        if ($results[1]['ok']) {
            $this->assertPublished($recipe, $revision);
        } else {
            $this->assertSame(RecipeRevisionStatus::InReview, $revision->fresh()->status);
            $this->assertSame('private', $recipe->fresh()->visibility->value);
            $this->assertSame('pending', $recipe->fresh()->status);
            $this->assertNull($recipe->fresh()->current_revision_id);
            $this->assertSame('variation_rights_rejected', $revision->audits()->whereIn('action', ['variation_rights_cleared', 'variation_rights_rejected'])->reorder()->latest('id')->value('action'));
        }
    }

    public function test_approval_waits_for_and_observes_a_committed_permission_suspension(): void
    {
        [, $recipe, , $revision, $reviewer] = $this->inReview();
        $permission = app(RecipeVariationPublishingService::class)->permission($recipe);
        DB::beginTransaction();
        try {
            $permission->forceFill(['suspended_at' => now(), 'suspended_by' => $reviewer->id, 'suspension_reason' => 'Evidence dispute raised before publication.'])->save();
            $processes = $this->startWorkers($recipe, $revision, [['approve', $reviewer->id]]);
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) DB::selectOne("SELECT count(*) AS total FROM pg_stat_activity WHERE application_name = 'variation-publication-test-worker' AND wait_event_type = 'Lock'")->total > 0;
                if (! $waiting) {
                    usleep(20000);
                }
            } while (! $waiting && microtime(true) < $deadline);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $results = $this->results($processes);
        $this->assertTrue($waiting, 'The publisher must wait on the rights version locked by the suspension.');
        $this->assertFalse($results[0]['ok'], json_encode($results));
        $this->assertSame('private', $recipe->fresh()->visibility->value);
        $this->assertNull($recipe->fresh()->current_revision_id);
        $this->assertSame(RecipeRevisionStatus::InReview, $revision->fresh()->status);
        $this->assertSame(0, $revision->audits()->where('action', 'approved')->count());
    }
}
