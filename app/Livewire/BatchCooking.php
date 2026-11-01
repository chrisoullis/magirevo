<?php

namespace App\Livewire;

use App\Enums\HouseholdAbility;
use App\Enums\MealType;
use App\Exceptions\StaleBatchCookException;
use App\Exceptions\StaleMealPlanException;
use App\Models\BatchCook;
use App\Models\Household;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Services\BatchCookService;
use App\Services\HouseholdAccess;
use App\Services\MealPlanService;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class BatchCooking extends Component
{
    #[Locked]
    public string $locale = 'en';

    #[Locked]
    public ?int $householdId = null;

    public string $search = '';

    public ?int $recipeId = null;

    public int $portions = 4;

    public string $cookedOn = '';

    public string $notes = '';

    public ?int $activeBatchId = null;

    public string $leftoverDate = '';

    public string $leftoverMeal = 'lunch';

    public int $leftoverPortions = 1;

    public int $eatPortions = 1;

    public string $eatKey = '';

    public function mount(string $locale, ?int $householdId = null): void
    {
        $this->locale = $locale;
        $this->householdId = $householdId;
        $this->cookedOn = now()->toDateString();
        $this->leftoverDate = now()->toDateString();
        $this->eatKey = (string) Str::uuid();
        $this->household();
    }

    public function selectRecipe(int $recipeId): void
    {
        $this->recipeId = $recipeId;
    }

    public function create(BatchCookService $service): void
    {
        $this->resetErrorBag();
        $this->validate(['cookedOn' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:255']]);

        $recipe = $this->recipeId ? Recipe::query()->find($this->recipeId) : null;

        if ($recipe === null) {
            $this->addError('recipe', __('planner.batches.errors.bad_source'));

            return;
        }

        $service->create(auth()->user(), $this->household(), $recipe, $this->portions, $this->cookedOn, $this->notes);
        $this->reset('recipeId', 'search', 'notes');
        session()->flash('status', __('planner.batches.flash.created'));
    }

    public function open(int $batchId): void
    {
        $this->resetErrorBag();
        $this->activeBatchId = $this->batch($batchId)?->getKey();
    }

    public function scheduleLeftover(BatchCookService $service): void
    {
        $this->resetErrorBag();
        $batch = $this->batch((int) $this->activeBatchId);

        if ($batch === null) {
            return;
        }

        $this->validate(['leftoverDate' => ['required', 'date'], 'leftoverMeal' => ['required', 'in:'.implode(',', MealType::values())]]);
        $service->scheduleLeftover(auth()->user(), $batch, $this->leftoverDate, MealType::from($this->leftoverMeal), $this->leftoverPortions);
        session()->flash('status', __('planner.batches.flash.scheduled'));
    }

    public function consume(BatchCookService $service): void
    {
        $this->resetErrorBag();
        $batch = $this->batch((int) $this->activeBatchId);

        if ($batch === null) {
            return;
        }

        $service->consume(auth()->user(), $batch, $this->eatPortions, $this->eatKey);
        // A fresh key per submission: a double click replays the same key and is ignored, a new intent gets a new one.
        $this->eatKey = (string) Str::uuid();
        session()->flash('status', __('planner.batches.flash.consumed'));
    }

    public function markEaten(BatchCookService $service, int $entryId): void
    {
        $entry = $this->leftover($entryId);

        if ($entry !== null) {
            $service->consumeLeftover(auth()->user(), $entry);
            session()->flash('status', __('planner.batches.flash.marked'));
        }
    }

    public function removeLeftover(BatchCookService $service, int $entryId, int $version): void
    {
        $entry = $this->leftover($entryId);

        if ($entry === null) {
            return;
        }

        try {
            $service->removeLeftover(auth()->user(), $entry, $version);
            session()->flash('status', __('planner.batches.flash.removed'));
        } catch (StaleMealPlanException) {
            $this->addError('conflict', __('planner.batches.errors.conflict'));
        }
    }

    public function cancel(BatchCookService $service, int $batchId, int $version): void
    {
        $batch = $this->batch($batchId);

        if ($batch === null) {
            return;
        }

        try {
            $service->cancel(auth()->user(), $batch, $version);
            $this->activeBatchId = null;
            session()->flash('status', __('planner.batches.flash.cancelled'));
        } catch (StaleBatchCookException) {
            $this->addError('conflict', __('planner.batches.errors.conflict'));
        }
    }

    public function render(BatchCookService $service, MealPlanService $plans, HouseholdAccess $access): View
    {
        app()->setLocale($this->locale);

        $household = $this->household();
        $user = auth()->user();
        $canEdit = $household === null || $access->can($user, $household, HouseholdAbility::EditPlans);

        return view('livewire.batch-cooking', [
            'household' => $household,
            'locale' => $this->locale,
            'canEdit' => $canEdit,
            'batches' => $service->batchesView($user, $household, $this->locale),
            'results' => $canEdit && $this->recipeId === null ? $plans->searchRecipes($user, $household, $this->search, $this->locale) : collect(),
            'meals' => MealType::cases(),
        ]);
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

    // Ids are only resolved inside the viewer's own scope, so ones from elsewhere are unreachable.
    private function batch(int $batchId): ?BatchCook
    {
        $household = $this->household();
        $query = $household === null
            ? BatchCook::query()->whereNull('household_id')->where('created_by', auth()->id())
            : BatchCook::query()->where('household_id', $household->getKey());

        return $query->find($batchId);
    }

    private function leftover(int $entryId): ?MealPlanEntry
    {
        $entry = MealPlanEntry::query()->whereNotNull('batch_cook_id')->with('mealPlan')->find($entryId);

        return $entry !== null && $this->batch($entry->batch_cook_id) !== null ? $entry : null;
    }
}
