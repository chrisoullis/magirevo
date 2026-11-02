<?php

namespace App\Services;

use App\Enums\DietaryCompatibility;
use App\Enums\IngredientCompositionStatus;
use App\Enums\IngredientOriginClassification;
use App\Enums\TagClassification;
use App\Models\IngredientComponent;
use App\Models\IngredientDietaryAssessment;
use App\Models\IngredientDietaryProfile;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\Tag;
use App\Models\User;
use App\Support\AppPermissions;
use Illuminate\Support\Collection;

class IngredientDietaryResolver
{
    /** @var array<int, User|null> */
    private array $reviewers = [];

    /**
     * Resolve only reviewed Vegetarian and Vegan evidence. Other claim types require a separately
     * implemented evidence model and remain unknown here.
     */
    public function resolve(Recipe|RecipeRevision $recipe, Tag $tag): DietaryCompatibility
    {
        if (! in_array($tag->code, ['vegetarian', 'vegan'], true)) {
            return DietaryCompatibility::Unknown;
        }

        $outcomes = $this->ingredientIds($recipe)
            ->map(fn (int $ingredientId): DietaryCompatibility => $this->resolveIngredient($ingredientId, $tag, []));

        if ($outcomes->contains(DietaryCompatibility::Incompatible)) {
            return DietaryCompatibility::Incompatible;
        }

        return $outcomes->isNotEmpty()
            && $outcomes->every(fn (DietaryCompatibility $outcome): bool => $outcome === DietaryCompatibility::Compatible)
                ? DietaryCompatibility::Compatible
                : DietaryCompatibility::Unknown;
    }

    /**
     * @return list<string>
     */
    public function warnings(Recipe|RecipeRevision $recipe, Tag $tag): array
    {
        if (! in_array($tag->code, ['vegetarian', 'vegan'], true)) {
            return ['No dietary resolver is available for this claim type.'];
        }

        $warnings = [];

        foreach ($this->ingredientIds($recipe) as $ingredientId) {
            $this->collectWarnings((int) $ingredientId, $tag, [], $warnings);
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @return list<string>
     */
    public function evidence(Recipe|RecipeRevision $recipe, Tag $tag): array
    {
        $evidence = [];

        foreach ($this->ingredientIds($recipe) as $ingredientId) {
            $this->collectEvidence((int) $ingredientId, $tag, [], $evidence);
        }

        return array_values(array_unique($evidence));
    }

    /**
     * @param  array<int, true>  $seen
     */
    /**
     * @return Collection<int, int>
     */
    private function ingredientIds(Recipe|RecipeRevision $subject): Collection
    {
        $ids = $subject instanceof RecipeRevision
            ? collect($subject->payload['ingredients'] ?? [])->pluck('ingredient_id')
            : $subject->recipeIngredients()->pluck('ingredient_id');

        return $ids->map(fn ($id): int => (int) $id)->unique()->values();
    }

    private function resolveIngredient(int $ingredientId, Tag $tag, array $seen): DietaryCompatibility
    {
        if (isset($seen[$ingredientId])) {
            return DietaryCompatibility::Unknown;
        }

        $seen[$ingredientId] = true;
        $profile = IngredientDietaryProfile::query()->where('ingredient_id', $ingredientId)->first();
        $assessment = IngredientDietaryAssessment::query()
            ->where('ingredient_id', $ingredientId)
            ->where('tag_id', $tag->getKey())
            ->first();

        if (! $this->isReviewed($profile?->evidence, $profile?->reviewed_by, $profile?->reviewed_at)
            || $profile?->composition_status !== IngredientCompositionStatus::Complete
            || $assessment === null
            || $assessment->rule_version !== (int) config("dietary.claims.{$tag->code}.rule_version", config('dietary.default_rule_version', 1))) {
            return DietaryCompatibility::Unknown;
        }

        $origin = $profile->origin_classification;
        $outcome = $assessment->outcome;

        if ($tag->code === 'vegan' && in_array($origin, [IngredientOriginClassification::AnimalFlesh, IngredientOriginClassification::AnimalDerivedOther], true)) {
            return DietaryCompatibility::Incompatible;
        }

        if ($tag->code === 'vegetarian' && $origin === IngredientOriginClassification::AnimalFlesh) {
            return DietaryCompatibility::Incompatible;
        }

        if ($outcome === DietaryCompatibility::Incompatible) {
            return DietaryCompatibility::Incompatible;
        }

        if ($outcome !== DietaryCompatibility::Compatible
            || ! $this->isReviewed($assessment->evidence, $assessment->reviewed_by, $assessment->reviewed_at)
            || $origin === IngredientOriginClassification::Unknown) {
            return DietaryCompatibility::Unknown;
        }

        $components = IngredientComponent::query()->where('ingredient_id', $ingredientId)->get();

        if ($components->contains(fn (IngredientComponent $component): bool => $component->component_ingredient_id === null)) {
            return DietaryCompatibility::Unknown;
        }

        foreach ($components as $component) {
            $componentOutcome = $this->resolveIngredient((int) $component->component_ingredient_id, $tag, $seen);

            if ($componentOutcome !== DietaryCompatibility::Compatible) {
                return $componentOutcome;
            }
        }

        return DietaryCompatibility::Compatible;
    }

    /**
     * @param  array<int, true>  $seen
     * @param  list<string>  $warnings
     */
    private function collectWarnings(int $ingredientId, Tag $tag, array $seen, array &$warnings): void
    {
        if (isset($seen[$ingredientId])) {
            $warnings[] = "Ingredient #{$ingredientId} has a circular compound-ingredient reference.";

            return;
        }

        $seen[$ingredientId] = true;
        $profile = IngredientDietaryProfile::query()->where('ingredient_id', $ingredientId)->first();
        $assessment = IngredientDietaryAssessment::query()
            ->where('ingredient_id', $ingredientId)
            ->where('tag_id', $tag->getKey())
            ->first();

        if ($profile === null || $profile->composition_status !== IngredientCompositionStatus::Complete) {
            $warnings[] = "Ingredient #{$ingredientId} has unknown or incomplete composition.";
        }

        if ($profile !== null && ! $this->isReviewed($profile->evidence, $profile->reviewed_by, $profile->reviewed_at)) {
            $warnings[] = "Ingredient #{$ingredientId} is missing reviewed composition evidence.";
        }

        if ($profile !== null && $profile->origin_classification === IngredientOriginClassification::Unknown) {
            $warnings[] = "Ingredient #{$ingredientId} has unknown origin.";
        }

        if ($profile !== null
            && (($tag->code === 'vegan' && in_array($profile->origin_classification, [IngredientOriginClassification::AnimalFlesh, IngredientOriginClassification::AnimalDerivedOther], true))
                || ($tag->code === 'vegetarian' && $profile->origin_classification === IngredientOriginClassification::AnimalFlesh))) {
            $warnings[] = "Ingredient #{$ingredientId} has an animal-origin conflict with the {$tag->code} classification.";
        }

        if ($assessment === null || $assessment->outcome === DietaryCompatibility::Unknown) {
            $warnings[] = "Ingredient #{$ingredientId} has no resolved {$tag->code} assessment.";
        } elseif ($assessment->outcome === DietaryCompatibility::Incompatible) {
            $warnings[] = "Ingredient #{$ingredientId} conflicts with the {$tag->code} classification.";
        } elseif (! $this->isReviewed($assessment->evidence, $assessment->reviewed_by, $assessment->reviewed_at)) {
            $warnings[] = "Ingredient #{$ingredientId} is missing reviewed {$tag->code} evidence.";
        }

        foreach (IngredientComponent::query()->where('ingredient_id', $ingredientId)->get() as $component) {
            if ($component->component_ingredient_id === null) {
                $warnings[] = "Ingredient #{$ingredientId} has an unresolved component: {$component->unresolved_component}.";

                continue;
            }

            $this->collectWarnings((int) $component->component_ingredient_id, $tag, $seen, $warnings);
        }
    }

    /**
     * @param  array<int, true>  $seen
     * @param  list<string>  $evidence
     */
    private function collectEvidence(int $ingredientId, Tag $tag, array $seen, array &$evidence): void
    {
        if (isset($seen[$ingredientId])) {
            return;
        }

        $seen[$ingredientId] = true;
        $profile = IngredientDietaryProfile::query()->where('ingredient_id', $ingredientId)->first();
        $assessment = IngredientDietaryAssessment::query()
            ->where('ingredient_id', $ingredientId)
            ->where('tag_id', $tag->getKey())
            ->first();

        foreach ($profile?->evidence ?? [] as $reference) {
            if (is_string($reference) && trim($reference) !== '') {
                $evidence[] = "Ingredient #{$ingredientId} composition: ".trim($reference);
            }
        }

        foreach ($assessment?->evidence ?? [] as $reference) {
            if (is_string($reference) && trim($reference) !== '') {
                $evidence[] = "Ingredient #{$ingredientId} {$tag->code} assessment: ".trim($reference);
            }
        }

        foreach (IngredientComponent::query()->where('ingredient_id', $ingredientId)->get() as $component) {
            foreach ($component->evidence ?? [] as $reference) {
                if (is_string($reference) && trim($reference) !== '') {
                    $evidence[] = "Ingredient #{$ingredientId} component: ".trim($reference);
                }
            }

            if ($component->component_ingredient_id !== null) {
                $this->collectEvidence((int) $component->component_ingredient_id, $tag, $seen, $evidence);
            }
        }

        if ($tag->classification === TagClassification::IngredientAllergen) {
            foreach (IngredientAllergenAssessment::query()->where('ingredient_id', $ingredientId)->get() as $allergen) {
                foreach ($allergen->evidence ?? [] as $reference) {
                    if (is_string($reference) && trim($reference) !== '') {
                        $evidence[] = "Ingredient #{$ingredientId} {$allergen->allergen_code}: ".trim($reference);
                    }
                }
            }
        }
    }

    private function isReviewed(?array $evidence, int|string|null $reviewerId, mixed $reviewedAt): bool
    {
        if (collect($evidence)->filter(fn (mixed $reference): bool => is_string($reference) && trim($reference) !== '')->isEmpty()
            || $reviewerId === null
            || $reviewedAt === null) {
            return false;
        }

        $reviewerId = (int) $reviewerId;

        if (! array_key_exists($reviewerId, $this->reviewers)) {
            $this->reviewers[$reviewerId] = User::query()->find($reviewerId);
        }

        $reviewer = $this->reviewers[$reviewerId];

        return $reviewer !== null
            && $reviewer->hasVerifiedEmail()
            && $reviewer->can(AppPermissions::DIETARY_CLAIMS_REVIEW);
    }
}
