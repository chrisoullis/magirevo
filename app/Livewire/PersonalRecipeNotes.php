<?php

namespace App\Livewire;

use App\Models\Recipe;
use App\Models\User;
use App\Models\UserRecipeNote;
use App\Support\AppPermissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PersonalRecipeNotes extends Component
{
    #[Locked]
    public int $recipeId;

    #[Locked]
    public string $locale = 'en';

    public string $body = '';

    public ?string $lastCookedOn = null;

    public function mount(int $recipeId, string $locale): void
    {
        abort_unless(in_array($locale, config('translatable.locales'), true), 404);

        $this->recipeId = $recipeId;
        $this->locale = $locale;

        $recipe = $this->recipe();
        Gate::authorize('viewAny', [UserRecipeNote::class, $recipe]);

        $note = $this->note($recipe);

        if ($note !== null) {
            Gate::authorize('view', $note);
            $this->body = $note->body;
            $this->lastCookedOn = $note->last_cooked_on?->toDateString();
        }
    }

    public function save(): void
    {
        $recipe = $this->recipe();
        $note = $this->note($recipe);

        if ($note === null) {
            Gate::authorize('create', [UserRecipeNote::class, $recipe]);
            $note = new UserRecipeNote(['recipe_id' => $recipe->getKey()]);
        } else {
            Gate::authorize('update', $note);
        }

        $validated = $this->validate([
            'body' => ['required', 'string', 'max:20000'],
            'lastCookedOn' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $note->fill([
            'body' => trim($validated['body']),
            'last_cooked_on' => $validated['lastCookedOn'],
        ]);

        $this->user()->recipeNotes()->save($note);
        session()->flash('recipe-note-status', __('site.notes_saved'));
    }

    public function delete(): void
    {
        $note = $this->note($this->recipe());

        if ($note === null) {
            return;
        }

        Gate::authorize('delete', $note);
        $note->delete();
        $this->reset('body', 'lastCookedOn');
        session()->flash('recipe-note-status', __('site.notes_deleted'));
    }

    public function render(): View
    {
        app()->setLocale($this->locale);

        $recipe = $this->recipe();
        Gate::authorize('viewAny', [UserRecipeNote::class, $recipe]);

        $note = $this->note($recipe);

        if ($note !== null) {
            Gate::authorize('view', $note);
        }

        return view('livewire.personal-recipe-notes', [
            'hasNote' => $note !== null,
        ]);
    }

    private function recipe(): Recipe
    {
        $recipe = Recipe::query()->findOrFail($this->recipeId);
        abort_unless($this->user()->can('view', $recipe), 404);

        return $recipe;
    }

    private function note(Recipe $recipe): ?UserRecipeNote
    {
        return $this->user()->recipeNotes()
            ->where('recipe_id', $recipe->getKey())
            ->first();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->can(AppPermissions::RECIPES_PERSONALIZE), 404);

        return $user;
    }
}
