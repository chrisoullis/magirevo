<?php

namespace Tests\Feature;

use App\Models\CookingSession;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStepGroup;
use App\Models\User;
use App\Services\CookingSessionService;
use App\Services\RecipeComponentResolver;
use App\Services\RecipePublishingService;
use Database\Seeders\DifficultySeeder;
use Database\Seeders\ScalingModeSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CookingReleaseEnvironment;
use Tests\TestCase;

class CookingSessionConcurrencyTest extends TestCase
{
    private static ?string $schema = null;

    private array $beforePublic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->beforePublic = CookingReleaseEnvironment::publicFingerprints();
        $new = self::$schema === null;
        self::$schema ??= 'cooking_release_'.bin2hex(random_bytes(8));
        if ($new) {
            DB::statement('CREATE SCHEMA "'.self::$schema.'"');
        }
        CookingReleaseEnvironment::useSchema(self::$schema);
        if ($new) {
            // Shadow the existing migration repository before running additive migrations in our namespace.
            app('migration.repository')->createRepository();
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
            $this->seed([DifficultySeeder::class, ScalingModeSeeder::class]);
        }
        $this->assertSame(self::$schema, DB::selectOne('select current_schema() as schema')->schema);
    }

    protected function tearDown(): void
    {
        if (isset($this->beforePublic)) {
            $this->assertSame($this->beforePublic, CookingReleaseEnvironment::publicFingerprints(), 'Release tests changed existing public-schema data.');
        }
        // Keep the new scratch namespace for inspection; never reset or truncate existing data.
        parent::tearDown();
    }

    private function recipe(User $owner): Recipe
    {
        $recipe = Recipe::factory()->complete()->create(['author_id' => $owner->id]);
        $group = RecipeStepGroup::factory()->for($recipe)->create([
            'en' => ['title' => 'Dough'], 'el' => ['title' => 'Ζύμη'],
        ]);
        $recipe->steps()->first()->update(['recipe_step_group_id' => $group->id, 'duration_minutes' => 1]);
        $filling = RecipeStepGroup::factory()->for($recipe)->create([
            'position' => 1, 'en' => ['title' => 'Filling'], 'el' => ['title' => 'Γέμιση'],
        ]);
        $recipe->steps()->create(['step_number' => 2, 'recipe_step_group_id' => $filling->id, 'duration_minutes' => 2,
            'en' => ['instruction' => 'Prepare filling.'], 'el' => ['instruction' => 'Ετοιμάστε τη γέμιση.']]);
        $recipe->categories()->first()->fill([
            'en' => ['name' => 'Cooking', 'slug' => 'cooking-'.$recipe->id],
            'el' => ['name' => 'Μαγείρεμα', 'slug' => 'cooking-el-'.$recipe->id],
        ])->save();

        return $recipe;
    }

    private function race(array $jobs): array
    {
        $barrier = (string) (microtime(true) + 1.0);
        $processes = [];
        foreach ($jobs as $job) {
            $process = proc_open([PHP_BINARY, base_path('tests/Support/CookingSessionWorker.php'), self::$schema,
                json_encode($job, JSON_THROW_ON_ERROR), $barrier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(),
                [...getenv(), 'DB_DATABASE' => DB::connection()->getDatabaseName(), 'XDEBUG_MODE' => 'off']);
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
            $this->assertJson(trim($output), $errors);
            $results[] = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    public function test_simultaneous_start_retries_create_one_session(): void
    {
        $owner = User::factory()->contributor()->create();
        $recipe = $this->recipe($owner);
        $job = ['action' => 'start', 'user' => $owner->id, 'recipe' => $recipe->id, 'key' => (string) Str::uuid()];

        $results = $this->race([$job, $job]);

        $this->assertSame([true, true], array_column($results, 'ok'), json_encode($results));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, CookingSession::where('user_id', $owner->id)->count());
    }

    public function test_simultaneous_updates_with_one_version_have_one_winner(): void
    {
        $owner = User::factory()->contributor()->create();
        $session = app(CookingSessionService::class)->start($owner, $this->recipe($owner)->id, (string) Str::uuid());
        $base = ['action' => 'update', 'user' => $owner->id, 'session' => $session->id, 'version' => 1];

        $results = $this->race([
            [...$base, 'event' => ['action' => 'timer_start', 'step' => 's0']],
            [...$base, 'event' => ['action' => 'visit', 'step' => 's1']],
        ]);

        $this->assertCount(1, array_filter($results, fn ($result) => $result['ok']), json_encode($results));
        $this->assertSame([409], array_values(array_column(array_filter($results, fn ($result) => ! $result['ok']), 'status')));
        $fresh = $session->fresh();
        $this->assertSame(2, $fresh->version);
        $this->assertSame($results[0]['ok'], isset($fresh->state['timers']['s0']));
        $this->assertSame($results[1]['ok'] ? 's1' : 's0', $fresh->state['active_step']);
    }

    public function test_independent_sessions_accept_simultaneous_updates(): void
    {
        $owner = User::factory()->contributor()->create();
        $recipe = $this->recipe($owner);
        $service = app(CookingSessionService::class);
        $first = $service->start($owner, $recipe->id, (string) Str::uuid());
        $second = $service->start($owner, $recipe->id, (string) Str::uuid());
        $base = ['action' => 'update', 'user' => $owner->id, 'version' => 1];

        $results = $this->race([
            [...$base, 'session' => $first->id, 'event' => ['action' => 'timer_start', 'step' => 's0']],
            [...$base, 'session' => $second->id, 'event' => ['action' => 'timer_start', 'step' => 's1']],
        ]);

        $this->assertSame([true, true], array_column($results, 'ok'), json_encode($results));
        $this->assertSame(['s0'], array_keys($first->fresh()->state['timers']));
        $this->assertSame(['s1'], array_keys($second->fresh()->state['timers']));
    }

    public function test_withdrawal_racing_progress_blocks_every_subsequent_resume(): void
    {
        $owner = User::factory()->contributor()->create();
        $recipe = $this->recipe($owner);
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($recipe);
        $session = app(CookingSessionService::class)->start($owner, $recipe->id, (string) Str::uuid());
        $results = $this->race([
            ['action' => 'withdraw', 'user' => $owner->id, 'recipe' => $recipe->id],
            ['action' => 'update', 'user' => $owner->id, 'session' => $session->id, 'version' => 1,
                'event' => ['action' => 'visit', 'step' => 's1']],
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results));
        $this->assertTrue($results[1]['ok'] || $results[1]['status'] === 404, json_encode($results));
        $this->actingAs($owner)->getJson('/en/cooking-sessions/'.$session->id)->assertNotFound();
        $this->patchJson('/en/cooking-sessions/'.$session->id, ['version' => $session->fresh()->version,
            'event' => ['action' => 'visit', 'step' => 's0']])->assertNotFound();
    }

    public function test_parent_publication_racing_child_withdrawal_has_no_partial_or_unreadable_success_path(): void
    {
        $owner = User::factory()->contributor()->create();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $child = $this->recipe($owner);
        app(RecipePublishingService::class)->publish($child);
        $parent = $this->recipe($owner);
        RecipeIngredient::factory()->for($parent)->component($child->currentRevision)->create();

        $results = $this->race([
            ['action' => 'withdraw', 'user' => $owner->id, 'recipe' => $child->id],
            ['action' => 'publish', 'user' => $admin->id, 'recipe' => $parent->id],
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results));
        $this->assertTrue($results[1]['ok'] || $results[1]['status'] === 422, json_encode($results));
        $parent->refresh();
        if ($results[1]['ok']) {
            $this->assertNotNull($parent->current_revision_id);
            $this->assertSame('published', $parent->status);
        } else {
            $this->assertNull($parent->current_revision_id);
            $this->assertSame('draft', $parent->status);
        }
        $this->assertFalse(app(RecipeComponentResolver::class)->canResolve($parent, $owner));
        $this->get('/en/recipes/'.$parent->translate('en')->slug)->assertNotFound();
    }

    public function test_live_browser_fixture_is_isolated_and_has_bilingual_groups(): void
    {
        $owner = User::factory()->contributor()->create();
        $recipe = $this->recipe($owner);
        $this->actingAs(User::factory()->admin()->create());
        $child = $this->recipe($owner);
        app(RecipePublishingService::class)->publish($child);
        $line = RecipeIngredient::factory()->for($recipe)->component($child->currentRevision)->create()->fresh();
        app(RecipePublishingService::class)->publish($recipe);
        $recipe->refresh();
        $this->assertSame(2, $recipe->stepGroups()->count());
        if ($file = getenv('COOKING_LIVE_MANIFEST')) {
            file_put_contents($file, json_encode(['schema' => self::$schema, 'recipe' => $recipe->id,
                'email' => $owner->email, 'component_path' => $line->line_uuid, 'slugs' => ['en' => $recipe->translate('en')->slug, 'el' => $recipe->translate('el')->slug]], JSON_PRETTY_PRINT));
        }
    }
}
