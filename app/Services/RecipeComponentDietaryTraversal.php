<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\User;

/** Internal evidence traversal: never consumes component claims or shopping modes. */
class RecipeComponentDietaryTraversal
{
    public function inspect(Recipe|RecipeRevision $subject, bool $content = true): array
    {
        $root = $subject instanceof Recipe ? $subject : $subject->recipe;
        $public = $root === null || $root->visibility?->value === 'public';
        $viewer = $public ? null : $root->author;

        $budget = ['components' => 0, 'lines' => 0, 'exceeded' => false];
        $result = $this->walk($subject, $public, $viewer, [], 0, $content, $budget);
        if ($result['ids'] !== []) {
            $existingIds = Ingredient::whereIn('id', $result['ids'])->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if (count($existingIds) !== count($result['ids'])) {
                $result['unresolved'] = true;
                $result['ids'] = $existingIds;
            }
        }

        return $result;
    }

    private function canReadRetained(?User $viewer, Recipe $source): bool
    {
        if ($viewer === null || ! $viewer->hasVerifiedEmail()) {
            return false;
        }
        $access = app(RecipeAccessService::class);

        return $access->owns($viewer, $source) || $access->hasActiveShare($viewer, $source)
            || $access->canManage($viewer, $source, 'View:Recipe');
    }

    private function preparation(array $translations): array
    {
        $notes = [];
        foreach ($translations as $locale => $row) {
            $text = trim(preg_replace('/\\s+/u', ' ', (string) ($row['preparation_note'] ?? '')) ?? '');
            if ($text !== '') {
                $notes[$locale] = $text;
            }
        }
        ksort($notes);

        return $notes;
    }

    private function walk(Recipe|RecipeRevision $subject, bool $public, ?User $viewer, array $seen, int $depth, bool $content, array &$budget): array
    {
        $key = (int) ($subject instanceof RecipeRevision ? $subject->recipe_id : $subject->id);
        if (isset($seen[$key]) || $depth > RecipeComponentResolver::MAX_DEPTH) {
            return ['ids' => [], 'components' => [], 'unresolved' => true];
        }
        $seen[$key] = true;
        $payload = $subject instanceof RecipeRevision ? $subject->payload : null;
        $lines = $payload !== null ? ($payload['ingredients'] ?? []) : $subject->recipeIngredients()->with('translations')->get()->map(function ($line): array {
            $row = $line->getAttributes();
            $row['translations'] = $line->translations->mapWithKeys(fn ($text): array => [$text->locale => ['preparation_note' => $text->preparation_note]])->all();

            return $row;
        })->all();
        $result = ['ids' => [], 'components' => [], 'unresolved' => false];
        foreach ($lines as $line) {
            if (($line['line_type'] ?? 'ingredient') !== 'recipe') {
                if (++$budget['lines'] > RecipeComponentResolver::MAX_EXPANDED_LINES) {
                    $budget['exceeded'] = true;

                    return ['ids' => [], 'components' => [], 'unresolved' => true];
                }
                if (! empty($line['ingredient_id'])) {
                    $result['ids'][] = (int) $line['ingredient_id'];
                } else {
                    $result['unresolved'] = true;
                }

                continue;
            }
            if (++$budget['components'] > RecipeComponentResolver::MAX_COMPONENT_OCCURRENCES) {
                $budget['exceeded'] = true;

                return ['ids' => [], 'components' => [], 'unresolved' => true];
            }
            $source = Recipe::find($line['component_recipe_id'] ?? null);
            $revision = RecipeRevision::where('recipe_id', $line['component_recipe_id'] ?? null)->find($line['component_recipe_revision_id'] ?? null);
            $available = config('nested-recipes.enabled') && $source && $source->status !== 'archived' && $revision
                && hash_equals($revision->content_hash, app(RecipeRevisionSnapshotter::class)->hash($revision->payload))
                && in_array($revision->getRawOriginal('status'), $public ? ['published', 'superseded'] : ['published', 'superseded', 'retained'], true)
                && ($revision->getRawOriginal('status') === 'retained'
                    ? $this->canReadRetained($viewer, $source)
                    : ($revision->published_at !== null && $source->status === 'published'))
                && ($public ? ($source->visibility?->value === 'public' && $source->status === 'published' && $source->published_at !== null && ! $source->published_at->isFuture() && ! $source->trashed()) : app(RecipeAccessService::class)->canViewDirect($viewer, $source));
            $entry = [
                'recipe' => (int) ($line['component_recipe_id'] ?? 0),
                'revision' => (int) ($line['component_recipe_revision_id'] ?? 0),
                'quantity' => ($line['quantity'] ?? null) === null ? null : number_format((float) $line['quantity'], 4, '.', ''),
                'quantity_max' => ($line['quantity_max'] ?? null) === null ? null : number_format((float) $line['quantity_max'], 4, '.', ''),
                'unit' => ($line['unit_id'] ?? null) === null ? null : (int) $line['unit_id'],
                'basis' => $line['quantity_basis'] ?? null,
                'optional' => (bool) ($line['is_optional'] ?? false),
                'preparation' => $this->preparation($line['translations'] ?? []),
                'available' => (bool) $available,
            ];
            if ($available) {
                $child = $this->walk($revision, $public, $viewer, $seen, $depth + 1, $content, $budget);
                if ($budget['exceeded'] || (! $content && $child['unresolved'])) {
                    return ['ids' => [], 'components' => [], 'unresolved' => true];
                }
                $result['ids'] = [...$result['ids'], ...$child['ids']];
                $result['unresolved'] = $result['unresolved'] || $child['unresolved'];
                if ($content) {
                    $entry['fingerprint'] = app(RecipeDietaryFingerprint::class)->forRevisionContent($revision);
                }
                $entry['components'] = $child['components'];
            } else {
                $result['unresolved'] = true;
                if (! $content) {
                    return ['ids' => [], 'components' => [], 'unresolved' => true];
                }
            }
            $result['components'][] = $entry;
        }
        $result['ids'] = array_values(array_unique($result['ids']));
        usort($result['components'], fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));

        return $result;
    }
}
