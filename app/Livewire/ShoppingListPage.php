<?php

namespace App\Livewire;

use App\Enums\HouseholdAbility;
use App\Exceptions\StaleShoppingListException;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Unit;
use App\Services\HouseholdAccess;
use App\Services\MealPlanService;
use App\Services\ShoppingListService;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class ShoppingListPage extends Component
{
    #[Locked]
    public string $locale = 'en';

    #[Locked]
    public ?int $householdId = null;

    #[Url(as: 'week', except: '')]
    public string $week = '';

    public string $manualName = '';

    public string $manualQuantity = '';

    public ?int $manualUnit = null;

    public function mount(string $locale, ?int $householdId = null): void
    {
        $this->locale = $locale;
        $this->householdId = $householdId;
        $this->household();
    }

    public function generate(MealPlanService $plans, ShoppingListService $service): void
    {
        $this->resetErrorBag();
        $plan = $plans->findPlan(auth()->user(), $this->household(), $this->weekStart());

        if ($plan === null) {
            $this->addError('conflict', __('planner.shopping.errors.no_plan'));

            return;
        }

        $result = $service->generate(auth()->user(), $service->ensureList(auth()->user(), $plan));

        session()->flash('status', $result['excluded_entries'] > 0
            ? __('planner.shopping.flash.generated_with_exclusions', ['count' => $result['excluded_entries']])
            : __('planner.shopping.flash.generated'));
    }

    public function setComponentMode(MealPlanService $plans, ShoppingListService $service, int $entryId, string $path, string $mode): void
    {
        $plan = $plans->findPlan(auth()->user(), $this->household(), $this->weekStart());
        abort_unless($plan !== null, 404);
        $service->setComponentMode(auth()->user(), $plan->entries()->findOrFail($entryId), $path, $mode);
        session()->flash('status', __('nested_planner.regenerate_hint'));
    }

    public function setChecked(ShoppingListService $service, int $itemId, int $version, bool $checked): void
    {
        $item = $this->item($itemId);

        if ($item === null) {
            $this->conflict();

            return;
        }

        try {
            $service->setChecked(auth()->user(), $item, $version, $checked);
        } catch (StaleShoppingListException) {
            $this->conflict();
        }
    }

    public function addManual(MealPlanService $plans, ShoppingListService $service): void
    {
        $this->resetErrorBag();
        $this->validate([
            'manualName' => ['required', 'string', 'max:120'],
            'manualQuantity' => ['nullable', 'string', 'max:14'],
            'manualUnit' => ['nullable', 'integer'],
        ]);

        $plan = $plans->findPlan(auth()->user(), $this->household(), $this->weekStart());

        if ($plan === null) {
            $this->addError('conflict', __('planner.shopping.errors.no_plan'));

            return;
        }

        $service->addManualItem(auth()->user(), $service->ensureList(auth()->user(), $plan), $this->manualName, $this->manualQuantity, $this->manualUnit);

        $this->reset('manualName', 'manualQuantity', 'manualUnit');
    }

    public function removeManual(ShoppingListService $service, int $itemId, int $version): void
    {
        $item = $this->item($itemId);

        if ($item === null) {
            return;
        }

        try {
            $service->removeManualItem(auth()->user(), $item, $version);
        } catch (StaleShoppingListException) {
            $this->conflict();
        }
    }

    public function render(MealPlanService $plans, ShoppingListService $service, HouseholdAccess $access): View
    {
        app()->setLocale($this->locale);

        $user = auth()->user();
        $household = $this->household();
        $start = $this->weekStart();
        $plan = $plans->findPlan($user, $household, $start);
        $list = $plan ? $service->findList($user, $plan) : null;
        $canEdit = $household === null ? $access->canUsePlanner($user) : $access->can($user, $household, HouseholdAbility::EditShopping);

        return view('livewire.shopping-list-page', [
            'household' => $household,
            'start' => $start,
            'plan' => $plan,
            'list' => $list,
            'items' => $list ? $service->itemsView($user, $list, $this->locale) : collect(),
            'canEdit' => $canEdit,
            'componentChoices' => $plan ? $service->componentChoices($user, $plan) : collect(),
            'units' => $canEdit ? Unit::query()->with('translations')->orderBy('sort_order')->get() : collect(),
        ]);
    }

    private function weekStart(): CarbonImmutable
    {
        try {
            return MealPlan::weekStartFor($this->week !== '' ? $this->week : now());
        } catch (\Throwable) {
            return MealPlan::weekStartFor(now());
        }
    }

    // A missing household and one the user cannot see give the same response.
    private function household(): ?Household
    {
        if ($this->householdId === null) {
            abort_unless(app(HouseholdAccess::class)->canUsePlanner(auth()->user()), 404);

            return null;
        }

        $household = Household::query()->find($this->householdId);
        abort_unless($household !== null && auth()->user()?->can('view', $household), 404);

        return $household;
    }

    // Items are only ever resolved through the viewer's own list, so ids from other lists are unreachable.
    private function item(int $itemId): ?ShoppingListItem
    {
        $plan = app(MealPlanService::class)->findPlan(auth()->user(), $this->household(), $this->weekStart());
        $list = $plan ? ShoppingList::query()->where('meal_plan_id', $plan->getKey())->first() : null;

        return $list?->items()->with('shoppingList.mealPlan')->find($itemId);
    }

    private function conflict(): void
    {
        $this->addError('conflict', __('planner.shopping.errors.conflict'));
    }
}
