<?php

namespace App\Livewire;

use App\Models\MealPlan;
use App\Services\HouseholdAccess;
use App\Services\MealPlanService;
use App\Services\MyKitchenData;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class KitchenPreview extends Component
{
    #[Locked]
    public string $locale;

    public function mount(string $locale): void
    {
        $this->locale = $locale;
    }

    public function placeholder(): View
    {
        return view('livewire.kitchen-preview-placeholder');
    }

    public function render(HouseholdAccess $access, MealPlanService $plans): View
    {
        $user = auth()->user();
        abort_unless($user?->hasVerifiedEmail(), 403);
        abort_unless(in_array($this->locale, config('translatable.locales'), true), 404);
        app()->setLocale($this->locale);
        $counts = app(MyKitchenData::class)->counts($user);
        $start = MealPlan::weekStartFor(now());
        $days = collect(range(0, 6))->map(fn ($offset) => $start->addDays($offset));
        $planned = collect();
        $canPlan = $access->canUsePlanner($user);
        if ($canPlan) {
            $plan = $plans->findPlan($user, null, $start);
            $planned = $plan?->entries()->whereBetween('planned_on', [$start->toDateString(), $start->addDays(6)->toDateString()])
                ->selectRaw('planned_on, COUNT(*) AS total')->groupBy('planned_on')->orderBy('planned_on')->limit(7)->pluck('total', 'planned_on') ?? collect();
        }

        return view('livewire.kitchen-preview', compact('counts', 'days', 'planned', 'start', 'canPlan'));
    }
}
