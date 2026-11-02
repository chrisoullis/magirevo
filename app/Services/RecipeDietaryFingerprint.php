<?php

namespace App\Services;

use App\Enums\TagClassification;
use App\Models\DietaryClaim;
use App\Models\IngredientAllergenAssessment;
use App\Models\IngredientComponent;
use App\Models\IngredientDietaryAssessment;
use App\Models\IngredientDietaryProfile;
use App\Models\IngredientSubstitution;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeRevision;
use App\Models\RecipeStep;
use App\Models\Tag;

/**
 * Content fingerprint of everything that can change a recipe's dietary status: ingredient lines,
 * ingredient metadata relevant to the claim, quantities, preparation notes, instructions and substitutions.
 * Titles, summaries, media, SEO text, categories, tags and ordering are deliberately excluded.
 */
class RecipeDietaryFingerprint
{
    public function for(Recipe $recipe, ?Tag $tag = null): string
    {
        return $this->forId((int) $recipe->getKey(), $tag);
    }

    public function forId(int $recipeId, ?Tag $tag = null): string
    {
        $ingredients = RecipeIngredient::query()
            ->where('recipe_id', $recipeId)
            ->with('translations')
            ->get()
            ->map(fn (RecipeIngredient $line): array => [
                'i' => (int) $line->ingredient_id,
                'q' => $this->number($line->quantity),
                'qm' => $this->number($line->quantity_max),
                'u' => $line->unit_id === null ? null : (int) $line->unit_id,
                'o' => (bool) $line->is_optional,
                'n' => $this->localized($line->translations->pluck('preparation_note', 'locale')->all()),
            ])
            ->sortBy(fn (array $line): string => json_encode($line))
            ->values()
            ->all();

        $steps = RecipeStep::query()
            ->where('recipe_id', $recipeId)
            ->with('translations')
            ->orderBy('step_number')
            ->get()
            ->map(fn (RecipeStep $step): array => $this->localized($step->translations->pluck('instruction', 'locale')->all()))
            ->all();

        return $this->build($recipeId, $ingredients, $steps, $tag);
    }

    /**
     * The fingerprint of the exact content a claim was reviewed against.
     */
    public function forClaim(DietaryClaim $claim): string
    {
        $subject = $claim->contentSubject();

        return $subject instanceof RecipeRevision
            ? $this->forRevision($subject, $claim->tag)
            : $this->for($subject, $claim->tag);
    }

    /**
     * The fingerprint of a frozen revision payload. For identical content it equals the live fingerprint, so a
     * revision that is not (or no longer) the live content can still be verified against what was reviewed.
     */
    public function forRevision(RecipeRevision $revision, ?Tag $tag = null): string
    {
        $payload = (array) $revision->payload;

        $ingredients = collect($payload['ingredients'] ?? [])
            ->map(fn (array $line): array => [
                'i' => (int) $line['ingredient_id'],
                'q' => $this->number($line['quantity'] ?? null),
                'qm' => $this->number($line['quantity_max'] ?? null),
                'u' => ($line['unit_id'] ?? null) === null ? null : (int) $line['unit_id'],
                'o' => (bool) ($line['is_optional'] ?? false),
                'n' => $this->localized(collect($line['translations'] ?? [])->map(fn (array $row) => $row['preparation_note'] ?? null)->all()),
            ])
            ->sortBy(fn (array $line): string => json_encode($line))
            ->values()
            ->all();

        $steps = collect($payload['steps'] ?? [])
            ->sortBy('step_number')
            ->map(fn (array $step): array => $this->localized(collect($step['translations'] ?? [])->map(fn (array $row) => $row['instruction'] ?? null)->all()))
            ->values()
            ->all();

        return $this->build((int) $revision->recipe_id, $ingredients, $steps, $tag);
    }

    /**
     * @param  list<array<string, mixed>>  $ingredients
     * @param  list<array<string, string>>  $steps
     */
    private function build(int $recipeId, array $ingredients, array $steps, ?Tag $tag): string
    {
        $substitutions = IngredientSubstitution::query()
            ->where('recipe_id', $recipeId)
            ->approved()
            ->get(['ingredient_id', 'replacement_ingredient_id'])
            ->map(fn (IngredientSubstitution $substitution): array => [(int) $substitution->ingredient_id, (int) $substitution->replacement_ingredient_id])
            ->sort()
            ->values()
            ->all();

        $ingredientIds = collect($ingredients)->pluck('i')->unique()->values();
        $allIngredientIds = $ingredientIds->all();
        $frontier = $allIngredientIds;

        while ($frontier !== []) {
            $componentIds = IngredientComponent::query()
                ->whereIn('ingredient_id', $frontier)
                ->whereNotNull('component_ingredient_id')
                ->pluck('component_ingredient_id')
                ->map(fn ($id): int => (int) $id)
                ->diff($allIngredientIds)
                ->values()
                ->all();
            $allIngredientIds = array_values(array_unique([...$allIngredientIds, ...$componentIds]));
            $frontier = $componentIds;
        }

        sort($allIngredientIds);
        $ingredientMetadata = [];
        $includeProfilesAndComponents = $tag === null
            || in_array($tag->classification, [TagClassification::DietStyle, TagClassification::IngredientAllergen], true);
        $includeDietaryAssessments = $tag === null || $tag->classification === TagClassification::DietStyle;
        $includeAllergenAssessments = $tag === null || $tag->classification === TagClassification::IngredientAllergen;

        if ($includeProfilesAndComponents) {
            $ingredientMetadata['profiles'] = IngredientDietaryProfile::query()->whereIn('ingredient_id', $allIngredientIds)
                ->orderBy('ingredient_id')
                ->get(['ingredient_id', 'composition_status', 'origin_classification', 'evidence', 'reviewed_by', 'reviewed_at', 'updated_at'])
                ->toArray();
            $ingredientMetadata['components'] = IngredientComponent::query()->whereIn('ingredient_id', $allIngredientIds)
                ->orderBy('ingredient_id')->orderBy('id')
                ->get(['ingredient_id', 'component_ingredient_id', 'unresolved_component', 'evidence', 'updated_at'])
                ->toArray();
        }

        if ($includeDietaryAssessments) {
            $ingredientMetadata['dietary_assessments'] = IngredientDietaryAssessment::query()
                ->whereIn('ingredient_id', $allIngredientIds)
                ->when($tag !== null, fn ($query) => $query->where('tag_id', $tag->getKey()))
                ->orderBy('ingredient_id')->orderBy('tag_id')
                ->get(['ingredient_id', 'tag_id', 'outcome', 'verification_method', 'evidence', 'reviewed_by', 'reviewed_at', 'rule_version', 'review_notes', 'updated_at'])
                ->toArray();
        }

        if ($includeAllergenAssessments) {
            $ingredientMetadata['allergen_assessments'] = IngredientAllergenAssessment::query()
                ->whereIn('ingredient_id', $allIngredientIds)
                ->orderBy('ingredient_id')->orderBy('allergen_code')
                ->get(['ingredient_id', 'allergen_code', 'presence', 'verification_method', 'evidence', 'reviewed_by', 'reviewed_at', 'review_notes', 'updated_at'])
                ->toArray();
        }

        $claimDefinition = $tag === null ? null : [
            'id' => (int) $tag->getKey(),
            'code' => $tag->code,
            'classification' => $tag->classification->value,
            'rule_version' => (int) config("dietary.claims.{$tag->code}.rule_version", config('dietary.default_rule_version', 1)),
        ];

        return hash('sha256', json_encode([
            'v' => (int) config('dietary.fingerprint_version', 1),
            'claim' => $claimDefinition,
            'ingredients' => $ingredients,
            'ingredient_metadata' => $ingredientMetadata,
            'steps' => $steps,
            'substitutions' => $substitutions,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function number(mixed $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 4, '.', '');
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function localized(array $values): array
    {
        $normalized = [];

        foreach ($values as $locale => $text) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');

            if ($text !== '') {
                $normalized[(string) $locale] = $text;
            }
        }

        ksort($normalized);

        return $normalized;
    }
}
