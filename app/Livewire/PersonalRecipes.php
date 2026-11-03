<?php

namespace App\Livewire;

use App\Models\Recipe;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class PersonalRecipes extends Component
{
    use WithPagination;

    #[Locked]
    public string $locale;

    public string $filter = 'all';

    public function mount(string $locale): void
    {
        abort_unless(in_array($locale, ['en', 'el'], true), 404);
        $this->locale = $locale;
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        abort_unless(config('recipe-variations.enabled') && auth()->user()?->hasVerifiedEmail(), 403);
        app()->setLocale($this->locale);
        abort_unless(in_array($this->filter, ['all', 'original', 'variation'], true), 422);
        $query = Recipe::query()->where('author_id', auth()->id())->with(['translations', 'media.translations', 'categories.translations']);
        if ($this->filter === 'original') {
            $query->whereNull('derived_from_recipe_id');
        } elseif ($this->filter === 'variation') {
            $query->whereNotNull('derived_from_recipe_id');
        }

        return view('livewire.personal-recipes', ['recipes' => $query->latest('id')->paginate(12)]);
    }
}
