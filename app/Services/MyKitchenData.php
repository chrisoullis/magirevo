<?php

namespace App\Services;

use App\Models\HouseholdInvitation;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\ShoppingList;
use App\Models\User;
use App\Support\AppPermissions;
use Illuminate\Database\Eloquent\Builder;

/** Bounded, read-only previews; full workflows remain in their existing modules. */
class MyKitchenData
{
    public function __construct(private HouseholdAccess $access, private MealPlanService $plans) {}

    public function shoppingQuery(User $user): Builder
    {
        return ShoppingList::query()->whereHas('mealPlan', fn ($query) => $query->where(fn ($scope) => $scope
            ->where(fn ($personal) => $personal->whereNull('household_id')->where('created_by', $user->id))
            ->orWhereIn('household_id', $user->households()->select('households.id'))));
    }

    public function counts(User $user): array
    {
        $own = Recipe::query()->where('author_id', $user->id)->selectRaw('COUNT(*) FILTER (WHERE derived_from_recipe_id IS NULL) AS originals, COUNT(*) FILTER (WHERE derived_from_recipe_id IS NOT NULL) AS variations')->first();
        $canPlan = $this->access->canUsePlanner($user);

        return [
            'saved' => $user->favoriteRecipes()->published()->count(),
            'shared' => $user->can(AppPermissions::RECIPES_VIEW_SHARED) ? $user->sharedRecipes()->count() : null,
            'originals' => config('recipe-variations.enabled') || $user->can('create', Recipe::class) ? (int) $own->originals : null,
            'variations' => config('recipe-variations.enabled') ? (int) $own->variations : null,
            'households' => $canPlan ? $user->households()->count() : null,
            'shopping' => $canPlan ? $this->shoppingQuery($user)->whereHas('mealPlan', fn ($plan) => $plan->whereDate('week_start', MealPlan::weekStartFor(now())->toDateString()))->count() : null,
        ];
    }

    public function sections(User $user, string $locale): array
    {
        $canPlan = $this->access->canUsePlanner($user);
        $canShare = $user->can(AppPermissions::RECIPES_VIEW_SHARED);
        $canOwn = config('recipe-variations.enabled') || $user->can('create', Recipe::class);
        $media = ['translations', 'media.translations', 'currentRevision:id,status,media_snapshot'];
        $saved = $user->favoriteRecipes()->published()->with($media)->latest('recipe_favorites.created_at')->orderByDesc('recipes.id')->limit(4)->get();
        $shared = $canShare ? $user->sharedRecipes()->with([...$media, 'author'])->latest('recipe_shares.created_at')->orderByDesc('recipes.id')->limit(4)->get() : collect();
        $ownQuery = fn () => Recipe::query()->where('author_id', $user->id)->select('recipes.*')->with($media)
            ->addSelect(['latest_editor_status' => RecipeRevision::query()->select('status')->whereColumn('recipe_id', 'recipes.id')->orderByDesc('revision_number')->limit(1)])
            ->latest('updated_at')->orderByDesc('recipes.id')->limit(4);
        $originals = $canOwn ? $ownQuery()->whereNull('derived_from_recipe_id')->get() : collect();
        $variations = config('recipe-variations.enabled') ? $ownQuery()->whereNotNull('derived_from_recipe_id')->get() : collect();
        $statusCounts = $canOwn ? Recipe::query()->where('author_id', $user->id)->whereNull('derived_from_recipe_id')
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'draft' AND rejection_reason IS NULL) AS draft, COUNT(*) FILTER (WHERE status = 'pending') AS in_review, COUNT(*) FILTER (WHERE status = 'published') AS published, COUNT(*) FILTER (WHERE rejection_reason IS NOT NULL AND status = 'draft') AS rejected")->first()->only(['draft', 'in_review', 'published', 'rejected']) : [];
        $start = MealPlan::weekStartFor(now());
        $days = collect(range(0, 6))->map(fn ($offset) => $start->addDays($offset));
        $plan = $canPlan ? $this->plans->findPlan($user, null, $start) : null;
        $planned = $plan?->entries()->whereBetween('planned_on', [$start->toDateString(), $start->addDays(6)->toDateString()])
            ->selectRaw('planned_on, COUNT(*) AS total')->groupBy('planned_on')->pluck('total', 'planned_on') ?? collect();
        $households = $canPlan ? $user->households()->withCount('members')->orderBy('households.name')->limit(4)->get() : collect();
        $invitations = $canPlan ? HouseholdInvitation::query()->usable()->where('email', mb_strtolower($user->email))
            ->with('household:id,name')->latest('id')->limit(4)->get() : collect();
        $shopping = $canPlan ? $this->shoppingQuery($user)->whereHas('mealPlan', fn ($query) => $query->whereDate('week_start', $start->toDateString()))
            ->with(['mealPlan.household:id,name'])->withCount(['items', 'items as checked_items_count' => fn ($items) => $items->whereNotNull('checked_at')])->latest('id')->limit(4)->get() : collect();

        return ['counts' => $this->counts($user), ...compact('locale', 'canPlan', 'canShare', 'canOwn', 'saved', 'shared', 'originals', 'variations', 'statusCounts', 'start', 'days', 'planned', 'households', 'invitations', 'shopping')];
    }
}
