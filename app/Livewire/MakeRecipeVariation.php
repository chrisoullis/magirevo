<?php

namespace App\Livewire;

use App\Models\Recipe;
use App\Services\Recipes\RecipeForkService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class MakeRecipeVariation extends Component
{
    #[Locked]
    public int $recipeId;

    #[Locked]
    public string $locale;

    #[Locked]
    public string $idempotencyKey;

    public bool $confirmed = false;

    public function mount(int $recipeId, string $locale): void
    {
        abort_unless(in_array($locale, ['en', 'el'], true), 404);
        $this->recipeId = $recipeId;
        $this->locale = $locale;
        $this->idempotencyKey = (string) Str::uuid();
    }

    public function create(): void
    {
        app()->setLocale($this->locale);
        abort_unless(auth()->check(), 403);
        $this->validate(['confirmed' => ['accepted']]);
        $recipe = app(RecipeForkService::class)->fork(auth()->user(), Recipe::query()->findOrFail($this->recipeId), ['idempotency_key' => $this->idempotencyKey]);
        $this->redirectRoute('recipes.variations.edit', ['locale' => $this->locale, 'recipe' => $recipe->id]);
    }

    public function render()
    {
        app()->setLocale($this->locale);
        $recipe = Recipe::query()->findOrFail($this->recipeId);

        return view('livewire.make-recipe-variation', ['available' => auth()->check() && Gate::allows('fork', $recipe)]);
    }
}
