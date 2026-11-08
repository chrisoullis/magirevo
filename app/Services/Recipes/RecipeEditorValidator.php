<?php

namespace App\Services\Recipes;

use App\Enums\TagClassification;
use App\Models\Category;
use App\Models\Recipe;
use App\Models\Unit;
use App\Models\User;
use App\Services\RecipeComponentResolver;
use App\Services\RecipeRevisionSnapshotter;
use App\Support\AppPermissions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Domain rules apply even when the caller does not render a Filament form. */
class RecipeEditorValidator
{
    public function validate(array $data, Recipe $recipe, ?User $actor = null, ?array $existingPayload = null): array
    {
        if (is_array($data['ingredients_data'] ?? null)) {
            foreach ($data['ingredients_data'] as &$line) {
                if (is_array($line)) {
                    $line['line_type'] ??= 'ingredient';
                }
            }
            unset($line);
        }

        $rules = [
            'difficulty' => ['nullable', 'string', Rule::exists('difficulties', 'code')],
            'prep_minutes' => ['required', 'integer', 'between:0,10000'],
            'cook_minutes' => ['required', 'integer', 'between:0,10000'],
            'rest_minutes' => ['required', 'integer', 'between:0,10000'],
            'base_servings' => ['required', 'numeric', 'decimal:0,2', 'between:0.01,1000'],
            'categories' => ['sometimes', 'array', 'max:20'],
            'categories.*' => ['integer', 'distinct', Rule::exists('categories', 'id')],
            'tags' => ['sometimes', 'array', 'max:30'],
            'tags.*' => ['integer', 'distinct', Rule::exists('tags', 'id')->where('is_active', true)->whereIn('classification', TagClassification::freelyPublicValues())->where(fn ($query) => $query->whereNull('group_code')->orWhere('group_code', '!=', 'dietary_claim'))],
            'ingredient_groups_data' => ['sometimes', 'array', 'max:30'],
            'ingredient_groups_data.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('ingredient_groups', 'id')->where('recipe_id', $recipe->id ?? 0)],
            'ingredient_groups_data.*.names' => ['required', 'array:en,el'],
            'ingredient_groups_data.*.names.*' => ['nullable', 'string', 'max:150'],
            'ingredients_data' => ['present', 'array', 'max:100'],
            'ingredients_data.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('recipe_ingredients', 'id')->where('recipe_id', $recipe->id ?? 0)],
            'ingredients_data.*.ingredient_id' => ['nullable', 'required_unless:ingredients_data.*.line_type,recipe', 'integer', Rule::exists('ingredients', 'id')->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $recipe->exists ? $recipe->recipeIngredients()->pluck('ingredient_id')->all() : []))],
            'ingredients_data.*.group_index' => ['nullable', 'integer', 'min:0'],
            'ingredients_data.*.quantity' => ['nullable', 'numeric', 'decimal:0,4', 'between:0,99999999'],
            'ingredients_data.*.quantity_max' => ['nullable', 'numeric', 'decimal:0,4', 'between:0,99999999'],
            'ingredients_data.*.unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'ingredients_data.*.scaling_mode' => ['required', 'string', Rule::exists('scaling_modes', 'code')],
            'ingredients_data.*.is_optional' => ['sometimes', 'boolean'],
            'media_data' => ['sometimes', 'array', 'max:100'],
            'media_data.*.media_id' => ['required', 'integer', 'distinct'],
            'media_data.*.role' => ['required', 'in:cover,gallery'],
            'media_data.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'steps_data.*.revision_media' => ['sometimes', 'array', 'max:4'],
            'steps_data.*.revision_media.*.media_id' => ['required', 'integer'],
            'steps_data.*.revision_media.*.kind' => ['required', 'in:image,video'],
            'steps_data.*.revision_media.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'step_groups_data' => ['sometimes', 'array', 'max:30'],
            'step_groups_data.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('recipe_step_groups', 'id')->where('recipe_id', $recipe->id ?? 0)],
            'step_groups_data.*.key' => ['required', 'string', 'max:80', 'distinct'],
            'step_groups_data.*.translations' => ['present', 'array:en,el'],
            'step_groups_data.*.translations.*' => ['array:title,description'],
            'step_groups_data.*.translations.*.title' => ['nullable', 'string', 'max:150'],
            'step_groups_data.*.translations.*.description' => ['nullable', 'string', 'max:5000'],
            'step_group_removals' => ['sometimes', 'array', 'max:30'],
            'step_group_removals.*' => ['integer', 'distinct'],
            'steps_data.*.step_group_key' => ['nullable', 'string', 'max:80'],
            'steps_data' => ['present', 'array', 'max:100'],
            'steps_data.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('recipe_steps', 'id')->where('recipe_id', $recipe->id ?? 0)],
            'steps_data.*.duration_minutes' => ['nullable', 'integer', 'between:1,32000'],
            'steps_data.*.media_items' => ['sometimes', 'array', 'max:3'],
            'steps_data.*.media_items.*' => ['array:media_id,upload,alt_text_en,caption_en,alt_text_el,caption_el'],
            'steps_data.*.video_url' => ['nullable', 'string', 'max:2048'],
        ];
        $rules += [
            'ingredients_data.*.line_uuid' => ['nullable', 'string', 'max:64'],
            'ingredients_data.*.line_type' => ['sometimes', 'in:ingredient,recipe'],
            'ingredients_data.*.component_recipe_id' => ['nullable', 'integer'],
            'ingredients_data.*.component_recipe_revision_id' => ['nullable', 'integer'],
            'ingredients_data.*.quantity_basis' => ['nullable', 'in:whole_batch,servings,weight,volume'],
            'ingredients_data.*.shopping_mode' => ['nullable', 'in:scratch,prepared'],
            'ingredients_data.*.prepared_ingredient_id' => ['nullable', 'integer', Rule::exists('ingredients', 'id')->where('is_active', true)],
            'ingredients_data.*.prepared_quantity_per_batch' => ['nullable', 'numeric', 'gt:0', 'max:99999999', 'decimal:0,4'],
            'ingredients_data.*.prepared_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
        ];
        $rules += [
            'yields_data' => ['sometimes', 'array', 'max:2'],
            'yields_data.*.basis' => ['required', 'in:weight,volume', 'distinct'],
            'yields_data.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999'],
            'yields_data.*.unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'yields_data.*.evidence' => ['required', 'array'],
        ];
        foreach (config('translatable.locales') as $locale) {
            $rules[$locale] = ['sometimes', 'array:title,slug,summary,introduction,seo_title,seo_description'];
            foreach (['title' => 255, 'slug' => 255, 'summary' => 1000, 'introduction' => 10000, 'seo_title' => 255, 'seo_description' => 320] as $field => $limit) {
                $rules["$locale.$field"] = ['nullable', 'string', "max:$limit"];
            }
            $rules["$locale.slug"] = ['nullable', 'string', 'max:255', 'regex:/^[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*$/u', Rule::unique('recipe_translations', 'slug')->where('locale', $locale)->ignore($recipe->id ?? 0, 'recipe_id')];
            foreach (['quantity_text' => 255, 'preparation_note' => 255] as $field => $limit) {
                $rules["ingredients_data.*.{$field}_$locale"] = ['nullable', 'string', "max:$limit"];
            }
            $rules["steps_data.*.instruction_$locale"] = ['nullable', 'string', 'max:5000'];
            $rules["steps_data.*.video_alt_text_$locale"] = ['nullable', 'string', 'max:255'];
            $rules["steps_data.*.video_caption_$locale"] = ['nullable', 'string', 'max:1000'];
        }
        $data = Validator::make($data, $rules)->validate();
        if (array_key_exists('step_groups_data', $data)) {
            $keys = array_column($data['step_groups_data'], 'key');
            foreach ($data['steps_data'] as $index => $step) {
                if (filled($step['step_group_key'] ?? null) && ! in_array($step['step_group_key'], $keys, true)) {
                    throw ValidationException::withMessages(["steps_data.$index.step_group_key" => __('cooking.group_recipe_mismatch')]);
                }
            }
            $existingGroups = $existingPayload['step_groups'] ?? ($recipe->exists && Schema::hasTable('recipe_step_groups') ? $recipe->stepGroups()->get()->toArray() : []);
            $removed = array_diff(array_column($existingGroups, 'id'), array_column($data['step_groups_data'], 'id'));
            if (array_diff($removed, $data['step_group_removals'] ?? []) !== []) {
                throw ValidationException::withMessages(['step_groups_data' => __('cooking.confirm_group_removal')]);
            }
        } elseif (collect($data['steps_data'])->contains(fn (array $step): bool => filled($step['step_group_key'] ?? null))) {
            throw ValidationException::withMessages(['step_groups_data' => __('cooking.group_recipe_mismatch')]);
        }
        if (! collect(config('translatable.locales'))->contains(fn (string $locale): bool => filled($data[$locale]['title'] ?? null))) {
            throw ValidationException::withMessages(['translations' => __('variations.title_required')]);
        }
        foreach (config('translatable.locales') as $locale) {
            if (! isset($data[$locale])) {
                continue;
            }
            $data[$locale]['title'] = (string) ($data[$locale]['title'] ?? '');
            if (blank($data[$locale]['title']) && (! $recipe->exists || $recipe->getTranslation($locale, false) === null)) {
                $hasContent = collect($data[$locale])->except('title')->contains(fn ($value): bool => filled(trim(strip_tags((string) $value))));
                if ($hasContent) {
                    throw ValidationException::withMessages(["$locale.title" => __('studio.translation_title_required')]);
                }
                unset($data[$locale]);
            }
        }
        $categoryIds = $data['categories'] ?? [];
        $allowedCategories = Category::query()->whereIn('id', $categoryIds)
            ->where(fn ($query) => $query->where(fn ($visible) => $visible->publiclyVisible())
                ->when($recipe->exists, fn ($query) => $query->orWhereHas('recipes', fn ($recipes) => $recipes->whereKey($recipe->id))))->count();
        if ($allowedCategories !== count($categoryIds)) {
            throw ValidationException::withMessages(['categories' => __('variations.invalid_category')]);
        }
        foreach ($data['ingredients_data'] as $index => $line) {
            if (filled($line['group_index'] ?? null) && ! isset($data['ingredient_groups_data'][$line['group_index']])) {
                throw ValidationException::withMessages(["ingredients_data.$index.group_index" => __('variations.invalid_ingredient')]);
            }
            if (filled($line['quantity_max'] ?? null) && (blank($line['quantity'] ?? null) || (float) $line['quantity_max'] < (float) $line['quantity'])) {
                throw ValidationException::withMessages(["ingredients_data.$index.quantity_max" => __('variations.invalid_ingredient')]);
            }
        }

        if (array_key_exists('yields_data', $data)) {
            $oldYields = $existingPayload['yields'] ?? ($recipe->exists ? $recipe->yields()->get()->toArray() : []);
            $canonical = fn (array $rows): array => collect($rows)->map(fn (array $row): array => Arr::only($row, ['basis', 'quantity', 'unit_id', 'evidence']))->sortBy('basis')->values()->all();
            if ($canonical($data['yields_data']) != $canonical($oldYields)) {
                if (! $actor?->can(AppPermissions::RECIPES_REVIEW)) {
                    throw ValidationException::withMessages(['yields_data' => __('nested.yield_permission')]);
                }
                foreach ($data['yields_data'] as $index => &$yield) {
                    if (Unit::find($yield['unit_id'])?->unit_type !== ($yield['basis'] === 'weight' ? 'mass' : 'volume') || (float) Unit::find($yield['unit_id'])?->base_conversion <= 0 || array_is_list($yield['evidence']) || ! collect($yield['evidence'])->contains(fn ($value): bool => filled($value))) {
                        throw ValidationException::withMessages(["yields_data.$index" => __('nested.invalid_component')]);
                    }
                    $yield['verified_by'] = $actor->id;
                    $yield['verified_at'] = now()->toISOString();
                }
                unset($yield);
            } else {
                $data['yields_data'] = $oldYields;
            }
        }
        $storedLines = collect($existingPayload['ingredients'] ?? ($recipe->exists ? $recipe->recipeIngredients()->get()->toArray() : []));
        $existing = $storedLines->filter(fn (array $row): bool => filled($row['id'] ?? null))->keyBy('id');
        $existingByUuid = $storedLines->filter(fn (array $row): bool => blank($row['id'] ?? null) && filled($row['line_uuid'] ?? null))->keyBy('line_uuid');
        $usedUuids = [];
        foreach ($data['ingredients_data'] as $index => &$line) {
            $stored = filled($line['id'] ?? null) ? $existing->get($line['id']) : $existingByUuid->get($line['line_uuid'] ?? '');
            $line['line_uuid'] = $stored['line_uuid'] ?? (string) Str::uuid();
            if (isset($usedUuids[$line['line_uuid']])) {
                throw ValidationException::withMessages(["ingredients_data.$index" => __('nested.invalid_component')]);
            }
            $usedUuids[$line['line_uuid']] = true;
            $line['line_type'] ??= 'ingredient';
            if ($line['line_type'] === 'recipe') {
                $manualWithoutQuantity = blank($line['quantity'] ?? null) && ($line['scaling_mode'] ?? null) === 'manual' && in_array($line['quantity_basis'] ?? null, ['whole_batch', 'servings'], true);
                if (! $actor || (! $manualWithoutQuantity && (blank($line['quantity'] ?? null) || (float) $line['quantity'] <= 0)) || blank($line['quantity_basis'] ?? null) || blank($line['shopping_mode'] ?? null)) {
                    throw ValidationException::withMessages(["ingredients_data.$index" => __('nested.invalid_component')]);
                }
                if ((($line['shopping_mode'] ?? null) === 'prepared' || filled($line['prepared_ingredient_id'] ?? null) || filled($line['prepared_quantity_per_batch'] ?? null) || filled($line['prepared_unit_id'] ?? null)) && (blank($line['prepared_ingredient_id'] ?? null) || blank($line['prepared_quantity_per_batch'] ?? null) || blank($line['prepared_unit_id'] ?? null))) {
                    throw ValidationException::withMessages(["ingredients_data.$index" => __('nested.mapping_required')]);
                }
                if (in_array($line['quantity_basis'], ['whole_batch', 'servings'], true)) {
                    $line['unit_id'] = null;
                } elseif (Unit::find($line['unit_id'] ?? null)?->unit_type !== ($line['quantity_basis'] === 'weight' ? 'mass' : 'volume') || (float) Unit::find($line['unit_id'] ?? null)?->base_conversion <= 0) {
                    throw ValidationException::withMessages(["ingredients_data.$index.unit_id" => __('nested.invalid_component')]);
                }
                $trustedPin = $stored ?? $storedLines->first(fn (array $candidate): bool => (int) ($candidate['component_recipe_id'] ?? 0) === (int) ($line['component_recipe_id'] ?? 0) && (int) ($candidate['component_recipe_revision_id'] ?? 0) === (int) ($line['component_recipe_revision_id'] ?? 0));
                $line['component_recipe_revision_id'] = app(RecipeComponentEligibility::class)->pin($actor, $line, $trustedPin, $recipe->id);
                $line['ingredient_id'] = null;
            } else {
                foreach (['component_recipe_id', 'component_recipe_revision_id', 'quantity_basis', 'shopping_mode', 'prepared_ingredient_id', 'prepared_quantity_per_batch', 'prepared_unit_id'] as $field) {
                    $line[$field] = null;
                }
            }
        }
        unset($line);
        try {
            app(RecipeComponentResolver::class)->assertValidPayload(app(RecipeRevisionSnapshotter::class)->normalize(RecipeEditorData::toPayload($existingPayload ?? [], $data)), $recipe->id ?? 0, $actor);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => [preg_replace('/^ingredients\./', 'ingredients_data.', $key) => $messages])->all());
        }

        return $data;
    }
}
