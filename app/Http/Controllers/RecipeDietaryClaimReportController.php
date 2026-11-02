<?php

namespace App\Http\Controllers;

use App\Models\DietaryClaim;
use App\Models\Recipe;
use App\Services\DietaryClaimService;
use App\Services\DietaryClaimVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecipeDietaryClaimReportController extends Controller
{
    public function store(
        Request $request,
        string $locale,
        string $slug,
        DietaryClaim $claim,
        DietaryClaimVisibility $visibility,
        DietaryClaimService $service,
    ): RedirectResponse {
        $recipe = Recipe::query()
            ->published()
            ->whereTranslation('slug', $slug, $locale)
            ->firstOrFail();

        abort_unless(
            (int) $claim->recipe_id === (int) $recipe->getKey()
                && $visibility->publicClaims($recipe)->contains('id', $claim->getKey()),
            404,
        );

        $validated = $request->validate([
            'notes' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $service->report($request->user(), $claim, $validated['notes']);

        return to_route('recipes.show', ['locale' => $locale, 'slug' => $slug])
            ->with('dietary-report-status', __('site.dietary_report_received'));
    }
}
