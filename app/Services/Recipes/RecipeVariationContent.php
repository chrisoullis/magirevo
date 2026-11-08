<?php

namespace App\Services\Recipes;

use App\Actions\DeleteMediaIfUnused;
use App\Enums\TagClassification;
use App\Models\Category;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeRevisionAudit;
use App\Models\RecipeStep;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DietaryClaimService;
use App\Services\RecipeComponentResolver;
use App\Services\RecipeRevisionSnapshotter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecipeVariationContent
{
    public function save(User $actor, Recipe $recipe, array $payload, array $summaries, string $expectedUpdatedAt): Recipe
    {
        return DB::transaction(function () use ($actor, $recipe, $payload, $summaries, $expectedUpdatedAt): Recipe {
            $recipe = Recipe::query()->whereKey($recipe->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('editVariation', $recipe);
            // Include content identity: timestamps alone have only second precision in this schema.
            if (! hash_equals($this->version($recipe), $expectedUpdatedAt)) {
                throw ValidationException::withMessages(['draft' => __('variations.stale')]);
            }
            $summaries = Validator::make(['summaries' => $summaries], [
                'summaries' => ['array:en,el'], 'summaries.*' => ['nullable', 'string', 'max:5000'],
            ])->validate()['summaries'];
            // Conversion definitions are metadata, not editable form fields.
            $payload['units'] = app(RecipeRevisionSnapshotter::class)->serialize($recipe->id)['units'] ?? [];
            $this->write($recipe, $payload, $actor);
            foreach ($summaries as $locale => $summary) {
                $recipe->derivation->translateOrNew($locale)->change_summary = trim((string) $summary);
            }
            $recipe->derivation->save();
            app(DietaryClaimService::class)->revalidate($recipe, 'Private variation content changed.');
            RecipeRevisionAudit::query()->create(['recipe_id' => $recipe->id, 'actor_id' => $actor->id, 'action' => 'variation_edited']);
            app(AuditLogger::class)->record('recipe_variation_edited', ['recipe_id' => $recipe->id, 'editor_id' => $actor->id]);

            return $recipe->refresh();
        });
    }

    public function version(Recipe $recipe): string
    {
        return hash('sha256', json_encode([
            app(RecipeRevisionSnapshotter::class)->serialize($recipe->id),
            $recipe->derivation?->translations()->orderBy('locale')->get(['locale', 'change_summary'])->toArray(),
        ], JSON_THROW_ON_ERROR));
    }

    /** Internal writer: caller owns transaction, authorization and locks. Never accepts media or ownership fields. */
    public function write(Recipe $recipe, array $payload, ?User $actor = null): void
    {
        $normalize = function (array $values) use (&$normalize): array {
            foreach ($values as $key => &$value) {
                if (is_array($value)) {
                    $value = $normalize($value);
                } elseif ($value === '' && in_array($key, ['id', 'quantity', 'quantity_max', 'unit_id', 'group_index', 'step_group_index', 'duration_minutes', 'difficulty', 'component_recipe_id', 'component_recipe_revision_id', 'quantity_basis', 'shopping_mode', 'prepared_ingredient_id', 'prepared_quantity_per_batch', 'prepared_unit_id'], true)) {
                    $value = null;
                }
            }

            return $values;
        };
        $payload = $normalize($payload);
        $rules = [
            'recipe' => ['required', 'array:prep_minutes,cook_minutes,rest_minutes,base_servings,difficulty'],
            'recipe.prep_minutes' => ['required', 'integer', 'between:0,10000'],
            'recipe.cook_minutes' => ['required', 'integer', 'between:0,10000'],
            'recipe.rest_minutes' => ['required', 'integer', 'between:0,10000'],
            'recipe.base_servings' => ['required', 'numeric', 'decimal:0,2', 'between:0.01,999999.99'],
            'recipe.difficulty' => ['nullable', 'string', Rule::exists('difficulties', 'code')],
            'translations' => ['required', 'array:en,el'],
            'category_ids' => ['present', 'array', 'max:20'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('is_active', true)],
            'tag_ids' => ['present', 'array', 'max:30'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('tags', 'id')->where('is_active', true)->whereIn('classification', TagClassification::freelyPublicValues())],
            'ingredient_groups' => ['present', 'array', 'max:30'],
            'ingredient_groups.*' => ['array:id,sort_order,names'],
            'ingredient_groups.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('ingredient_groups', 'id')->where('recipe_id', $recipe->id)],
            'ingredient_groups.*.names' => ['present', 'array:en,el'],
            'ingredient_groups.*.names.*' => ['nullable', 'string', 'max:150'],
            'ingredients' => ['present', 'array', 'max:150'],
            'ingredients.*' => ['array:id,ingredient_id,group_index,quantity,quantity_max,unit_id,scaling_mode,is_optional,sort_order,translations,line_uuid,line_type,component_recipe_id,component_recipe_revision_id,quantity_basis,shopping_mode,prepared_ingredient_id,prepared_quantity_per_batch,prepared_unit_id'],
            'ingredients.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('recipe_ingredients', 'id')->where('recipe_id', $recipe->id)],
            'ingredients.*.ingredient_id' => ['nullable', 'integer', Rule::exists('ingredients', 'id')->where('is_active', true)],
            'ingredients.*.line_uuid' => ['sometimes', 'uuid', 'distinct'],
            'ingredients.*.line_type' => ['sometimes', Rule::in(['ingredient', 'recipe'])],
            'ingredients.*.component_recipe_id' => ['nullable', 'integer', Rule::exists('recipes', 'id')],
            'ingredients.*.component_recipe_revision_id' => ['nullable', 'integer', Rule::exists('recipe_revisions', 'id')],
            'ingredients.*.quantity_basis' => ['nullable', Rule::in(['whole_batch', 'servings', 'weight', 'volume'])],
            'ingredients.*.shopping_mode' => ['nullable', Rule::in(['scratch', 'prepared'])],
            'ingredients.*.prepared_ingredient_id' => ['nullable', 'integer', Rule::exists('ingredients', 'id')->where('is_active', true)],
            'ingredients.*.prepared_quantity_per_batch' => ['nullable', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999.9999'],
            'ingredients.*.prepared_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'yields' => ['sometimes', 'array', 'max:2'],
            'yields.*' => ['array:basis,quantity,unit_id,evidence,verified_by,verified_at'],
            'yields.*.basis' => ['required', Rule::in(['weight', 'volume']), 'distinct'],
            'yields.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999.9999'],
            'yields.*.unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'yields.*.evidence' => ['required', 'array', 'min:1'],
            'yields.*.verified_by' => ['required', 'integer', Rule::exists('users', 'id')],
            'yields.*.verified_at' => ['required', 'date'],
            'units' => ['sometimes', 'array'],
            'units.*' => ['array:code,unit_type,base_conversion,allow_fraction,is_scalable'],
            'units.*.code' => ['required', 'string'],
            'units.*.unit_type' => ['required', 'string'],
            'units.*.base_conversion' => ['nullable', 'numeric'],
            'units.*.allow_fraction' => ['required', 'boolean'],
            'units.*.is_scalable' => ['required', 'boolean'],
            'ingredients.*.group_index' => ['nullable', 'integer', 'min:0'],
            'ingredients.*.quantity' => ['nullable', 'numeric', 'decimal:0,4', 'between:0,99999999.9999'],
            'ingredients.*.quantity_max' => ['nullable', 'numeric', 'decimal:0,4', 'between:0,99999999.9999'],
            'ingredients.*.unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'ingredients.*.scaling_mode' => ['required', 'string', Rule::exists('scaling_modes', 'code')],
            'ingredients.*.is_optional' => ['required', 'boolean'],
            'ingredients.*.translations' => ['present', 'array:en,el'],
            'step_groups' => ['sometimes', 'array', 'max:30'],
            'step_groups.*' => ['array:id,position,translations'],
            'step_groups.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('recipe_step_groups', 'id')->where('recipe_id', $recipe->id)],
            'step_groups.*.translations' => ['present', 'array:en,el'],
            'step_groups.*.translations.*' => ['array:title,description'],
            'step_groups.*.translations.*.title' => ['nullable', 'string', 'max:150'],
            'step_groups.*.translations.*.description' => ['nullable', 'string', 'max:5000'],
            'steps.*.step_group_index' => ['nullable', 'integer', 'min:0'],
            'steps' => ['present', 'array', 'max:100'],
            'steps.*' => ['array:id,step_number,step_group_index,duration_minutes,translations'],
            'steps.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('recipe_steps', 'id')->where('recipe_id', $recipe->id)],
            'steps.*.duration_minutes' => ['nullable', 'integer', 'between:1,32000'],
            'steps.*.translations' => ['present', 'array:en,el'],
        ];
        foreach (['en', 'el'] as $locale) {
            $rules["translations.$locale"] = ['sometimes', 'array:title,summary,introduction,seo_title,seo_description'];
            foreach (['title' => 255, 'summary' => 1000, 'introduction' => 10000, 'seo_title' => 255, 'seo_description' => 320] as $field => $limit) {
                $rules["translations.$locale.$field"] = ['nullable', 'string', "max:$limit"];
            }
            $rules["steps.*.translations.$locale"] = ['sometimes', 'array:instruction'];
            $rules["steps.*.translations.$locale.instruction"] = ['nullable', 'string', 'max:10000'];
            $rules["ingredients.*.translations.$locale"] = ['sometimes', 'array:preparation_note,quantity_text'];
            $rules["ingredients.*.translations.$locale.preparation_note"] = ['nullable', 'string', 'max:255'];
            $rules["ingredients.*.translations.$locale.quantity_text"] = ['nullable', 'string', 'max:100'];
        }
        foreach ($payload['ingredients'] ?? [] as $index => $line) {
            $component = ($line['line_type'] ?? 'ingredient') === 'recipe';
            $rules["ingredients.$index.ingredient_id"] = [...$rules['ingredients.*.ingredient_id'], $component ? 'prohibited' : 'required'];
            foreach (['component_recipe_id', 'component_recipe_revision_id', 'quantity_basis', 'shopping_mode'] as $field) {
                $rules["ingredients.$index.$field"] = [...$rules["ingredients.*.$field"], $component ? 'required' : 'prohibited'];
            }
            if ($component && in_array($line['quantity_basis'] ?? null, ['whole_batch', 'servings'], true)) {
                $rules["ingredients.$index.unit_id"] = ['nullable', 'prohibited'];
            }
            foreach (['prepared_ingredient_id', 'prepared_quantity_per_batch', 'prepared_unit_id'] as $field) {
                $rules["ingredients.$index.$field"] = [...$rules["ingredients.*.$field"], ! $component ? 'prohibited' : (($line['shopping_mode'] ?? null) === 'prepared' && $field !== 'prepared_unit_id' ? 'required' : 'nullable')];
            }
        }
        $data = Validator::make($payload, $rules)->validate();
        if (! collect($data['translations'])->contains(fn ($row) => filled($row['title'] ?? null))) {
            throw ValidationException::withMessages(['translations' => __('variations.title_required')]);
        }
        foreach ($data['ingredients'] as $index => $line) {
            if ((filled($line['group_index'] ?? null) && ! isset($data['ingredient_groups'][$line['group_index']])) ||
                (filled($line['quantity_max'] ?? null) && (! filled($line['quantity'] ?? null) || (float) $line['quantity_max'] < (float) $line['quantity']))) {
                throw ValidationException::withMessages(["ingredients.$index.quantity" => __('variations.invalid_ingredient')]);
            }
        }
        foreach ($data['ingredients'] as $index => $line) {
            if (($line['line_type'] ?? 'ingredient') === 'recipe'
                && ((isset($line['quantity']) && (float) $line['quantity'] <= 0)
                    || (! isset($line['quantity']) && $line['scaling_mode'] !== 'manual'))) {
                throw ValidationException::withMessages(["ingredients.$index.quantity" => __('variations.invalid_ingredient')]);
            }
            if (isset($line['prepared_unit_id'])) {
                $preparedUnit = Unit::find($line['prepared_unit_id']);
                if (in_array($preparedUnit->unit_type, ['mass', 'volume'], true) && (float) $preparedUnit->base_conversion <= 0) {
                    throw ValidationException::withMessages(["ingredients.$index.prepared_unit_id" => __('variations.invalid_ingredient')]);
                }
            }
        }
        if (Category::query()->publiclyVisible()->whereIn('id', $data['category_ids'])->count() !== count($data['category_ids'])) {
            throw ValidationException::withMessages(['category_ids' => __('variations.invalid_category')]);
        }
        foreach ($data['yields'] ?? [] as $index => $yield) {
            $unit = Unit::find($yield['unit_id']);
            if ($unit->unit_type !== ($yield['basis'] === 'weight' ? 'mass' : 'volume') || (float) $unit->base_conversion <= 0 || array_is_list($yield['evidence'])) {
                throw ValidationException::withMessages(["yields.$index" => 'A yield requires evidence and a compatible measurement unit.']);
            }
        }
        foreach ($data['steps'] as $index => $step) {
            if (isset($step['step_group_index']) && ! isset($data['step_groups'][$step['step_group_index']])) {
                throw ValidationException::withMessages(["steps.$index.step_group_index" => __('cooking.group_recipe_mismatch')]);
            }
        }
        $data['units'] = app(RecipeRevisionSnapshotter::class)->normalize($data)['units'];
        app(RecipeComponentResolver::class)->assertValidPayload($data, $recipe->id, $actor, false);
        $recipe->fill($data['recipe'])->save();
        foreach (['en', 'el'] as $locale) {
            $fields = $data['translations'][$locale] ?? [];
            if (blank($fields['title'] ?? null)) {
                $recipe->translations()->where('locale', $locale)->delete();

                continue;
            }
            $translation = $recipe->translateOrNew($locale);
            $translation->fill($fields);
            // Introduction is authored as plain text; escape it before the rich-text renderer sees it.
            $translation->introduction = nl2br(e((string) ($fields['introduction'] ?? '')));
            $translation->save();
        }
        $recipe->unsetRelation('translations');
        $recipe->categories()->sync($data['category_ids']);
        $recipe->tags()->sync($data['tag_ids']);
        $groupIds = [];
        foreach (array_values($data['ingredient_groups']) as $index => $row) {
            $group = filled($row['id'] ?? null) ? $recipe->ingredientGroups()->findOrFail($row['id']) : $recipe->ingredientGroups()->make();
            $group->sort_order = $index;
            foreach (['en', 'el'] as $locale) {
                if (filled($row['names'][$locale] ?? null)) {
                    $group->translateOrNew($locale)->name = $row['names'][$locale];
                } else {
                    $group->translations()->where('locale', $locale)->delete();
                }
            }
            $group->save();
            $groupIds[$index] = $group->id;
        }
        $kept = [];
        foreach (array_values($data['ingredients']) as $index => $row) {
            $line = filled($row['id'] ?? null) ? $recipe->recipeIngredients()->findOrFail($row['id']) : new RecipeIngredient(['recipe_id' => $recipe->id]);
            // A replacement gets a new line identity: personal swaps must not silently change meaning.
            if ($line->exists && ($line->line_type !== ($row['line_type'] ?? 'ingredient') || (int) $line->ingredient_id !== (int) ($row['ingredient_id'] ?? 0) || (int) $line->component_recipe_id !== (int) ($row['component_recipe_id'] ?? 0))) {
                $line->delete();
                $line = new RecipeIngredient(['recipe_id' => $recipe->id]);
            }
            if ($line->exists && isset($row['line_uuid']) && $line->line_uuid !== $row['line_uuid']) {
                throw ValidationException::withMessages(["ingredients.$index.line_uuid" => 'Line identity cannot change.']);
            }
            if (! $line->exists) {
                $line->line_uuid = (string) Str::uuid();
            }
            $line->fill(collect($row)->only(['ingredient_id', 'quantity', 'quantity_max', 'unit_id', 'scaling_mode', 'is_optional', 'line_type', 'component_recipe_id', 'component_recipe_revision_id', 'quantity_basis', 'shopping_mode', 'prepared_ingredient_id', 'prepared_quantity_per_batch', 'prepared_unit_id'])->all());
            $line->ingredient_group_id = isset($row['group_index']) ? $groupIds[$row['group_index']] : null;
            $line->sort_order = $index;
            foreach ($row['translations'] as $locale => $fields) {
                $line->translateOrNew($locale)->fill($fields);
            }
            $line->save();
            $kept[] = $line->id;
        }
        $recipe->recipeIngredients()->whereNotIn('id', $kept)->get()->each->delete();
        $recipe->ingredientGroups()->whereNotIn('id', $groupIds)->delete();
        if (array_key_exists('yields', $data)) {
            DB::table('recipe_yields')->where('recipe_id', $recipe->id)->delete();
            foreach ($data['yields'] as $yield) {
                $yield['evidence'] = json_encode($yield['evidence'], JSON_THROW_ON_ERROR);
                DB::table('recipe_yields')->insert(['recipe_id' => $recipe->id, ...$yield, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $stepGroups = app(RecipeStepGroups::class);
        $stepGroupIds = array_key_exists('step_groups', $data) ? $stepGroups->sync($recipe, $data['step_groups']) : null;
        $recipe->steps()->increment('step_number', 10000);
        $kept = [];
        foreach (array_values($data['steps']) as $index => $row) {
            $step = filled($row['id'] ?? null) ? $recipe->steps()->findOrFail($row['id']) : new RecipeStep(['recipe_id' => $recipe->id]);
            $step->fill(['step_number' => $index + 1, 'duration_minutes' => $row['duration_minutes'] ?? null,
                ...($stepGroupIds !== null ? ['recipe_step_group_id' => isset($row['step_group_index']) ? $stepGroupIds[$row['step_group_index']] : null] : []),
            ]);
            foreach (['en', 'el'] as $locale) {
                if (filled($row['translations'][$locale]['instruction'] ?? null)) {
                    $step->translateOrNew($locale)->instruction = $row['translations'][$locale]['instruction'];
                } else {
                    $step->translations()->where('locale', $locale)->delete();
                }
            }
            $step->save();
            $kept[] = $step->id;
        }
        foreach ($recipe->steps()->whereNotIn('id', $kept)->with('media')->get() as $removed) {
            $media = $removed->media;
            $removed->delete();
            foreach ($media as $asset) {
                app(DeleteMediaIfUnused::class)->handle($asset);
            }
        }
        if ($stepGroupIds !== null) {
            $stepGroups->removeUnused($recipe, array_values($stepGroupIds));
        }
    }
}
