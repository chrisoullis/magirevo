<?php

namespace Database\Factories;

use App\Models\CookingSession;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\User;
use App\Services\CookingSessionProgress;
use App\Services\RecipeRevisionService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CookingSession> */
class CookingSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'recipe_id' => fn (array $attributes): int => Recipe::factory()->complete()->create(['author_id' => $attributes['user_id']])->id,
            'recipe_revision_id' => fn (array $attributes): int => app(RecipeRevisionService::class)->captureComponentSnapshot(User::findOrFail($attributes['user_id']), Recipe::findOrFail($attributes['recipe_id']))->id,
            'request_key' => (string) Str::uuid(),
            'content_hash' => fn (array $attributes): string => RecipeRevision::findOrFail($attributes['recipe_revision_id'])->content_hash,
            'media_snapshot_hash' => fn (array $attributes): string => RecipeRevision::findOrFail($attributes['recipe_revision_id'])->media_snapshot_hash,
            'state' => fn (array $attributes): array => app(CookingSessionProgress::class)->initial(RecipeRevision::findOrFail($attributes['recipe_revision_id'])->payload),
            'servings' => '2.00', 'access_scope' => 'restricted', 'status' => 'active', 'version' => 1,
        ];
    }
}
