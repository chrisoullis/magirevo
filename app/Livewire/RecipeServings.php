<?php

namespace App\Livewire;

use App\Models\Ingredient;
use App\Models\IngredientSubstitution;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use App\Models\UserRecipeIngredientChange;
use App\Services\RecipeAccessService;
use App\Services\RecipeContentPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class RecipeServings extends Component
{
    private const MIN_SERVINGS = 1;

    private const MAX_SERVINGS = 48;

    #[Locked]
    public int $recipeId;

    #[Locked]
    public string $locale = 'en';

    #[Url(as: 'servings', except: '')]
    public string $servings = '';

    public ?int $editingRecipeIngredientId = null;

    public ?int $alternativeIngredientId = null;

    public string $ingredientSearch = '';

    public ?string $personalNote = null;

    public function mount(int $recipeId, string $locale, int $baseServings): void
    {
        $this->recipeId = $recipeId;
        $this->locale = $locale;
        $this->servings = $this->servings === '' ? '' : (string) $this->clamp($this->servings, $baseServings);
    }

    public function increase(): void
    {
        $this->servings = (string) $this->clamp($this->current() + 1, $this->base());
    }

    public function decrease(): void
    {
        $this->servings = (string) $this->clamp($this->current() - 1, $this->base());
    }

    public function resetServings(): void
    {
        $this->servings = '';
    }

    public function editPersonalChange(int $recipeIngredientId): void
    {
        app()->setLocale($this->locale);

        $recipe = $this->recipe();
        $user = $this->user();
        $line = $this->recipeLine($recipe, $recipeIngredientId);
        $change = $user->recipeIngredientChanges()
            ->where('recipe_ingredient_id', $line->getKey())
            ->first();

        if ($change === null) {
            Gate::authorize('create', [UserRecipeIngredientChange::class, $recipe]);
        } else {
            Gate::authorize('update', $change);
        }

        $this->editingRecipeIngredientId = (int) $line->getKey();
        $this->alternativeIngredientId = $change?->alternative_ingredient_id;
        $this->personalNote = $change?->note;
        $this->ingredientSearch = (string) ($change?->alternativeIngredient?->translate($this->locale)?->name ?? '');
    }

    public function savePersonalChange(int $recipeIngredientId): void
    {
        app()->setLocale($this->locale);

        $recipe = $this->recipe();
        $user = $this->user();
        $line = $this->recipeLine($recipe, $recipeIngredientId);
        abort_unless($this->editingRecipeIngredientId === $line->getKey(), 404);

        $change = $user->recipeIngredientChanges()
            ->where('recipe_ingredient_id', $line->getKey())
            ->first();

        if ($change === null) {
            Gate::authorize('create', [UserRecipeIngredientChange::class, $recipe]);
        } else {
            Gate::authorize('update', $change);
        }

        $validated = $this->validate([
            'alternativeIngredientId' => [
                'required',
                'integer',
                Rule::exists('ingredients', 'id')->where('is_active', true),
                Rule::notIn([$line->ingredient_id]),
            ],
            'personalNote' => ['nullable', 'string', 'max:500'],
        ], [
            'alternativeIngredientId.not_in' => __('site.personal_substitution_same_ingredient'),
            'alternativeIngredientId.exists' => __('site.personal_substitution_invalid_ingredient'),
        ]);

        $user->recipeIngredientChanges()->updateOrCreate(
            [
                'recipe_id' => $recipe->getKey(),
                'recipe_ingredient_id' => $line->getKey(),
            ],
            [
                'alternative_ingredient_id' => $validated['alternativeIngredientId'],
                'note' => filled($validated['personalNote']) ? trim($validated['personalNote']) : null,
            ],
        );

        $this->cancelPersonalChange();
        session()->flash('recipe-substitution-status', __('site.personal_substitution_saved'));
    }

    public function deletePersonalChange(int $recipeIngredientId): void
    {
        app()->setLocale($this->locale);

        $recipe = $this->recipe();
        $user = $this->user();
        $line = $this->recipeLine($recipe, $recipeIngredientId);
        $change = $user->recipeIngredientChanges()
            ->where('recipe_id', $recipe->getKey())
            ->where('recipe_ingredient_id', $line->getKey())
            ->firstOrFail();

        Gate::authorize('delete', $change);
        $change->delete();

        session()->flash('recipe-substitution-status', __('site.personal_substitution_deleted'));
    }

    public function cancelPersonalChange(): void
    {
        $this->reset('editingRecipeIngredientId', 'alternativeIngredientId', 'ingredientSearch', 'personalNote');
    }

    public function render(RecipeContentPresenter $presenter): View
    {
        app()->setLocale($this->locale);

        $recipe = $this->recipe();
        $base = $presenter->baseServings($recipe);
        $current = $this->clamp($this->servings === '' ? $base : $this->servings, $base);
        $groups = $presenter->ingredientGroups($recipe, (string) $current);
        $items = collect($groups)->flatMap(fn (array $group) => $group['items']);
        $ingredientIds = $items->pluck('ingredient_id')->unique()->values();
        $recipeIngredientIds = $items->pluck('recipe_ingredient_id')->unique()->values();
        $substitutionsByIngredient = $ingredientIds->isEmpty()
            ? collect()
            : IngredientSubstitution::query()
                ->approved()
                ->applicableToRecipe((int) $recipe->getKey())
                ->whereIn('ingredient_id', $ingredientIds)
                ->with(['translations', 'replacementIngredient.translations'])
                ->get()
                ->groupBy('ingredient_id');

        $user = $this->currentUser();
        $canPersonalize = $user !== null
            && Gate::forUser($user)->allows('create', [UserRecipeIngredientChange::class, $recipe]);
        $personalChanges = $canPersonalize
            ? $user->recipeIngredientChanges()
                ->where('recipe_id', $recipe->getKey())
                ->whereIn('recipe_ingredient_id', $recipeIngredientIds)
                ->with('alternativeIngredient.translations')
                ->get()
                ->keyBy('recipe_ingredient_id')
            : collect();

        foreach ($groups as &$group) {
            foreach ($group['items'] as &$item) {
                $item['substitutions'] = $substitutionsByIngredient
                    ->get($item['ingredient_id'], collect())
                    ->map(fn (IngredientSubstitution $substitution): array => [
                        'name' => (string) ($substitution->replacementIngredient->translate($this->locale)?->name
                            ?? $substitution->replacementIngredient->code),
                        'explanation' => $substitution->translate($this->locale)?->explanation,
                    ])
                    ->values()
                    ->all();

                $change = $personalChanges->get($item['recipe_ingredient_id']);
                $item['personalChange'] = $change === null ? null : [
                    'name' => (string) ($change->alternativeIngredient->translate($this->locale)?->name
                        ?? $change->alternativeIngredient->code),
                    'note' => $change->note,
                ];
            }
            unset($item);
        }
        unset($group);

        return view('livewire.recipe-servings', [
            'groups' => $groups,
            'base' => $base,
            'current' => $current,
            'isScaled' => $current !== $base,
            'min' => self::MIN_SERVINGS,
            'max' => self::MAX_SERVINGS,
            'canPersonalize' => $canPersonalize,
            'ingredientOptions' => $canPersonalize && $this->editingRecipeIngredientId !== null
                ? $this->ingredientOptions()
                : [],
        ]);
    }

    private function recipe(): Recipe
    {
        $recipe = Recipe::query()->with('translations')->findOrFail($this->recipeId);
        abort_unless(app(RecipeAccessService::class)->canView($this->currentUser(), $recipe), 404);

        return $recipe;
    }

    private function recipeLine(Recipe $recipe, int $recipeIngredientId): RecipeIngredient
    {
        return $recipe->recipeIngredients()->whereKey($recipeIngredientId)->firstOrFail();
    }

    private function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function user(): User
    {
        $user = $this->currentUser();
        abort_unless($user !== null, 403);

        return $user;
    }

    /**
     * @return array<int, string>
     */
    private function ingredientOptions(): array
    {
        $search = trim($this->ingredientSearch);

        return Ingredient::query()
            ->where('is_active', true)
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $matches): Builder => $matches
                ->where('code', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhereHas('translations', fn (Builder $translations): Builder => $translations
                    ->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%'))))
            ->with('translations')
            ->orderBy('code')
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (Ingredient $ingredient): array => [
                $ingredient->getKey() => (string) ($ingredient->translate($this->locale)?->name ?? $ingredient->code),
            ])
            ->all();
    }

    private function current(): int
    {
        return $this->clamp($this->servings === '' ? $this->base() : $this->servings, $this->base());
    }

    private function base(): int
    {
        return app(RecipeContentPresenter::class)->baseServings(Recipe::query()->findOrFail($this->recipeId));
    }

    private function clamp(string|int $value, int $fallback): int
    {
        return is_numeric($value) ? max(self::MIN_SERVINGS, min(self::MAX_SERVINGS, (int) $value)) : $fallback;
    }
}
