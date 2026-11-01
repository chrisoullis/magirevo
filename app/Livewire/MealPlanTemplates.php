<?php

namespace App\Livewire;

use App\Enums\HouseholdAbility;
use App\Exceptions\StaleTemplateException;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\MealPlanTemplate;
use App\Services\HouseholdAccess;
use App\Services\MealPlanService;
use App\Services\MealPlanTemplateService;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class MealPlanTemplates extends Component
{
    #[Locked]
    public string $locale = 'en';

    #[Locked]
    public ?int $householdId = null;

    #[Url(as: 'week', except: '')]
    public string $week = '';

    public string $templateName = '';

    public ?int $renamingId = null;

    public int $renamingVersion = 0;

    public string $renameValue = '';

    public ?int $expandedId = null;

    public function mount(string $locale, ?int $householdId = null): void
    {
        $this->locale = $locale;
        $this->householdId = $householdId;
        $this->household();
    }

    public function saveCurrentWeek(MealPlanService $plans, MealPlanTemplateService $service): void
    {
        $this->resetErrorBag();
        $this->validate(['templateName' => ['required', 'string', 'max:100']]);

        $plan = $plans->findPlan(auth()->user(), $this->household(), $this->weekStart());

        if ($plan === null) {
            $this->addError('templateName', __('planner.templates.errors.nothing_to_save'));

            return;
        }

        $result = $service->saveFromPlan(auth()->user(), $plan, $this->templateName);
        $this->reset('templateName');

        session()->flash('status', $result['skipped'] > 0
            ? __('planner.templates.flash.saved_with_skipped', ['count' => $result['skipped']])
            : __('planner.templates.flash.saved'));
    }

    public function apply(MealPlanService $plans, MealPlanTemplateService $service, int $templateId): void
    {
        $this->resetErrorBag();
        $template = $this->template($templateId);

        if ($template === null) {
            $this->conflict();

            return;
        }

        $plan = $plans->ensurePlan(auth()->user(), $this->household(), $this->weekStart());
        $result = $service->apply(auth()->user(), $template, $plan);

        session()->flash('status', __('planner.templates.flash.applied', [
            'added' => $result['added'],
            'skipped' => $result['duplicates'] + $result['unavailable'] + $result['over_limit'],
        ]));
    }

    public function startRename(int $templateId): void
    {
        $template = $this->template($templateId);

        if ($template === null) {
            $this->conflict();

            return;
        }

        $this->renamingId = $template->getKey();
        $this->renamingVersion = $template->version;
        $this->renameValue = $template->name;
    }

    public function cancelRename(): void
    {
        $this->reset('renamingId', 'renamingVersion', 'renameValue');
    }

    public function saveRename(MealPlanTemplateService $service): void
    {
        $this->resetErrorBag();
        $this->validate(['renameValue' => ['required', 'string', 'max:100']]);

        $template = $this->renamingId ? $this->template($this->renamingId) : null;

        if ($template === null) {
            $this->conflict();

            return;
        }

        try {
            $service->rename(auth()->user(), $template, $this->renamingVersion, $this->renameValue);
        } catch (StaleTemplateException) {
            $this->conflict();

            return;
        }

        $this->cancelRename();
    }

    public function remove(MealPlanTemplateService $service, int $templateId, int $version): void
    {
        $template = $this->template($templateId);

        if ($template !== null) {
            try {
                $service->delete(auth()->user(), $template, $version);
            } catch (StaleTemplateException) {
                $this->conflict();

                return;
            }
        }

        $this->expandedId = null;
        session()->flash('status', __('planner.templates.flash.deleted'));
    }

    public function toggle(int $templateId): void
    {
        $this->expandedId = $this->expandedId === $templateId ? null : $templateId;
    }

    public function render(MealPlanTemplateService $service, HouseholdAccess $access): View
    {
        app()->setLocale($this->locale);

        $user = auth()->user();
        $household = $this->household();
        $start = $this->weekStart();
        $templates = $service->list($user, $household);
        $expanded = $this->expandedId ? $templates->firstWhere('id', $this->expandedId) : null;

        return view('livewire.meal-plan-templates', [
            'household' => $household,
            'start' => $start,
            'templates' => $templates,
            'canEdit' => $household === null ? $access->canUsePlanner($user) : $access->can($user, $household, HouseholdAbility::EditPlans),
            'expandedEntries' => $expanded ? $service->entriesView($user, $expanded, $this->locale)->groupBy('day') : collect(),
            'days' => collect(range(1, 7))->mapWithKeys(fn (int $day): array => [$day => CarbonImmutable::parse('monday this week')->addDays($day - 1)->locale($this->locale)->translatedFormat('l')]),
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

    // Templates are only resolved inside the viewer's own scope, so ids from elsewhere are unreachable.
    private function template(int $templateId): ?MealPlanTemplate
    {
        return app(MealPlanTemplateService::class)->list(auth()->user(), $this->household())->firstWhere('id', $templateId);
    }

    private function conflict(): void
    {
        $this->cancelRename();
        $this->addError('conflict', __('planner.templates.errors.conflict'));
    }
}
