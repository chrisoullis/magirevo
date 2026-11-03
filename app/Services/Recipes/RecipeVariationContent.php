<?php

namespace App\Services\Recipes;

use App\Actions\DeleteMediaIfUnused;
use App\Enums\TagClassification;
use App\Models\Category;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeRevisionAudit;
use App\Models\RecipeStep;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DietaryClaimService;
use App\Services\RecipeRevisionSnapshotter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
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
            $this->write($recipe, $payload);
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
    public function write(Recipe $recipe, array $payload): void
    {
        $normalize = function (array $values) use (&$normalize): array {
            foreach ($values as $key => &$value) {
                if (is_array($value)) {
                    $value = $normalize($value);
                } elseif ($value === '' && in_array($key, ['id', 'quantity', 'quantity_max', 'unit_id', 'group_index', 'duration_minutes', 'difficulty'], true)) {
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
            'ingredients.*' => ['array:id,ingredient_id,group_index,quantity,quantity_max,unit_id,scaling_mode,is_optional,sort_order,translations'],
            'ingredients.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('recipe_ingredients', 'id')->where('recipe_id', $recipe->id)],
            'ingredients.*.ingredient_id' => ['required', 'integer', Rule::exists('ingredients', 'id')->where('is_active', true)],
            'ingredients.*.group_index' => ['nullable', 'integer', 'min:0'],
            'ingredients.*.quantity' => ['nullable', 'numeric', 'decimal:0,4', 'between:0,99999999.9999'],
            'ingredients.*.quantity_max' => ['nullable', 'numeric', 'decimal:0,4', 'between:0,99999999.9999'],
            'ingredients.*.unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'ingredients.*.scaling_mode' => ['required', 'string', Rule::exists('scaling_modes', 'code')],
            'ingredients.*.is_optional' => ['required', 'boolean'],
            'ingredients.*.translations' => ['present', 'array:en,el'],
            'steps' => ['present', 'array', 'max:100'],
            'steps.*' => ['array:id,step_number,duration_minutes,translations'],
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
        if (Category::query()->publiclyVisible()->whereIn('id', $data['category_ids'])->count() !== count($data['category_ids'])) {
            throw ValidationException::withMessages(['category_ids' => __('variations.invalid_category')]);
        }
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
            if ($line->exists && (int) $line->ingredient_id !== (int) $row['ingredient_id']) {
                $line->delete();
                $line = new RecipeIngredient(['recipe_id' => $recipe->id]);
            }
            $line->fill(collect($row)->only(['ingredient_id', 'quantity', 'quantity_max', 'unit_id', 'scaling_mode', 'is_optional'])->all());
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
        $recipe->steps()->increment('step_number', 10000);
        $kept = [];
        foreach (array_values($data['steps']) as $index => $row) {
            $step = filled($row['id'] ?? null) ? $recipe->steps()->findOrFail($row['id']) : new RecipeStep(['recipe_id' => $recipe->id]);
            $step->fill(['step_number' => $index + 1, 'duration_minutes' => $row['duration_minutes'] ?? null]);
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
    }
}
