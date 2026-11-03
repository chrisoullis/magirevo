<?php

namespace App\Livewire;

use App\Models\Recipe;
use App\Services\Recipes\RecipeVariationPublishingService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class VariationPublicationStatus extends Component
{
    #[Locked]
    public int $recipeId;

    #[Locked]
    public string $locale;

    public function mount(int $recipeId, string $locale): void
    {
        abort_unless(in_array($locale, ['en', 'el'], true), 404);
        $this->recipeId = $recipeId;
        $this->locale = $locale;
    }

    private function recipe(): Recipe
    {
        $recipe = Recipe::query()->findOrFail($this->recipeId);
        abort_unless(auth()->user()?->hasVerifiedEmail() && auth()->id() === $recipe->author_id && $recipe->derived_from_recipe_id !== null, 403);
        Gate::authorize('view', $recipe);

        return $recipe;
    }

    public function withdraw(): void
    {
        $recipe = app(RecipeVariationPublishingService::class)->withdraw(auth()->user(), $this->recipe());
        $this->redirectRoute('recipes.variations.edit', ['locale' => $this->locale, 'recipe' => $recipe->id]);
    }

    public function render()
    {
        app()->setLocale($this->locale);

        return view('livewire.variation-publication-status', ['recipe' => $this->recipe()]);
    }
}
