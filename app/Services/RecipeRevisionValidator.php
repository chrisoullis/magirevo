<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The publication gate applied to a frozen payload: the same bilingual and completeness rules as
 * {@see RecipePublishingService}, plus checks that everything the payload references still exists.
 */
class RecipeRevisionValidator
{
    /**
     * @param  array<string, mixed>  $payload  A normalised payload.
     *
     * @throws ValidationException
     */
    public function assertSubmittable(array $payload, int $recipeId): void
    {
        $errors = [];
        $locales = (array) config('translatable.locales');

        foreach ($locales as $locale) {
            $translation = $payload['translations'][$locale] ?? [];

            if (blank($translation['title'] ?? null) || blank($translation['slug'] ?? null)) {
                $errors['translations'] = 'English and Greek titles and slugs are required before publishing a recipe.';

                break;
            }

            $taken = DB::table('recipe_translations')->where('locale', $locale)->where('slug', $translation['slug'])->where('recipe_id', '!=', $recipeId)->exists();

            if ($taken) {
                $errors['slug'] = 'That slug is already used by another recipe.';
            }
        }

        if (collect($locales)->contains(fn (string $locale): bool => blank($payload['translations'][$locale]['summary'] ?? null))) {
            $errors['summary'] = 'English and Greek summaries are required before publishing.';
        }

        $servings = $payload['recipe']['base_servings'] ?? null;

        if (! is_numeric($servings) || (float) $servings <= 0) {
            $errors['base_servings'] = 'Base servings must be greater than zero.';
        }

        $difficulty = $payload['recipe']['difficulty'] ?? null;

        if ($difficulty !== null && ! DB::table('difficulties')->where('code', $difficulty)->exists()) {
            $errors['difficulty'] = 'Choose a valid difficulty.';
        }

        $errors += $this->ingredientErrors($payload['ingredients'] ?? [], $locales);
        $errors += $this->stepErrors($payload['steps'] ?? [], $locales);
        $errors += $this->referenceErrors($payload);

        if (collect($payload['media'] ?? [])->where('role', 'cover')->count() !== 1) {
            $errors['media'] = 'Choose exactly one cover image before publishing.';
        }

        if (($payload['category_ids'] ?? []) === []) {
            $errors['categories'] = 'Choose at least one category before publishing.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<string>  $locales
     * @return array<string, string>
     */
    private function ingredientErrors(array $lines, array $locales): array
    {
        if ($lines === []) {
            return ['ingredients' => 'Add at least one ingredient before publishing.'];
        }

        $ingredientIds = collect($lines)->pluck('ingredient_id')->unique()->all();
        $known = DB::table('ingredients')->whereIn('id', $ingredientIds)->pluck('id')->all();

        if (count($known) !== count($ingredientIds)) {
            return ['ingredients' => 'An ingredient in this revision no longer exists.'];
        }

        $named = DB::table('ingredient_translations')->whereIn('ingredient_id', $ingredientIds)->whereIn('locale', $locales)->whereNotNull('name')->where('name', '!=', '')
            ->get(['ingredient_id', 'locale'])->groupBy('ingredient_id');

        foreach ($ingredientIds as $ingredientId) {
            if (($named[$ingredientId] ?? collect())->pluck('locale')->unique()->count() < count($locales)) {
                return ['ingredients' => 'Every ingredient needs an English and Greek name before publishing.'];
            }
        }

        foreach ($lines as $line) {
            foreach (['quantity', 'quantity_max'] as $field) {
                if ($line[$field] !== null && (! is_numeric($line[$field]) || (float) $line[$field] < 0)) {
                    return ['ingredients' => 'Ingredient quantities must be positive numbers.'];
                }
            }
        }

        $units = collect($lines)->pluck('unit_id')->filter()->unique()->all();

        if ($units !== [] && DB::table('units')->whereIn('id', $units)->count() !== count($units)) {
            return ['ingredients' => 'A unit in this revision no longer exists.'];
        }

        $modes = collect($lines)->pluck('scaling_mode')->unique()->all();

        if (DB::table('scaling_modes')->whereIn('code', $modes)->count() !== count($modes)) {
            return ['ingredients' => 'An ingredient uses an unknown scaling mode.'];
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  list<string>  $locales
     * @return array<string, string>
     */
    private function stepErrors(array $steps, array $locales): array
    {
        $complete = $steps !== [] && collect($steps)->every(fn (array $step): bool => collect($locales)->every(fn (string $locale): bool => filled($step['translations'][$locale]['instruction'] ?? null)));

        return $complete ? [] : ['steps' => 'Add at least one cooking step with English and Greek instructions before publishing.'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function referenceErrors(array $payload): array
    {
        $errors = [];

        $mediaIds = collect($payload['media'] ?? [])->pluck('media_id')
            ->merge(collect($payload['steps'] ?? [])->flatMap(fn (array $step) => collect($step['media'])->pluck('media_id')))->unique()->all();

        if ($mediaIds !== [] && DB::table('media')->whereIn('id', $mediaIds)->count() !== count($mediaIds)) {
            $errors['media'] = 'A media item in this revision no longer exists.';
        }

        foreach (['category_ids' => 'categories', 'tag_ids' => 'tags'] as $key => $table) {
            $ids = $payload[$key] ?? [];

            if ($ids !== [] && DB::table($table)->whereIn('id', $ids)->count() !== count($ids)) {
                $errors[$table] = "A {$table} entry in this revision no longer exists.";
            }
        }

        return $errors;
    }
}
