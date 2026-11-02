<?php

namespace Tests\Feature;

use App\Enums\RecipeRevisionStatus;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\User;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use App\Services\RecipeRevisionSnapshotter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Real concurrency: each contested call runs in a separate PHP process with its own PostgreSQL connection. Data has to
 * be committed for the other processes to see it, so this class does not wrap tests in a transaction and truncates the
 * test database's schema afterwards. It refuses to run against anything but a database whose name ends in "_test".
 */
class RecipeRevisionConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $otherAuthor;

    private Recipe $recipe;

    /**
     * @return list<string>
     */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->author = User::factory()->contributor()->create();
        $this->otherAuthor = User::factory()->contributor()->create();
        $this->recipe = Recipe::factory()->complete()->create(['author_id' => $this->author->id]);
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($this->recipe);
        $this->recipe->refresh();
    }

    protected function tearDown(): void
    {
        // The audit tables are append-only (TRUNCATE is refused too), so the committed test data is wiped by rebuilding
        // the schema of the disposable test database.
        if (str_ends_with((string) DB::connection()->getDatabaseName(), '_test')) {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }

        parent::tearDown();
    }

    /**
     * @param  list<array{string, int, int}>  $jobs  action, user id, subject id
     * @return list<array<string, mixed>>
     */
    private function race(array $jobs): array
    {
        $startAt = microtime(true) + 1.5;
        $processes = [];

        foreach ($jobs as [$action, $userId, $subjectId]) {
            $command = [PHP_BINARY, base_path('tests/Support/ConcurrencyWorker.php'), $action, (string) $userId, (string) $subjectId, (string) $startAt];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), [...getenv(), 'XDEBUG_MODE' => 'off']);
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        $results = [];

        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $lines = array_values(array_filter(explode("\n", (string) $output), fn (string $line): bool => str_starts_with($line, '{')));
            $this->assertNotEmpty($lines, 'Worker produced no result: '.$output);
            $results[] = json_decode(end($lines), true);
        }

        return $results;
    }

    private function succeeded(array $results): int
    {
        return count(array_filter($results, fn (array $result): bool => $result['ok']));
    }

    private function service(): RecipeRevisionService
    {
        return app(RecipeRevisionService::class);
    }

    private function inReview(?User $author = null): RecipeRevision
    {
        $author ??= $this->author;
        $draft = $this->service()->createDraft($author, $this->recipe->fresh());
        $payload = $draft->payload;
        $payload['translations']['en']['title'] = 'Concurrent edit '.$draft->id;

        return $this->service()->submit($author, $this->service()->saveDraft($author, $draft, $payload));
    }

    private function assertRecipeConsistent(): void
    {
        $recipe = $this->recipe->fresh();
        $published = RecipeRevision::where('recipe_id', $recipe->id)->where('status', RecipeRevisionStatus::Published->value)->get();

        $this->assertCount(1, $published, 'Exactly one revision may be published.');
        $this->assertSame($published->first()->id, $recipe->current_revision_id);
        $snapshotter = app(RecipeRevisionSnapshotter::class);
        $this->assertSame($published->first()->content_hash, $snapshotter->hash($snapshotter->serialize($recipe->id)), 'Live content must match the published revision.');
        $this->assertLessThanOrEqual(1, RecipeRevision::where('recipe_id', $recipe->id)->whereIn('status', ['draft', 'in_review'])->count());
    }

    public function test_two_users_cannot_both_open_a_revision(): void
    {
        $results = $this->race([
            ['create_draft', $this->author->id, $this->recipe->id],
            ['create_draft', $this->otherAuthor->id, $this->recipe->id],
        ]);

        $this->assertSame(1, $this->succeeded($results), json_encode($results));
        $this->assertSame(1, RecipeRevision::where('recipe_id', $this->recipe->id)->whereIn('status', ['draft', 'in_review'])->count());
    }

    public function test_concurrent_submissions_of_one_draft_freeze_it_once(): void
    {
        $draft = $this->service()->createDraft($this->author, $this->recipe->fresh());
        $payload = $draft->payload;
        $payload['translations']['en']['title'] = 'Submitted twice';
        $draft = $this->service()->saveDraft($this->author, $draft, $payload);

        $results = $this->race([
            ['submit', $this->author->id, $draft->id],
            ['submit', $this->author->id, $draft->id],
        ]);

        $this->assertSame(1, $this->succeeded($results), json_encode($results));
        $this->assertSame(RecipeRevisionStatus::InReview, $draft->fresh()->status);
        $this->assertSame(1, DB::table('recipe_revision_audits')->where('recipe_revision_id', $draft->id)->where('to_status', 'in_review')->count());
    }

    public function test_a_revision_cannot_be_published_twice(): void
    {
        $revision = $this->inReview();
        $reviewers = [User::factory()->admin()->create(), User::factory()->admin()->create()];

        $results = $this->race([
            ['approve', $reviewers[0]->id, $revision->id],
            ['approve', $reviewers[1]->id, $revision->id],
        ]);

        $this->assertSame(1, $this->succeeded($results), json_encode($results));
        $this->assertSame(1, DB::table('recipe_revision_audits')->where('recipe_revision_id', $revision->id)->where('to_status', 'published')->count());
        $this->assertSame($revision->id, $this->recipe->fresh()->current_revision_id);
        $this->assertRecipeConsistent();
    }

    public function test_simultaneous_approval_and_rollback_leave_one_consistent_published_revision(): void
    {
        $first = $this->recipe->current_revision_id;
        $second = $this->inReview();
        $this->service()->approve(User::factory()->admin()->create(), $second);
        $third = $this->inReview();
        $approver = User::factory()->admin()->create();
        $roller = User::factory()->admin()->create();

        $results = $this->race([
            ['approve', $approver->id, $third->id],
            ['rollback', $roller->id, $first],
        ]);

        $this->assertGreaterThanOrEqual(1, $this->succeeded($results), json_encode($results));
        $this->assertRecipeConsistent();
        $this->assertSame(0, RecipeRevision::where('recipe_id', $this->recipe->id)->where('status', 'published')->where('id', '<>', $this->recipe->fresh()->current_revision_id)->count());
    }

    public function test_a_new_draft_racing_a_publication_is_based_on_a_real_revision(): void
    {
        $revision = $this->inReview();
        $approver = User::factory()->admin()->create();

        $results = $this->race([
            ['approve', $approver->id, $revision->id],
            ['create_draft', $this->otherAuthor->id, $this->recipe->id],
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results));
        $this->assertRecipeConsistent();
        $open = RecipeRevision::where('recipe_id', $this->recipe->id)->whereIn('status', ['draft', 'in_review'])->get();
        $this->assertLessThanOrEqual(1, $open->count());
    }
}
