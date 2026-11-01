<?php

namespace App\Livewire;

use App\Models\Recipe;
use App\Services\RecipeAccessService;
use App\Services\RecipeContentPresenter;
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

    public function render(RecipeContentPresenter $presenter): View
    {
        app()->setLocale($this->locale);

        $recipe = Recipe::query()->with('translations')->findOrFail($this->recipeId);

        // Same rule as the page itself, so a revoked recipe stops rendering immediately.
        abort_unless(app(RecipeAccessService::class)->canView(auth()->user(), $recipe), 404);
        $base = $presenter->baseServings($recipe);
        $current = $this->clamp($this->servings === '' ? $base : $this->servings, $base);

        return view('livewire.recipe-servings', [
            'groups' => $presenter->ingredientGroups($recipe, (string) $current),
            'base' => $base,
            'current' => $current,
            'isScaled' => $current !== $base,
            'min' => self::MIN_SERVINGS,
            'max' => self::MAX_SERVINGS,
        ]);
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
