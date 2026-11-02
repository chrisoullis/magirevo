<?php

namespace App\Livewire;

use App\Enums\HouseholdAbility;
use App\Enums\MealType;
use App\Exceptions\StaleMealPlanException;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\Tag;
use App\Services\BatchCookService;
use App\Services\DietaryClaimVisibility;
use App\Services\HouseholdAccess;
use App\Services\MealPlanService;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class MealPlanner extends Component
{
    #[Locked]
    public string $locale = 'en';

    #[Locked]
    public ?int $householdId = null;

    #[Url(as: 'week', except: '')]
    public string $week = '';

    public string $search = '';

    public string $dietaryClaim = '';

    public ?int $recipeId = null;

    public string $date = '';

    public string $meal = 'dinner';

    public int $servings = 4;

    public string $notes = '';

    public ?int $editingId = null;

    public int $editingVersion = 0;

    public int $editServings = 4;

    public string $editNotes = '';

    public function mount(string $locale, ?int $householdId = null): void
    {
        $this->locale = $locale;
        $this->householdId = $householdId;
        $this->household();
        $this->date = $this->weekStart()->toDateString();
    }

    public function previousWeek(): void
    {
        $this->goToWeek($this->weekStart()->subWeek());
    }

    public function nextWeek(): void
    {
        $this->goToWeek($this->weekStart()->addWeek());
    }

    public function currentWeek(): void
    {
        $this->goToWeek(MealPlan::weekStartFor(now()));
    }

    public function selectRecipe(int $recipeId): void
    {
        $this->recipeId = $recipeId;
        $this->search = '';
    }

    public function clearRecipe(): void
    {
        $this->recipeId = null;
    }

    public function addEntry(MealPlanService $service): void
    {
        $this->resetErrorBag();
        $this->validate([
            'recipeId' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'meal' => ['required', 'in:'.implode(',', MealType::values())],
            'servings' => ['required', 'integer', 'between:1,99'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $recipe = Recipe::query()->find($this->recipeId);

        // A missing recipe and a recipe the user cannot see are indistinguishable.
        if ($recipe === null) {
            $this->addError('recipeId', __('planner.errors.recipe_not_shareable'));

            return;
        }

        $plan = $service->ensurePlan(auth()->user(), $this->household(), $this->weekStart());
        $service->addEntry(auth()->user(), $plan, $recipe, $this->date, MealType::from($this->meal), $this->servings, $this->notes);

        $this->reset('recipeId', 'search', 'notes');
        session()->flash('status', __('planner.flash.added'));
    }

    public function startEdit(int $entryId): void
    {
        $entry = $this->entry($entryId);

        if ($entry === null) {
            $this->conflict();

            return;
        }

        $this->editingId = $entry->getKey();
        $this->editingVersion = $entry->version;
        $this->editServings = $entry->servings;
        $this->editNotes = (string) $entry->notes;
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editingVersion', 'editNotes');
    }

    public function saveEdit(MealPlanService $service): void
    {
        $this->resetErrorBag();
        $this->validate(['editServings' => ['required', 'integer', 'between:1,99'], 'editNotes' => ['nullable', 'string', 'max:255']]);

        $entry = $this->editingId ? $this->entry($this->editingId) : null;

        if ($entry === null) {
            $this->conflict();

            return;
        }

        try {
            $service->updateEntry(auth()->user(), $entry, $this->editingVersion, ['servings' => $this->editServings, 'notes' => $this->editNotes]);
        } catch (StaleMealPlanException) {
            $this->conflict();

            return;
        }

        $this->cancelEdit();
        session()->flash('status', __('planner.flash.updated'));
    }

    public function startBatch(BatchCookService $batches, int $entryId): void
    {
        $this->resetErrorBag();
        $entry = $this->entry($entryId);

        if ($entry !== null) {
            $batches->createFromEntry(auth()->user(), $entry);
            session()->flash('status', __('planner.batches.flash.created'));
        }
    }

    public function consumeLeftover(BatchCookService $batches, int $entryId): void
    {
        $entry = $this->entry($entryId);

        if ($entry !== null) {
            $batches->consumeLeftover(auth()->user(), $entry);
            session()->flash('status', __('planner.batches.flash.marked'));
        }
    }

    public function acknowledgeRecipeUpdate(MealPlanService $service, int $entryId, int $version): void
    {
        $entry = $this->entry($entryId);

        if ($entry === null) {
            $this->conflict();

            return;
        }

        try {
            $service->acknowledgeRecipeUpdate(auth()->user(), $entry, $version);
        } catch (StaleMealPlanException) {
            $this->conflict();

            return;
        }

        session()->flash('status', __('planner.flash.recipe_update_acknowledged'));
    }

    public function removeEntry(MealPlanService $service, int $entryId, int $version): void
    {
        $entry = $this->entry($entryId);

        if ($entry !== null) {
            try {
                $service->removeEntry(auth()->user(), $entry, $version);
            } catch (StaleMealPlanException) {
                $this->conflict();

                return;
            }
        }

        session()->flash('status', __('planner.flash.removed'));
    }

    public function render(MealPlanService $service, HouseholdAccess $access, DietaryClaimVisibility $claims): View
    {
        app()->setLocale($this->locale);

        $user = auth()->user();
        $household = $this->household();
        $start = $this->weekStart();
        $plan = $service->findPlan($user, $household, $start);
        $canEdit = $household === null ? $access->canUsePlanner($user) : $access->can($user, $household, HouseholdAbility::EditPlans);
        $selected = $this->recipeId ? Recipe::query()->with('translations')->find($this->recipeId) : null;
        $publicClaimIndex = $claims->publicClaimIndex();
        $dietaryClaims = $publicClaimIndex === []
            ? collect()
            : Tag::query()
                ->whereIn('code', array_keys($publicClaimIndex))
                ->where('is_active', true)
                ->where('is_public_enabled', true)
                ->with('translations')
                ->orderBy('sort_order')
                ->get();

        return view('livewire.meal-planner', [
            'household' => $household,
            'start' => $start,
            'days' => collect(range(0, 6))->map(fn (int $offset): CarbonImmutable => $start->addDays($offset)),
            'view' => $plan ? $service->weekView($user, $plan, $this->locale) : collect(),
            'canEdit' => $canEdit,
            'results' => $canEdit && $this->search !== ''
                ? $service->searchRecipes($user, $household, $this->search, $this->locale, 8, $this->dietaryClaim)
                : collect(),
            'dietaryClaims' => $dietaryClaims,
            'selectedTitle' => $selected && $service->recipeAddable($user, $household, $selected)
                ? $selected->displayTranslation($this->locale)?->title
                : null,
            'meals' => MealType::cases(),
            'isCurrentWeek' => $start->equalTo(MealPlan::weekStartFor(now())),
        ]);
    }

    private function goToWeek(CarbonImmutable $start): void
    {
        $this->week = $start->toDateString();
        $this->date = $start->toDateString();
        $this->cancelEdit();
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

    private function entry(int $entryId): ?MealPlanEntry
    {
        $plan = app(MealPlanService::class)->findPlan(auth()->user(), $this->household(), $this->weekStart());

        return $plan?->entries()->with('mealPlan')->find($entryId);
    }

    private function conflict(): void
    {
        $this->cancelEdit();
        $this->addError('conflict', __('planner.errors.conflict'));
    }
}
