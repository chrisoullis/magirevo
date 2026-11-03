<?php

namespace App\Services\Recipes;

use App\Models\Recipe;
use App\Models\User;
use App\Services\RecipeAccessService;

class RecipeVariationAttribution
{
    public function present(Recipe $recipe, ?User $viewer, string $locale): array
    {
        $derivation = $recipe->derivation;
        $source = $recipe->derivedFrom;
        $visible = $source !== null && ! $source->trashed() && app(RecipeAccessService::class)->canView($viewer, $source);
        $translation = $visible ? $source->displayTranslation($locale) : null;
        $publication = $recipe->isPubliclyAccessible() ? $recipe->currentRevision?->publication_context : null;
        $disclose = (bool) ($publication['disclosure_allowed'] ?? $derivation?->attribution_snapshot['disclosure_allowed'] ?? false);

        return [
            'summary' => $publication['summaries'][$locale] ?? null,
            'license_identifier' => $publication['license_identifier'] ?? null,
            'license_url' => isset($publication['license_url']) && filter_var($publication['license_url'], FILTER_VALIDATE_URL) && str_starts_with($publication['license_url'], 'https://') ? $publication['license_url'] : null,
            'title' => $disclose ? $derivation?->source_title_snapshot : null,
            'author' => $disclose ? $derivation?->source_author_snapshot : null,
            'url' => $translation ? route('recipes.show', ['locale' => $translation->locale, 'slug' => $translation->slug], false) : null,
            'updated' => $visible && $source->current_revision_id !== $derivation?->source_recipe_revision_id,
        ];
    }
}
