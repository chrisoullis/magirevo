<?php

namespace App\Livewire;

use App\Models\Recipe;
use App\Models\RecipeShare;
use App\Services\RecipeShareService;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ManageRecipeSharing extends Component
{
    #[Locked]
    public string $locale = 'en';

    #[Locked]
    public int $recipeId = 0;

    public string $email = '';

    public function mount(string $locale, int $recipeId): void
    {
        $this->locale = $locale;
        $this->recipeId = $recipeId;

        // Same response as a missing recipe so ids cannot be probed.
        $this->ownedRecipe();
    }

    public function enableSharing(RecipeShareService $service): void
    {
        $service->enableSharing(auth()->user(), $this->ownedRecipe());

        session()->flash('status', __('sharing.enabled_flash'));
    }

    public function stopSharing(RecipeShareService $service): void
    {
        $service->stopSharing(auth()->user(), $this->ownedRecipe());

        session()->flash('status', __('sharing.stopped_flash'));
    }

    public function share(RecipeShareService $service): void
    {
        $this->resetErrorBag();

        $service->share(auth()->user(), $this->ownedRecipe(), $this->email, $this->locale);

        $this->email = '';
        session()->flash('status', __('sharing.shared_flash'));
    }

    public function revoke(RecipeShareService $service, int $shareId): void
    {
        $share = RecipeShare::query()->where('recipe_id', $this->recipeId)->findOrFail($shareId);

        $service->revoke(auth()->user(), $share);

        session()->flash('status', __('sharing.revoked_flash'));
    }

    public function render(): View
    {
        app()->setLocale($this->locale);

        $recipe = $this->ownedRecipe();

        return view('livewire.manage-recipe-sharing', [
            'recipe' => $recipe,
            'title' => $recipe->displayTranslation($this->locale)?->title ?? '',
            'recipeUrl' => ($translation = $recipe->displayTranslation($this->locale))
                ? route('recipes.show', ['locale' => $translation->locale, 'slug' => $translation->slug], false)
                : null,
            'isShared' => $recipe->visibility->value === 'shared',
            'shares' => $recipe->shares()->active()->with('user')->orderBy('created_at')->get(),
        ]);
    }

    private function recipe(): ?Recipe
    {
        return Recipe::query()->with('translations')->find($this->recipeId);
    }

    private function ownedRecipe(): Recipe
    {
        $recipe = $this->recipe();

        abort_unless($recipe !== null && auth()->user()?->can('share', $recipe), 404);

        return $recipe;
    }
}
