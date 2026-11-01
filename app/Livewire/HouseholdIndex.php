<?php

namespace App\Livewire;

use App\Models\Household;
use App\Services\HouseholdService;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class HouseholdIndex extends Component
{
    #[Locked]
    public string $locale = 'en';

    public string $name = '';

    public function mount(string $locale): void
    {
        $this->locale = $locale;
        abort_unless(auth()->user()?->can('viewAny', Household::class), 404);
    }

    public function create(HouseholdService $service): void
    {
        $this->resetErrorBag();
        $this->validate(['name' => ['required', 'string', 'max:100']]);

        $household = $service->create(auth()->user(), $this->name);

        $this->redirectRoute('households.show', ['locale' => $this->locale, 'household' => $household->getKey()], navigate: false);
    }

    public function render(): View
    {
        app()->setLocale($this->locale);

        return view('livewire.household-index', [
            'memberships' => auth()->user()->households()->withCount('members')->orderBy('households.name')->get(),
        ]);
    }
}
