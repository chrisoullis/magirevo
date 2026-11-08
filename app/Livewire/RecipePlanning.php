<?php

namespace App\Livewire;

use App\Enums\HouseholdAbility;
use App\Enums\MealType;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Services\HouseholdAccess;
use App\Services\MealPlanService;
use App\Services\RecipeComponentResolver;
use App\Services\RecipeContentPresenter;
use App\Services\ShoppingListService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RecipePlanning extends Component
{
    #[Locked]
    public int $recipeId;

    #[Locked]
    public string $locale;

    public ?int $householdId = null;

    public string $date = '';

    public string $meal = 'dinner';

    public int $servings = 4;

    #[Locked]
    public bool $added = false;

    #[Locked]
    public ?string $plannerUrl = null;

    #[Locked]
    public ?string $shoppingUrl = null;

    #[Locked]
    public int $excludedEntries = 0;

    public function mount(int $recipeId, string $locale): void
    {
        $this->recipeId = $recipeId;
        $this->locale = $locale;
        $recipe = $this->recipe();
        $this->date = now()->toDateString();
        $requested = request()->query('servings');
        $this->servings = is_scalar($requested) && is_numeric($requested)
            ? max(1, min(48, (int) $requested)) : app(RecipeContentPresenter::class)->baseServings($recipe);
    }

    public function addToPlan(): void
    {
        $this->save(false);
    }

    public function addToShopping(): void
    {
        $this->save(true);
    }

    private function save(bool $shopping): void
    {
        app()->setLocale($this->locale);
        $recipe = $this->recipe();
        $actor = auth()->user();
        $access = app(HouseholdAccess::class);
        abort_unless($actor && $access->canUsePlanner($actor), 403);
        if ($this->added) {
            return;
        }
        $this->validate([
            'householdId' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'meal' => ['required', Rule::in(MealType::values())],
            'servings' => ['required', 'integer', 'between:1,48'],
        ], ['*' => __('recipe_detail.invalid_plan')]);
        $household = $this->householdId === null ? null : Household::query()->findOrFail($this->householdId);
        if ($household) {
            abort_unless($access->can($actor, $household, HouseholdAbility::EditPlans)
                && (! $shopping || $access->can($actor, $household, HouseholdAbility::EditShopping)), 403);
        }
        $plans = app(MealPlanService::class);
        if (! $plans->recipeAddable($actor, $household, $recipe)) {
            throw ValidationException::withMessages(['recipe' => __('planner.errors.recipe_not_shareable')]);
        }
        $this->excludedEntries = DB::transaction(function () use ($actor, $household, $recipe, $plans, $shopping): int {
            $plan = $plans->ensurePlan($actor, $household, $this->date);
            $plans->addEntry($actor, $plan, $recipe, $this->date, MealType::from($this->meal), $this->servings);
            if ($shopping) {
                $lists = app(ShoppingListService::class);

                return $lists->generate($actor, $lists->ensureList($actor, $plan))['excluded_entries'];
            }

            return 0;
        });
        $parameters = ['locale' => $this->locale, 'week' => MealPlan::weekStartFor($this->date)->toDateString()];
        if ($household) {
            $parameters['household'] = $household->id;
        }
        $this->plannerUrl = route($household ? 'households.planner' : 'planner.index', $parameters, false);
        $this->shoppingUrl = $shopping ? route($household ? 'households.shopping' : 'planner.shopping', $parameters, false) : null;
        $this->added = true;
    }

    private function recipe(): Recipe
    {
        abort_unless(in_array($this->locale, config('translatable.locales'), true), 404);
        $recipe = Recipe::query()->findOrFail($this->recipeId);
        abort_unless(app(RecipeComponentResolver::class)->canResolve($recipe, auth()->user()), 404);

        return $recipe;
    }

    public function render(): View
    {
        app()->setLocale($this->locale);
        $this->recipe();
        $user = auth()->user();
        $canPlan = $user && app(HouseholdAccess::class)->canUsePlanner($user);
        $households = $canPlan ? Household::query()->whereHas('members', fn ($members) => $members->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'admin', 'member']))->orderBy('name')->limit(50)->get(['id', 'name']) : collect();

        return view('livewire.recipe-planning', compact('canPlan', 'households'));
    }
}
