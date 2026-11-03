<?php

namespace App\Livewire;

use App\Enums\MealType;
use App\Models\Category;
use App\Models\Difficulty;
use App\Models\Ingredient;
use App\Models\IngredientSubstitution;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingList;
use App\Models\Tag;
use App\Models\User;
use App\Services\DietaryClaimVisibility;
use App\Services\HouseholdAccess;
use App\Services\MealPlanService;
use App\Services\ShoppingListService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class WhatCanICook extends Component
{
    private const RESULT_LIMIT = 60;

    #[Locked]
    public string $locale = 'en';

    public string $ingredientSearch = '';

    /** @var array<int, int|string> */
    public array $selectedIngredientIds = [];

    /** @var array<int|string, array<int|string, array{substitution_id: int|string, replacement_ingredient_id: int|string}>> */
    #[Locked]
    public array $confirmedSubstitutions = [];

    public bool $onlyComplete = false;

    public bool $includeMyVariations = false;

    public string $category = '';

    public string $difficulty = '';

    public string $maximumMinutes = '';

    public string $dietaryClaim = '';

    public function mount(string $locale): void
    {
        $this->locale = $locale;
        $this->guardLocale();
        $code = request()->query('ingredient');
        if (is_string($code) && mb_strlen($code) <= 100) {
            $ingredient = $this->availableIngredientsQuery()->where('code', $code)->first();
            $this->selectedIngredientIds = $ingredient ? [(int) $ingredient->getKey()] : [];
        }
    }

    public function updatedIngredientSearch(): void
    {
        $this->guardLocale();
        $this->validateOnly('ingredientSearch', [
            'ingredientSearch' => ['nullable', 'string', 'max:100'],
        ]);
    }

    public function updatedMaximumMinutes(): void
    {
        $this->validateOnly('maximumMinutes', [
            'maximumMinutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);
    }

    public function selectIngredient(int $ingredientId): void
    {
        $this->guardLocale();
        $ingredient = $this->availableIngredientsQuery()->findOrFail($ingredientId);
        $selectedIds = $this->selectedIngredients()
            ->map(fn (Ingredient $selectedIngredient): int => (int) $selectedIngredient->getKey())
            ->all();

        if (! in_array($ingredient->getKey(), $selectedIds, true)) {
            $this->selectedIngredientIds = [...$selectedIds, (int) $ingredient->getKey()];
        }

        $this->reset('ingredientSearch');
        $this->resetErrorBag('selectedIngredientIds');
    }

    public function removeIngredient(int $ingredientId): void
    {
        $this->selectedIngredientIds = $this->selectedIngredients()
            ->map(fn (Ingredient $ingredient): int => (int) $ingredient->getKey())
            ->reject(fn (int $selectedId): bool => $selectedId === $ingredientId)
            ->values()
            ->all();
    }

    public function clearIngredients(): void
    {
        $this->reset('selectedIngredientIds', 'confirmedSubstitutions');
    }

    public function clearFilters(): void
    {
        $this->reset('includeMyVariations', 'onlyComplete', 'category', 'difficulty', 'maximumMinutes', 'dietaryClaim');
        $this->resetErrorBag();
    }

    public function confirmSubstitution(
        int $recipeId,
        int $ingredientId,
        int $substitutionId,
        MealPlanService $plans,
    ): void {
        $this->guardLocale();
        $recipe = $this->availableRecipes()->whereKey($recipeId)->firstOrFail();
        $selectedIngredientIds = $this->selectedIngredients()
            ->map(fn (Ingredient $ingredient): int => (int) $ingredient->getKey())
            ->all();
        $lineExists = RecipeIngredient::query()
            ->where('recipe_id', $recipe->getKey())
            ->where('ingredient_id', $ingredientId)
            ->where('is_optional', false)
            ->exists();
        $substitution = IngredientSubstitution::query()
            ->approved()
            ->applicableToRecipe((int) $recipe->getKey())
            ->whereKey($substitutionId)
            ->where('ingredient_id', $ingredientId)
            ->first();

        abort_unless(
            $lineExists
                && $substitution !== null
                && in_array((int) $substitution->replacement_ingredient_id, $selectedIngredientIds, true),
            404,
        );

        $choices = $this->confirmedSubstitutions[$recipe->getKey()]
            ?? $this->confirmedSubstitutions[(string) $recipe->getKey()]
            ?? [];
        $choices = is_array($choices) ? $choices : [];
        unset($choices[$ingredientId], $choices[(string) $ingredientId]);
        $choices = $plans->validatedSubstitutionChoices($recipe, $choices);
        $choices[$ingredientId] = [
            'substitution_id' => $substitutionId,
            'replacement_ingredient_id' => (int) $substitution->replacement_ingredient_id,
        ];
        $this->confirmedSubstitutions[$recipeId] = $choices;
    }

    public function removeConfirmedSubstitution(int $recipeId, int $ingredientId): void
    {
        $choices = $this->confirmedSubstitutions[$recipeId] ?? $this->confirmedSubstitutions[(string) $recipeId] ?? [];
        $choices = is_array($choices) ? $choices : [];
        unset($choices[$ingredientId], $choices[(string) $ingredientId]);

        if ($choices === []) {
            unset($this->confirmedSubstitutions[$recipeId], $this->confirmedSubstitutions[(string) $recipeId]);

            return;
        }

        $this->confirmedSubstitutions[$recipeId] = $choices;
    }

    public function addMissingIngredients(
        int $recipeId,
        MealPlanService $plans,
        ShoppingListService $shoppingLists,
        HouseholdAccess $access,
    ): void {
        $this->guardLocale();

        $user = $this->currentUser();
        abort_unless($user !== null && $access->canUsePlanner($user), 403);

        $selectedIngredientIds = $this->selectedIngredients()
            ->map(fn (Ingredient $ingredient): int => (int) $ingredient->getKey())
            ->all();
        abort_if($selectedIngredientIds === [], 404);

        $recipe = $this->availableRecipes()->whereKey($recipeId)->firstOrFail();
        $confirmedSubstitutions = $this->confirmedForRecipe($recipe, $plans);
        $plan = $plans->findPlan($user, null, MealPlan::weekStartFor(now()));

        if ($plan === null) {
            $this->addError('shoppingList', __('site.cooking_shopping_list_unavailable'));

            return;
        }

        $list = $shoppingLists->findList($user, $plan);

        if ($list === null || ! Gate::forUser($user)->allows('update', $list)) {
            $this->addError('shoppingList', __('site.cooking_shopping_list_unavailable'));

            return;
        }

        $requiredIngredientIds = RecipeIngredient::query()
            ->where('recipe_id', $recipe->getKey())
            ->where('is_optional', false)
            ->distinct()
            ->pluck('ingredient_id')
            ->map(fn (int|string $id): int => (int) $id);
        $missingIngredientIds = $requiredIngredientIds
            ->reject(fn (int $ingredientId): bool => in_array($ingredientId, $selectedIngredientIds, true))
            ->map(function (int $ingredientId) use ($confirmedSubstitutions, $selectedIngredientIds, $recipe): ?int {
                $choice = $confirmedSubstitutions[$ingredientId] ?? null;

                if ($choice === null) {
                    return $ingredientId;
                }

                $replacementId = IngredientSubstitution::query()
                    ->approved()
                    ->applicableToRecipe((int) $recipe->getKey())
                    ->whereKey($choice['substitution_id'])
                    ->where('ingredient_id', $ingredientId)
                    ->where('replacement_ingredient_id', $choice['replacement_ingredient_id'])
                    ->value('replacement_ingredient_id');

                if ($replacementId === null) {
                    return $ingredientId;
                }

                if (in_array((int) $replacementId, $selectedIngredientIds, true)) {
                    return null;
                }

                return (int) $replacementId;
            })
            ->filter()
            ->unique()
            ->values();
        $missingIngredients = Ingredient::query()->whereIn('id', $missingIngredientIds)->get();
        $addedCount = $shoppingLists->addMissingIngredients($user, $list, $missingIngredients);

        session()->flash('cooking-shopping-status', trans_choice('site.cooking_added_to_list', $addedCount, [
            'count' => $addedCount,
        ]));
    }

    public function addToMealPlan(int $recipeId, MealPlanService $plans, HouseholdAccess $access): void
    {
        $this->guardLocale();

        $user = $this->currentUser();
        abort_unless($user !== null && $access->canUsePlanner($user), 403);

        $recipe = $this->availableRecipes()->whereKey($recipeId)->firstOrFail();
        $confirmedSubstitutions = $this->confirmedForRecipe($recipe, $plans);
        $plan = $plans->ensurePlan($user, null, MealPlan::weekStartFor(now()));
        $entry = $plans->addEntry(
            $user,
            $plan,
            $recipe,
            now()->toDateString(),
            MealType::Dinner,
            max(1, (int) ($recipe->base_servings ?: 4)),
            confirmedSubstitutions: $confirmedSubstitutions,
        );

        session()->flash('cooking-planner-status', __('site.cooking_added_to_planner'));
        session()->flash('cooking-planner-url', route('planner.index', ['locale' => $this->locale, 'week' => $plan->week_start->toDateString()], false));
        session()->flash('cooking-planner-entry-id', $entry->getKey());
    }

    public function render(
        MealPlanService $plans,
        ShoppingListService $shoppingLists,
        HouseholdAccess $access,
    ): View {
        $this->guardLocale();

        $selectedIngredients = $this->selectedIngredients();
        $selectedIngredientIds = $selectedIngredients
            ->map(fn (Ingredient $ingredient): int => (int) $ingredient->getKey())
            ->all();
        $recipes = $selectedIngredientIds === [] ? collect() : $this->matchingRecipes($selectedIngredientIds);

        if ($this->dietaryClaim !== '') {
            $recipes = $recipes->reject(fn (Recipe $recipe): bool => $this->confirmedForRecipe($recipe, $plans) !== []);
        }

        $matchDetails = $this->ingredientMatchDetails($recipes, $selectedIngredientIds);
        $results = $recipes
            ->map(fn (Recipe $recipe): array => [
                'recipe' => $recipe,
                ...$matchDetails->get($recipe->getKey()),
                'totalMinutes' => $recipe->totalMinutes(),
            ])
            ->when($this->onlyComplete, fn (Collection $matches): Collection => $matches->filter(fn (array $match): bool => $match['missingCount'] === 0))
            ->sort(fn (array $first, array $second): int => [
                $first['missingCount'],
                -$first['coverage'],
                $first['recipe']->getKey(),
            ] <=> [
                $second['missingCount'],
                -$second['coverage'],
                $second['recipe']->getKey(),
            ])
            ->take(self::RESULT_LIMIT)
            ->values();
        $user = $this->currentUser();
        $canUsePlanner = $user !== null && $access->canUsePlanner($user);
        $shoppingList = $canUsePlanner
            ? $this->existingPersonalShoppingList($user, $plans, $shoppingLists)
            : null;

        return view('livewire.what-can-i-cook', [
            'canIncludeMyVariations' => config('recipe-variations.enabled') && ($this->currentUser()?->hasVerifiedEmail() ?? false),
            'ingredientOptions' => $this->ingredientOptions($selectedIngredientIds),
            'selectedIngredients' => $selectedIngredients,
            'categories' => $this->categoryOptions(),
            'difficulties' => $this->difficultyOptions(),
            'dietaryClaims' => $this->dietaryClaimOptions(),
            'results' => $results,
            'canAddMissingIngredients' => $shoppingList !== null
                && Gate::forUser($user)->allows('update', $shoppingList),
            'canAddToPlanner' => $canUsePlanner,
            'shoppingListUrl' => $canUsePlanner
                ? route('planner.shopping', ['locale' => $this->locale], false)
                : null,
        ]);
    }

    private function guardLocale(): void
    {
        abort_unless(in_array($this->locale, config('translatable.locales'), true), 404);
        app()->setLocale($this->locale);
    }

    /**
     * @return Collection<int, Ingredient>
     */
    private function selectedIngredients(): Collection
    {
        $ids = collect($this->selectedIngredientIds)
            ->filter(fn (mixed $id): bool => (is_int($id) || is_string($id)) && ctype_digit((string) $id))
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return $this->availableIngredientsQuery()
            ->whereIn('id', $ids)
            ->with(['translations', 'catalogGroup.translations'])
            ->orderBy('id')
            ->get();
    }

    private function availableRecipes(?Builder $query = null): Builder
    {
        return ($query ?? Recipe::query())->where(function (Builder $recipes): void {
            $recipes->where(fn (Builder $public): Builder => $public->published());
            $user = $this->currentUser();

            if ($this->includeMyVariations && config('recipe-variations.enabled') && $user?->hasVerifiedEmail()) {
                $recipes->orWhere(fn (Builder $owned): Builder => $owned
                    ->where('recipes.author_id', $user->getKey())
                    ->where('recipes.visibility', 'private')
                    ->whereNotNull('recipes.derived_from_recipe_id'));
            }
        });
    }

    private function availableIngredientsQuery(): Builder
    {
        return Ingredient::query()
            ->where('is_active', true)
            ->where(fn (Builder $ingredients): Builder => $ingredients
                ->whereHas('recipeIngredients.recipe', fn (Builder $recipes): Builder => $this->availableRecipes($recipes))
                ->orWhereHas('substitutionSuggestions', fn (Builder $substitutions): Builder => $substitutions
                    ->approved()
                    ->whereHas('ingredient.recipeIngredients.recipe', fn (Builder $recipes): Builder => $this->availableRecipes($recipes))
                    ->where(fn (Builder $applicable): Builder => $applicable
                        ->whereNull('ingredient_substitutions.recipe_id')
                        ->orWhereHas('recipe', fn (Builder $recipes): Builder => $this->availableRecipes($recipes)
                            ->whereHas('recipeIngredients', fn (Builder $lines): Builder => $lines
                                ->whereColumn('recipe_ingredients.ingredient_id', 'ingredient_substitutions.ingredient_id'))))));
    }

    /**
     * @param  list<int>  $selectedIngredientIds
     * @return Collection<int, array{id: int, name: string}>
     */
    private function ingredientOptions(array $selectedIngredientIds): Collection
    {
        $search = trim($this->ingredientSearch);

        if (mb_strlen($search) < 2) {
            return collect();
        }

        return $this->availableIngredientsQuery()
            ->whereNotIn('id', $selectedIngredientIds)
            ->searchCatalogue($search)
            ->with('translations')
            ->orderBy('code')
            ->limit(12)
            ->get()
            ->map(fn (Ingredient $ingredient): array => [
                'id' => (int) $ingredient->getKey(),
                'name' => (string) ($ingredient->translate($this->locale)?->name ?? $ingredient->code),
            ]);
    }

    private function categoryOptions(): Collection
    {
        return Category::query()
            ->publiclyVisible()
            ->whereHas('recipes', fn (Builder $recipes): Builder => $this->availableRecipes($recipes))
            ->with('translations')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Category $category): ?array => filled($category->translate($this->locale)?->name)
                ? ['id' => (int) $category->getKey(), 'name' => (string) $category->translate($this->locale)->name]
                : null)
            ->filter()
            ->values();
    }

    private function difficultyOptions(): Collection
    {
        return Difficulty::query()
            ->where('is_active', true)
            ->whereIn('code', $this->availableRecipes()->whereNotNull('difficulty')->select('difficulty')->distinct())
            ->with('translations')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Difficulty $difficulty): array => [
                'code' => $difficulty->code,
                'name' => (string) ($difficulty->translate($this->locale)?->name ?? $difficulty->code),
            ]);
    }

    private function dietaryClaimOptions(): Collection
    {
        $claimCodes = array_keys(app(DietaryClaimVisibility::class)->publicClaimIndex());

        if ($claimCodes === []) {
            return collect();
        }

        return Tag::query()
            ->whereIn('code', $claimCodes)
            ->where('is_active', true)
            ->where('is_public_enabled', true)
            ->with('translations')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Tag $tag): ?array => filled($tag->translate($this->locale)?->name)
                ? ['code' => $tag->code, 'name' => (string) $tag->translate($this->locale)->name]
                : null)
            ->filter()
            ->values();
    }

    /**
     * @param  list<int>  $selectedIngredientIds
     * @return Collection<int, Recipe>
     */
    private function matchingRecipes(array $selectedIngredientIds): Collection
    {
        $requiredCounts = RecipeIngredient::query()
            ->select('recipe_id')
            ->selectRaw('COUNT(DISTINCT ingredient_id) AS required_count')
            ->where('is_optional', false)
            ->whereHas('recipe', fn (Builder $recipes): Builder => $this->availableRecipes($recipes))
            ->groupBy('recipe_id');
        $matchedCounts = RecipeIngredient::query()
            ->select('recipe_id')
            ->selectRaw('COUNT(DISTINCT ingredient_id) AS matched_count')
            ->where('is_optional', false)
            ->where(function (Builder $matches) use ($selectedIngredientIds): void {
                $matches->whereIn('ingredient_id', $selectedIngredientIds)
                    ->orWhereExists(function (\Illuminate\Database\Query\Builder $substitutions) use ($selectedIngredientIds): void {
                        $substitutions->selectRaw('1')
                            ->from('ingredient_substitutions')
                            ->whereColumn('ingredient_substitutions.ingredient_id', 'recipe_ingredients.ingredient_id')
                            ->where('ingredient_substitutions.status', 'approved')
                            ->whereIn('ingredient_substitutions.replacement_ingredient_id', $selectedIngredientIds)
                            ->where(fn (\Illuminate\Database\Query\Builder $applicable): \Illuminate\Database\Query\Builder => $applicable
                                ->whereNull('ingredient_substitutions.recipe_id')
                                ->orWhereColumn('ingredient_substitutions.recipe_id', 'recipe_ingredients.recipe_id'));
                    });
            })
            ->whereHas('recipe', fn (Builder $recipes): Builder => $this->availableRecipes($recipes))
            ->groupBy('recipe_id');
        $exactCounts = RecipeIngredient::query()
            ->select('recipe_id')
            ->selectRaw('COUNT(DISTINCT ingredient_id) AS exact_count')
            ->where('is_optional', false)
            ->whereIn('ingredient_id', $selectedIngredientIds)
            ->whereHas('recipe', fn (Builder $recipes): Builder => $this->availableRecipes($recipes))
            ->groupBy('recipe_id');
        $query = $this->availableRecipes()
            ->leftJoinSub($requiredCounts, 'required_ingredients', 'required_ingredients.recipe_id', '=', 'recipes.id')
            ->leftJoinSub($matchedCounts, 'matched_ingredients', 'matched_ingredients.recipe_id', '=', 'recipes.id')
            ->leftJoinSub($exactCounts, 'exact_ingredients', 'exact_ingredients.recipe_id', '=', 'recipes.id')
            ->whereHas('translations', fn (Builder $translations): Builder => $translations
                ->where('locale', $this->locale)
                ->where('title', '<>', '')
                ->where('slug', '<>', ''))
            ->select('recipes.*')
            ->selectRaw('COALESCE(required_ingredients.required_count, 0) AS required_ingredients_count')
            ->selectRaw('COALESCE(matched_ingredients.matched_count, 0) AS matched_ingredients_count')
            ->selectRaw('COALESCE(exact_ingredients.exact_count, 0) AS exact_ingredients_count')
            ->with('translations')
            ->where('matched_ingredients.matched_count', '>', 0)
            ->orderByRaw('(COALESCE(required_ingredients.required_count, 0) - COALESCE(matched_ingredients.matched_count, 0))')
            ->orderByRaw('CASE WHEN COALESCE(required_ingredients.required_count, 0) = 0 THEN 1 ELSE COALESCE(matched_ingredients.matched_count, 0)::numeric / required_ingredients.required_count END DESC')
            ->orderBy('recipes.id')
            ->limit(self::RESULT_LIMIT * 5);

        $categoryId = $this->publicCategoryId();

        if ($categoryId !== null) {
            $query->whereHas('categories', fn (Builder $categories): Builder => $categories
                ->publiclyVisible()
                ->where('categories.id', $categoryId));
        }

        $difficulty = $this->activeDifficultyCode();

        if ($difficulty !== null) {
            $query->where('recipes.difficulty', $difficulty);
        }

        if ($this->dietaryClaim !== '') {
            $claimIndex = app(DietaryClaimVisibility::class)->publicClaimIndex();
            $query->whereIn('recipes.id', $claimIndex[$this->dietaryClaim] ?? []);
        }

        $maximumMinutes = filter_var($this->maximumMinutes, FILTER_VALIDATE_INT);

        if ($maximumMinutes !== false && $maximumMinutes !== null && $maximumMinutes >= 1 && $maximumMinutes <= 1440) {
            $query->whereRaw(
                '(COALESCE(recipes.prep_minutes, 0) + COALESCE(recipes.cook_minutes, 0)) <= ?',
                [$maximumMinutes],
            );
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, Recipe>  $recipes
     * @param  list<int>  $selectedIngredientIds
     * @return Collection<int, array{requiredCount: int, matchedCount: int, missingCount: int, coverage: float, missingIngredients: list<string>, ingredientOptions: list<array<string, mixed>>}>
     */
    private function ingredientMatchDetails(Collection $recipes, array $selectedIngredientIds): Collection
    {
        if ($recipes->isEmpty()) {
            return collect();
        }

        $linesByRecipe = RecipeIngredient::query()
            ->whereIn('recipe_id', $recipes->modelKeys())
            ->where('is_optional', false)
            ->with('ingredient.translations')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('recipe_id');
        $originalIngredientIds = $linesByRecipe->flatten(1)->pluck('ingredient_id')->unique()->values();
        $substitutionsByIngredient = $originalIngredientIds->isEmpty()
            ? collect()
            : IngredientSubstitution::query()
                ->approved()
                ->whereIn('ingredient_id', $originalIngredientIds)
                ->whereHas('replacementIngredient', fn (Builder $replacement): Builder => $replacement->where('is_active', true))
                ->applicableToRecipes($recipes->modelKeys())
                ->with(['translations', 'replacementIngredient.translations', 'replacementIngredient.catalogGroup.translations'])
                ->get()
                ->groupBy('ingredient_id');

        return $recipes->mapWithKeys(function (Recipe $recipe) use ($linesByRecipe, $substitutionsByIngredient, $selectedIngredientIds): array {
            $linesByIngredient = $linesByRecipe->get($recipe->getKey(), collect())->groupBy('ingredient_id');
            $recipeChoices = $this->confirmedSubstitutions[$recipe->getKey()]
                ?? $this->confirmedSubstitutions[(string) $recipe->getKey()]
                ?? [];
            $recipeChoices = is_array($recipeChoices) ? $recipeChoices : [];
            $missingIngredients = [];
            $ingredientOptions = [];
            $matchedCount = 0;
            $possibleMatchedCount = 0;
            $substitutedCount = 0;

            foreach ($linesByIngredient as $ingredientId => $lines) {
                /** @var RecipeIngredient $line */
                $line = $lines->first();
                $originalId = (int) $ingredientId;
                $exactMatch = in_array($originalId, $selectedIngredientIds, true);
                $options = $substitutionsByIngredient->get($originalId, collect())
                    ->filter(fn (IngredientSubstitution $substitution): bool => $substitution->recipe_id === null
                        || (int) $substitution->recipe_id === (int) $recipe->getKey())
                    ->map(function (IngredientSubstitution $substitution) use ($originalId, $selectedIngredientIds, $recipeChoices): array {
                        $replacementId = (int) $substitution->replacement_ingredient_id;
                        $selected = in_array($replacementId, $selectedIngredientIds, true);
                        $confirmedChoice = $recipeChoices[$originalId] ?? $recipeChoices[(string) $originalId] ?? null;
                        $confirmed = is_array($confirmedChoice)
                            && (string) ($confirmedChoice['substitution_id'] ?? '') === (string) $substitution->getKey()
                            && (int) ($confirmedChoice['replacement_ingredient_id'] ?? 0) === $replacementId;

                        return [
                            'id' => (int) $substitution->getKey(),
                            'replacementId' => $replacementId,
                            'name' => (string) ($substitution->replacementIngredient->translate($this->locale)?->name
                                ?? $substitution->replacementIngredient->code),
                            'explanation' => $substitution->translate($this->locale)?->explanation,
                            'url' => $substitution->replacementIngredient->encyclopediaUrl($this->locale),
                            'selected' => $selected,
                            'confirmed' => $confirmed,
                        ];
                    })
                    ->values()
                    ->all();
                $confirmedOption = collect($options)->first(
                    fn (array $option): bool => $option['confirmed'] && $option['selected'],
                );
                $hasAvailableOption = collect($options)->contains(fn (array $option): bool => $option['selected']);
                $isMatched = $exactMatch || $confirmedOption !== null;
                $isPossible = $exactMatch || $hasAvailableOption;
                $usingSubstitution = $confirmedOption !== null;

                if ($isMatched) {
                    $matchedCount++;
                    $substitutedCount += $usingSubstitution ? 1 : 0;
                } else {
                    $missingIngredients[] = (string) ($line->ingredient->translate($this->locale)?->name
                        ?? $line->ingredient->code);
                }

                if ($isPossible) {
                    $possibleMatchedCount++;
                }

                if ($options !== []) {
                    $ingredientOptions[] = [
                        'ingredientId' => $originalId,
                        'ingredientName' => (string) ($line->ingredient->translate($this->locale)?->name ?? $line->ingredient->code),
                        'options' => $options,
                        'lineIds' => $lines->pluck('id')->map(fn (int|string $id): int => (int) $id)->all(),
                    ];
                }
            }

            $requiredCount = $linesByIngredient->count();
            $possibleCount = $possibleMatchedCount;
            $status = $matchedCount === $requiredCount
                ? ($substitutedCount === 0 ? 'exact' : 'substitution')
                : ($possibleCount === $requiredCount ? 'possible' : 'missing');

            return [$recipe->getKey() => [
                'requiredCount' => $requiredCount,
                'matchedCount' => $matchedCount,
                'missingCount' => $requiredCount - $matchedCount,
                'coverage' => $requiredCount === 0 ? 1.0 : $matchedCount / $requiredCount,
                'missingIngredients' => $missingIngredients,
                'ingredientOptions' => $ingredientOptions,
                'status' => $status,
                'substitutedCount' => $substitutedCount,
            ]];
        });
    }

    /**
     * @return array<int, array{substitution_id: int, replacement_ingredient_id: int}>
     */
    private function confirmedForRecipe(Recipe $recipe, MealPlanService $plans): array
    {
        $choices = $this->confirmedSubstitutions[$recipe->getKey()]
            ?? $this->confirmedSubstitutions[(string) $recipe->getKey()]
            ?? [];

        return $plans->validatedSubstitutionChoices($recipe, is_array($choices) ? $choices : []);
    }

    private function publicCategoryId(): ?int
    {
        if ($this->category === '' || ! ctype_digit($this->category)) {
            return null;
        }

        $categoryId = (int) $this->category;

        return Category::query()->publiclyVisible()->whereKey($categoryId)->exists() ? $categoryId : null;
    }

    private function activeDifficultyCode(): ?string
    {
        if ($this->difficulty === '') {
            return null;
        }

        return Difficulty::query()
            ->where('is_active', true)
            ->where('code', $this->difficulty)
            ->exists()
            ? $this->difficulty
            : null;
    }

    private function existingPersonalShoppingList(
        User $user,
        MealPlanService $plans,
        ShoppingListService $shoppingLists,
    ): ?ShoppingList {
        $plan = $plans->findPlan($user, null, MealPlan::weekStartFor(now()));

        return $plan === null ? null : $shoppingLists->findList($user, $plan);
    }

    private function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
