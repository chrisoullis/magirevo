<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use App\Services\RecipeQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesVariationSource;
use Tests\TestCase;

class RecipeForkConcurrencyTest extends TestCase
{
    use CreatesVariationSource;
    use RefreshDatabase;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        if (str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
        parent::tearDown();
    }

    private function race(User $actor, Recipe $source, array $keys): array
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $startAt = microtime(true) + 1.5;
        $processes = [];
        foreach ($keys as $key) {
            $process = proc_open([PHP_BINARY, base_path('tests/Support/RecipeForkWorker.php'), (string) $actor->id, (string) $source->id, $key, (string) $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), [...getenv(), 'XDEBUG_MODE' => 'off']);
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $this->assertJson(trim($output), $error);
            $results[] = json_decode(trim($output), true);
        }

        return $results;
    }

    public function test_same_key_in_independent_processes_creates_one_variation(): void
    {
        $source = $this->source();
        $actor = User::factory()->create();
        $key = (string) Str::uuid();
        $results = $this->race($actor, $source, [$key, $key]);
        $this->assertTrue($results[0]['ok']);
        $this->assertTrue($results[1]['ok']);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, Recipe::query()->where('author_id', $actor->id)->count());
    }

    public function test_distinct_requests_cannot_race_past_personal_quota(): void
    {
        $source = $this->source();
        $actor = User::factory()->create();
        app(RecipeQuotaService::class)->entitlement($actor)->update(['personal_recipe_limit_override' => 1]);
        $results = $this->race($actor, $source, [(string) Str::uuid(), (string) Str::uuid()]);
        $this->assertSame(1, count(array_filter($results, fn ($row) => $row['ok'])));
        $this->assertSame(1, Recipe::query()->where('author_id', $actor->id)->count());
    }
}
