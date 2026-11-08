<?php

namespace App\Services;

use App\Models\User;
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
    public function assertSubmittable(array $payload, int $recipeId, ?User $actor = null, bool $requirePublic = true): void
    {
        app(RecipeComponentResolver::class)->assertValidPayload($payload, $recipeId, $actor, $requirePublic);

        $errors = [];
        if (count($payload['step_groups'] ?? []) > 30) {
            $errors['step_groups'] = __('cooking.too_many_groups');
        }
        foreach ($payload['step_groups'] ?? [] as $index => $group) {
            foreach (config('translatable.locales') as $locale) {
                if (blank($group['translations'][$locale]['title'] ?? null) || mb_strlen($group['translations'][$locale]['title']) > 150 || mb_strlen($group['translations'][$locale]['description'] ?? '') > 5000) {
                    $errors["step_groups.$index"] = __('cooking.group_titles_required');
                }
            }
        }
        foreach ($payload['steps'] ?? [] as $index => $step) {
            if (isset($step['step_group_index']) && (! is_int($step['step_group_index']) || ! array_key_exists($step['step_group_index'], $payload['step_groups'] ?? []))) {
                $errors["steps.$index.step_group_index"] = __('cooking.group_recipe_mismatch');
            }
        }
        $locales = (array) config('translatable.locales');

        foreach ($locales as $locale) {
            $translation = $payload['translations'][$locale] ?? [];

            if (blank($translation['title'] ?? null) || blank($translation['slug'] ?? null)) {
                $errors['translations'] = __('studio.publication.english_and_greek_titles_and_slugs_are_required_before');

                break;
            }

            $taken = DB::table('recipe_translations')->where('locale', $locale)->where('slug', $translation['slug'])->where('recipe_id', '!=', $recipeId)->exists();

            if ($taken) {
                $errors['slug'] = __('studio.publication.that_slug_is_already_used_by_another_recipe');
            }
        }

        if (collect($locales)->contains(fn (string $locale): bool => blank($payload['translations'][$locale]['summary'] ?? null))) {
            $errors['summary'] = __('studio.publication.english_and_greek_summaries_are_required_before_publishing');
        }

        $servings = $payload['recipe']['base_servings'] ?? null;

        if (! is_numeric($servings) || (float) $servings <= 0) {
            $errors['base_servings'] = __('studio.publication.base_servings_must_be_greater_than_zero');
        }

        $difficulty = $payload['recipe']['difficulty'] ?? null;

        if ($difficulty !== null && ! DB::table('difficulties')->where('code', $difficulty)->exists()) {
            $errors['difficulty'] = __('studio.publication.choose_a_valid_difficulty');
        }

        $errors += $this->ingredientErrors($payload['ingredients'] ?? [], $locales);
        $errors += $this->stepErrors($payload['steps'] ?? [], $locales);
        $errors += $this->referenceErrors($payload);

        if (collect($payload['media'] ?? [])->where('role', 'cover')->count() !== 1) {
            $errors['media'] = __('studio.publication.choose_exactly_one_cover_image_before_publishing');
        }

        if (($payload['category_ids'] ?? []) === []) {
            $errors['categories'] = __('studio.publication.choose_at_least_one_category_before_publishing');
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
            return ['ingredients' => __('studio.publication.add_at_least_one_ingredient_before_publishing')];
        }

        $ingredientIds = collect($lines)->filter(fn (array $line): bool => ($line['line_type'] ?? 'ingredient') === 'ingredient')->pluck('ingredient_id')->unique()->all();
        $known = DB::table('ingredients')->whereIn('id', $ingredientIds)->pluck('id')->all();

        if (count($known) !== count($ingredientIds)) {
            return ['ingredients' => __('studio.publication.an_ingredient_in_this_revision_no_longer_exists')];
        }

        $named = DB::table('ingredient_translations')->whereIn('ingredient_id', $ingredientIds)->whereIn('locale', $locales)->whereNotNull('name')->where('name', '!=', '')
            ->get(['ingredient_id', 'locale'])->groupBy('ingredient_id');

        foreach ($ingredientIds as $ingredientId) {
            if (($named[$ingredientId] ?? collect())->pluck('locale')->unique()->count() < count($locales)) {
                return ['ingredients' => __('studio.publication.every_ingredient_needs_an_english_and_greek_name_before')];
            }
        }

        foreach ($lines as $line) {
            foreach (['quantity', 'quantity_max'] as $field) {
                if ($line[$field] !== null && (! is_numeric($line[$field]) || (float) $line[$field] < 0)) {
                    return ['ingredients' => __('studio.publication.ingredient_quantities_must_be_positive_numbers')];
                }
            }
        }

        $units = collect($lines)->pluck('unit_id')->filter()->unique()->all();

        if ($units !== [] && DB::table('units')->whereIn('id', $units)->count() !== count($units)) {
            return ['ingredients' => __('studio.publication.a_unit_in_this_revision_no_longer_exists')];
        }

        $modes = collect($lines)->pluck('scaling_mode')->unique()->all();

        if (DB::table('scaling_modes')->whereIn('code', $modes)->count() !== count($modes)) {
            return ['ingredients' => __('studio.publication.an_ingredient_uses_an_unknown_scaling_mode')];
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

        return $complete ? [] : ['steps' => __('studio.publication.add_at_least_one_cooking_step_with_english_and')];
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
            $errors['media'] = __('studio.publication.a_media_item_in_this_revision_no_longer_exists');
        }

        foreach (['category_ids' => 'categories', 'tag_ids' => 'tags'] as $key => $table) {
            $ids = $payload[$key] ?? [];

            if ($ids !== [] && DB::table($table)->whereIn('id', $ids)->count() !== count($ids)) {
                $errors[$table] = __('studio.missing_reference', ['type' => $table]);
            }
        }

        return $errors;
    }
}
