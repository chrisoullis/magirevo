<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Controls the PostgreSQL write guard on published recipe content. Content of a public recipe changes only inside
 * {@see self::asPublisher()}, which the revision publisher uses; the database refuses every other write once enforced.
 */
class RecipeContentGuard
{
    public const MODES = ['off', 'monitor', 'enforce'];

    public function mode(): string
    {
        return (string) (DB::table('recipe_revision_settings')->where('key', 'content_guard')->value('value') ?? 'monitor');
    }

    public function setMode(string $mode): void
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unknown content guard mode [{$mode}].");
        }

        DB::table('recipe_revision_settings')->updateOrInsert(['key' => 'content_guard'], ['value' => $mode, 'updated_at' => now(), 'created_at' => now()]);
    }

    /**
     * Runs the callback in a transaction where writes to this one recipe's content are allowed.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function asPublisher(int $recipeId, Closure $callback, ?int $revisionId = null, string $retireReason = 'line_removed'): mixed
    {
        return DB::transaction(function () use ($recipeId, $callback, $revisionId, $retireReason): mixed {
            $this->setLocal('app.recipe_publisher', (string) $recipeId);
            $this->setLocal('app.recipe_revision_id', $revisionId !== null ? (string) $revisionId : '');
            $this->setLocal('app.line_retire_reason', $retireReason);

            $result = $callback();

            $this->setLocal('app.recipe_publisher', '');
            $this->setLocal('app.recipe_revision_id', '');
            $this->setLocal('app.line_retire_reason', '');

            return $result;
        });
    }

    /**
     * Names the revision being published, for lines the database archives while the publisher runs.
     */
    public function markRevision(int $revisionId): void
    {
        $this->setLocal('app.recipe_revision_id', (string) $revisionId);
    }

    private function setLocal(string $name, string $value): void
    {
        DB::select('select set_config(?, ?, true)', [$name, $value]);
    }
}
