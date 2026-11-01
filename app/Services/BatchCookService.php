<?php

namespace App\Services;

use App\Enums\BatchMovementType;
use App\Enums\HouseholdAbility;
use App\Enums\MealType;
use App\Exceptions\StaleBatchCookException;
use App\Exceptions\StaleMealPlanException;
use App\Models\BatchCook;
use App\Models\BatchCookMovement;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Tracks cooked portions as a ledger: prepared, reserved (earmarked for a scheduled leftover meal) and consumed.
 *
 * Every change locks the batch row, appends a movement and updates the balances in one transaction, so concurrent
 * requests are serialised and nothing can be reserved or eaten twice. Database checks back this up
 * (reserved + consumed can never exceed prepared). Ingredients are never double counted: a batch's ingredients come
 * from its single cooking meal, and leftover meals are excluded from shopping-list generation.
 */
class BatchCookService
{
    public function __construct(
        private MealPlanService $plans,
        private RecipeAccessService $recipeAccess,
        private HouseholdAccess $householdAccess,
        private AuditLogger $audit,
    ) {}

    /**
     * @return Collection<int, BatchCook>
     */
    public function list(User $actor, ?Household $household, int $limit = 50): Collection
    {
        $this->authorizeScope($actor, $household, HouseholdAbility::View);

        return $this->scopeQuery($actor, $household)->orderByDesc('cooked_on')->orderByDesc('id')->limit($limit)->get();
    }

    public function create(User $actor, ?Household $household, Recipe $recipe, int $portions, \DateTimeInterface|string $cookedOn, ?string $notes = null, ?MealPlanEntry $source = null): BatchCook
    {
        $this->authorizeScope($actor, $household, HouseholdAbility::EditPlans);

        if ($source !== null) {
            $this->assertUsableSource($actor, $household, $recipe, $source);
            $portions = $source->servings;
            $cookedOn = $source->planned_on;
        }

        $this->assertPortions($portions);

        if (! $this->plans->recipeAddable($actor, $household, $recipe)) {
            throw ValidationException::withMessages(['recipe' => __('planner.errors.recipe_not_shareable')]);
        }

        try {
            return DB::transaction(function () use ($actor, $household, $recipe, $portions, $cookedOn, $notes, $source): BatchCook {
                // Serialises creation so the per-scope limit holds under concurrent requests.
                $this->lockScope($actor, $household);

                if ($this->scopeQuery($actor, $household)->count() >= config('planner.max_batches_per_scope')) {
                    throw ValidationException::withMessages(['recipe' => __('planner.batches.errors.limit')]);
                }

                $batch = BatchCook::create([
                    'household_id' => $household?->getKey(),
                    'created_by' => $actor->getKey(),
                    'recipe_id' => $recipe->getKey(),
                    'source_entry_id' => $source?->getKey(),
                    'cooked_on' => CarbonImmutable::parse($cookedOn)->toDateString(),
                    'prepared_portions' => $portions,
                    'notes' => filled($notes) ? trim($notes) : null,
                ]);

                $batch->movements()->create(['type' => BatchMovementType::Prepared, 'portions' => $portions, 'user_id' => $actor->getKey(), 'idempotency_key' => 'prepared']);
                $this->audit->record('batch_cook_created', ['batch_cook_id' => $batch->getKey(), 'recipe_id' => $recipe->getKey(), 'portions' => $portions]);

                return $batch->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['recipe' => __('planner.batches.errors.already_batched')]);
        }
    }

    public function createFromEntry(User $actor, MealPlanEntry $entry): BatchCook
    {
        $plan = $entry->mealPlan;
        Gate::forUser($actor)->authorize('update', $plan);

        return $this->create($actor, $plan->isPersonal() ? null : $plan->household, $entry->recipe, $entry->servings, $entry->planned_on, null, $entry);
    }

    /**
     * Earmarks portions as a leftover meal in the plan for that date.
     */
    public function scheduleLeftover(User $actor, BatchCook $batch, \DateTimeInterface|string $date, MealType $meal, int $portions, ?string $notes = null): MealPlanEntry
    {
        Gate::forUser($actor)->authorize('update', $batch);

        $day = CarbonImmutable::parse($date)->startOfDay();
        $this->assertPortions($portions);

        if ($day->lt(CarbonImmutable::parse($batch->cooked_on)->startOfDay())) {
            throw ValidationException::withMessages(['date' => __('planner.batches.errors.before_cooked')]);
        }

        $household = $batch->isPersonal() ? null : $batch->household;

        if (! $this->plans->recipeAddable($actor, $household, $batch->recipe)) {
            throw ValidationException::withMessages(['recipe' => __('planner.errors.recipe_not_shareable')]);
        }

        $plan = $this->plans->ensurePlan($actor, $household, $day);

        return DB::transaction(function () use ($actor, $batch, $day, $meal, $portions, $notes, $plan): MealPlanEntry {
            $locked = $this->lock($batch);
            MealPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();

            if ($portions > $locked->availablePortions()) {
                throw ValidationException::withMessages(['portions' => __('planner.batches.errors.not_enough')]);
            }

            if ($plan->entries()->count() >= config('planner.max_entries_per_plan')) {
                throw ValidationException::withMessages(['recipe' => __('planner.errors.entry_limit')]);
            }

            $entry = $plan->entries()->create([
                'recipe_id' => $locked->recipe_id,
                'planned_on' => $day->toDateString(),
                'meal_type' => $meal,
                'servings' => $portions,
                'notes' => filled($notes) ? trim($notes) : null,
                'added_by' => $actor->getKey(),
                'batch_cook_id' => $locked->getKey(),
            ]);

            $this->record($locked, BatchMovementType::Reserved, $portions, $actor, entry: $entry, key: 'reserve-entry-'.$entry->getKey());
            $this->audit->record('batch_leftover_scheduled', ['batch_cook_id' => $locked->getKey(), 'entry_id' => $entry->getKey(), 'portions' => $portions]);

            return $entry;
        });
    }

    /**
     * Marks a scheduled leftover as eaten. Repeating it (double click, two devices) changes nothing.
     * Returns whether this call recorded the consumption.
     */
    public function consumeLeftover(User $actor, MealPlanEntry $entry): bool
    {
        abort_unless($entry->batch_cook_id !== null, 404);
        $batch = BatchCook::query()->findOrFail($entry->batch_cook_id);
        Gate::forUser($actor)->authorize('consume', $batch);

        return DB::transaction(function () use ($actor, $batch, $entry): bool {
            $locked = $this->lock($batch);
            $current = MealPlanEntry::query()->whereKey($entry->getKey())->lockForUpdate()->first();

            if ($current === null || $current->consumed_at !== null) {
                return false;
            }

            $this->record($locked, BatchMovementType::Consumed, $current->servings, $actor, fromReserved: true, entry: $current, key: 'consume-entry-'.$current->getKey());

            $current->forceFill(['consumed_at' => now(), 'version' => $current->version + 1])->save();
            $this->audit->record('batch_leftover_consumed', ['batch_cook_id' => $locked->getKey(), 'entry_id' => $current->getKey()]);

            return true;
        });
    }

    /**
     * Records portions eaten that were not scheduled. A repeated idempotency key returns the original result
     * without consuming again.
     */
    public function consume(User $actor, BatchCook $batch, int $portions, string $idempotencyKey): BatchCookMovement
    {
        Gate::forUser($actor)->authorize('consume', $batch);

        $idempotencyKey = trim($idempotencyKey);
        $this->assertPortions($portions);

        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 90) {
            throw ValidationException::withMessages(['portions' => __('planner.batches.errors.key')]);
        }

        $key = 'consume-'.$idempotencyKey;

        return DB::transaction(function () use ($actor, $batch, $portions, $key): BatchCookMovement {
            $locked = $this->lock($batch);
            $existing = $locked->movements()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($portions > $locked->availablePortions()) {
                throw ValidationException::withMessages(['portions' => __('planner.batches.errors.not_enough')]);
            }

            $movement = $this->record($locked, BatchMovementType::Consumed, $portions, $actor, key: $key);
            $this->audit->record('batch_portions_consumed', ['batch_cook_id' => $locked->getKey(), 'portions' => $portions]);

            return $movement;
        });
    }

    /**
     * Removes a leftover meal from the plan, returning its reservation to the batch unless it was already eaten.
     */
    public function removeLeftover(User $actor, MealPlanEntry $entry, int $expectedVersion): void
    {
        abort_unless($entry->batch_cook_id !== null, 404);
        Gate::forUser($actor)->authorize('update', $entry->mealPlan);
        $batch = BatchCook::query()->findOrFail($entry->batch_cook_id);

        DB::transaction(function () use ($actor, $batch, $entry, $expectedVersion): void {
            $locked = $this->lock($batch);
            $current = MealPlanEntry::query()->whereKey($entry->getKey())->lockForUpdate()->first();

            if ($current === null) {
                return;
            }

            if ($current->version !== $expectedVersion) {
                throw new StaleMealPlanException;
            }

            if ($current->consumed_at === null) {
                $this->record($locked, BatchMovementType::Released, $current->servings, $actor, entry: $current, key: 'release-entry-'.$current->getKey());
            }

            $current->delete();
            $this->audit->record('batch_leftover_removed', ['batch_cook_id' => $locked->getKey(), 'entry_id' => $entry->getKey()]);
        });
    }

    /**
     * A batch that has been eaten from is history and cannot be cancelled; otherwise its reserved leftover meals go with it.
     */
    public function cancel(User $actor, BatchCook $batch, int $expectedVersion): void
    {
        Gate::forUser($actor)->authorize('update', $batch);

        DB::transaction(function () use ($batch, $expectedVersion): void {
            $current = BatchCook::query()->whereKey($batch->getKey())->lockForUpdate()->first();

            if ($current === null) {
                return;
            }

            if ($current->version !== $expectedVersion) {
                throw new StaleBatchCookException;
            }

            if ($current->consumed_portions > 0) {
                throw ValidationException::withMessages(['portions' => __('planner.batches.errors.cannot_cancel')]);
            }

            $current->delete();
            $this->audit->record('batch_cook_cancelled', ['batch_cook_id' => $current->getKey()]);
        });
    }

    /**
     * Rebuilds the balances from the movements alone; they must always equal the stored ones.
     *
     * @return array{prepared: int, reserved: int, consumed: int}
     */
    public function reconcile(BatchCook $batch): array
    {
        $totals = ['prepared' => 0, 'reserved' => 0, 'consumed' => 0];

        foreach ($batch->movements()->get() as $movement) {
            match ($movement->type) {
                BatchMovementType::Prepared => $totals['prepared'] += $movement->portions,
                BatchMovementType::Reserved => $totals['reserved'] += $movement->portions,
                BatchMovementType::Released => $totals['reserved'] -= $movement->portions,
                BatchMovementType::Consumed => $this->applyConsumed($totals, $movement),
            };
        }

        return $totals;
    }

    /**
     * Batches for one viewer. Recipes the viewer cannot see carry no title or link.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function batchesView(User $viewer, ?Household $household, string $locale): Collection
    {
        $batches = $this->list($viewer, $household)->load(['recipe.translations', 'leftovers' => fn ($q) => $q->orderBy('planned_on')->orderBy('id')]);

        return $batches->map(function (BatchCook $batch) use ($viewer, $locale): array {
            $available = $batch->recipe !== null && $this->recipeAccess->canView($viewer, $batch->recipe);
            $translation = $available ? $batch->recipe->displayTranslation($locale) : null;

            return [
                'id' => $batch->getKey(),
                'version' => $batch->version,
                'available' => $available,
                'title' => $translation?->title,
                'cooked_on' => $batch->cooked_on,
                'prepared' => $batch->prepared_portions,
                'reserved' => $batch->reserved_portions,
                'consumed' => $batch->consumed_portions,
                'free' => $batch->availablePortions(),
                'notes' => $batch->notes,
                'leftovers' => $batch->leftovers->map(fn (MealPlanEntry $entry): array => [
                    'id' => $entry->getKey(),
                    'version' => $entry->version,
                    'date' => $entry->planned_on,
                    'meal' => $entry->meal_type,
                    'servings' => $entry->servings,
                    'consumed' => $entry->consumed_at !== null,
                ]),
            ];
        });
    }

    /**
     * @param  array{prepared: int, reserved: int, consumed: int}  $totals
     */
    private function applyConsumed(array &$totals, BatchCookMovement $movement): void
    {
        $totals['consumed'] += $movement->portions;

        if ($movement->from_reserved) {
            $totals['reserved'] -= $movement->portions;
        }
    }

    private function lock(BatchCook $batch): BatchCook
    {
        return BatchCook::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Appends a movement and applies it to the locked batch's balances. Must run inside the batch's lock.
     */
    private function record(BatchCook $batch, BatchMovementType $type, int $portions, User $actor, bool $fromReserved = false, ?MealPlanEntry $entry = null, ?string $key = null): BatchCookMovement
    {
        $balances = match ($type) {
            BatchMovementType::Prepared => ['prepared_portions' => $batch->prepared_portions + $portions],
            BatchMovementType::Reserved => ['reserved_portions' => $batch->reserved_portions + $portions],
            BatchMovementType::Released => ['reserved_portions' => $batch->reserved_portions - $portions],
            BatchMovementType::Consumed => [
                'consumed_portions' => $batch->consumed_portions + $portions,
                'reserved_portions' => $batch->reserved_portions - ($fromReserved ? $portions : 0),
            ],
        };

        if (($balances['reserved_portions'] ?? 0) < 0) {
            throw new StaleBatchCookException;
        }

        $batch->forceFill([...$balances, 'version' => $batch->version + 1])->save();

        return $batch->movements()->create([
            'type' => $type,
            'portions' => $portions,
            'from_reserved' => $fromReserved,
            'meal_plan_entry_id' => $entry?->getKey(),
            'user_id' => $actor->getKey(),
            'idempotency_key' => $key,
        ]);
    }

    private function assertUsableSource(User $actor, ?Household $household, Recipe $recipe, MealPlanEntry $source): void
    {
        $plan = $source->mealPlan;
        $sameScope = (int) $plan->household_id === (int) $household?->getKey();

        if (! $sameScope || (int) $source->recipe_id !== (int) $recipe->getKey() || $source->batch_cook_id !== null) {
            throw ValidationException::withMessages(['recipe' => __('planner.batches.errors.bad_source')]);
        }

        Gate::forUser($actor)->authorize('update', $plan);
    }

    private function assertPortions(int $portions): void
    {
        if ($portions < 1 || $portions > 200) {
            throw ValidationException::withMessages(['portions' => __('planner.batches.errors.portions')]);
        }
    }

    private function authorizeScope(User $actor, ?Household $household, HouseholdAbility $ability): void
    {
        $allowed = $household === null
            ? $this->householdAccess->canUsePlanner($actor)
            : $this->householdAccess->can($actor, $household, $ability);

        abort_unless($allowed, 403);
    }

    private function scopeQuery(User $actor, ?Household $household)
    {
        return $household === null
            ? BatchCook::query()->whereNull('household_id')->where('created_by', $actor->getKey())
            : BatchCook::query()->where('household_id', $household->getKey());
    }

    private function lockScope(User $actor, ?Household $household): void
    {
        $household === null
            ? User::query()->whereKey($actor->getKey())->lockForUpdate()->first()
            : Household::query()->whereKey($household->getKey())->lockForUpdate()->first();
    }
}
