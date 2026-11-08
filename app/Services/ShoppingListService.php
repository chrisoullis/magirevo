<?php

namespace App\Services;

use App\Enums\HouseholdAbility;
use App\Exceptions\StaleShoppingListException;
use App\Models\Ingredient;
use App\Models\MealPlan;
use App\Models\MealPlanEntry;
use App\Models\MealPlanEntryComponentMode;
use App\Models\RecipeRevision;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Unit;
use App\Models\User;
use App\Support\ConsolidatedQuantity;
use App\Support\QuantityLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Builds a plan's shopping list and keeps it correct while people edit it.
 *
 * Regeneration is idempotent: generated rows are matched by a stable key (ingredient, measurement family, optional),
 * so running it twice changes nothing. It never touches manual items and never un-checks a bought item. If a checked
 * item now needs more than was bought, the item stays checked and the additional amount is recorded separately.
 * Shopping lists buy the upper end of a range, so nobody runs short.
 */
class ShoppingListService
{
    /** Persist a choice only; regeneration remains an explicit action. */
    public function setComponentMode(User $actor, MealPlanEntry $entry, string $path, string $mode): void
    {
        Gate::forUser($actor)->authorize('update', $this->ensureList($actor, $entry->mealPlan));
        abort_unless(in_array($mode, ['scratch', 'prepared'], true) && $entry->batch_cook_id === null, 422);
        $components = $this->componentChoices($actor, $entry->mealPlan)->firstWhere('entry_id', $entry->id);
        $component = collect($components['components'] ?? [])->firstWhere('path', $path);
        abort_unless($component !== null && ($mode !== 'prepared' || $component['can_use_prepared']), 422);
        MealPlanEntryComponentMode::updateOrCreate(['meal_plan_entry_id' => $entry->id, 'path' => $path], ['mode' => $mode]);
    }

    public function componentChoices(User $actor, MealPlan $plan): Collection
    {
        Gate::forUser($actor)->authorize('view', $plan);
        $audience = $plan->isPersonal() ? collect([$plan->createdBy ?? User::find($plan->created_by)]) : $plan->household->members()->with('user')->get()->pluck('user');
        $resolver = app(RecipeComponentResolver::class);
        $entries = $plan->entries()->whereNull('batch_cook_id')->with('recipe')->get();
        $revisions = RecipeRevision::whereIn('id', $entries->pluck('planned_recipe_revision_id')->filter())->with('recipe')->get()->keyBy('id');
        $modes = MealPlanEntryComponentMode::whereIn('meal_plan_entry_id', $entries->modelKeys())->get()->groupBy('meal_plan_entry_id');

        return $entries->map(function ($entry) use ($actor, $audience, $resolver, $revisions, $modes) {
            $subject = $entry->planned_recipe_revision_id !== null ? $revisions->get($entry->planned_recipe_revision_id) : $entry->recipe;
            if ($subject === null || $audience->isEmpty() || ! $audience->every(fn ($user) => $user && $resolver->canResolve($subject, $user)) || ! $resolver->canResolve($subject, $actor)) {
                return null;
            }
            $choices = $modes->get($entry->id, collect())->pluck('mode', 'path')->all();
            $result = $resolver->resolve($subject, $actor, (string) $entry->servings, $choices);

            return ['entry_id' => $entry->id, 'servings' => $entry->servings, 'components' => $result['components']];
        })->filter(fn ($item) => $item !== null && $item['components'] !== [])->values();
    }

    public function __construct(
        private PlanIngredientCollector $collector,
        private IngredientConsolidator $consolidator,
        private UnitConverter $converter,
        private HouseholdAccess $householdAccess,
        private AuditLogger $audit,
    ) {}

    /**
     * Reading never creates anything.
     */
    public function findList(User $actor, MealPlan $plan): ?ShoppingList
    {
        Gate::forUser($actor)->authorize('view', $plan);

        return ShoppingList::query()->where('meal_plan_id', $plan->getKey())->first();
    }

    public function ensureList(User $actor, MealPlan $plan): ShoppingList
    {
        $this->authorizeCreate($actor, $plan);

        $existing = fn (): ?ShoppingList => ShoppingList::query()->where('meal_plan_id', $plan->getKey())->first();

        try {
            return $existing() ?? ShoppingList::create(['meal_plan_id' => $plan->getKey()]);
        } catch (UniqueConstraintViolationException) {
            return $existing() ?? throw new StaleShoppingListException;
        }
    }

    /**
     * Adds ingredient-only manual entries without inferring quantities from recipe servings.
     *
     * @param  Collection<int, Ingredient>  $ingredients
     */
    public function addMissingIngredients(User $actor, ShoppingList $list, Collection $ingredients): int
    {
        Gate::forUser($actor)->authorize('update', $list);

        $ingredients = $ingredients
            ->unique(fn (Ingredient $ingredient): int => (int) $ingredient->getKey())
            ->values();
        $ingredientIds = $ingredients
            ->map(fn (Ingredient $ingredient): int => (int) $ingredient->getKey())
            ->all();

        if ($ingredientIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($list, $ingredients, $ingredientIds): int {
            $lockedList = ShoppingList::query()->whereKey($list->getKey())->lockForUpdate()->firstOrFail();
            $existingIngredientIds = $lockedList->items()
                ->whereIn('ingredient_id', $ingredientIds)
                ->pluck('ingredient_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $missingIngredients = $ingredients->reject(
                fn (Ingredient $ingredient): bool => in_array((int) $ingredient->getKey(), $existingIngredientIds, true),
            )->values();

            if ($lockedList->items()->count() + $missingIngredients->count() > config('planner.max_shopping_items_per_list')) {
                throw ValidationException::withMessages(['shoppingList' => __('planner.shopping.errors.limit')]);
            }

            foreach ($missingIngredients as $ingredient) {
                $lockedList->items()->create([
                    'ingredient_id' => $ingredient->getKey(),
                    'is_manual' => true,
                ]);
            }

            if ($missingIngredients->isNotEmpty()) {
                $this->audit->record('shopping_list_missing_ingredients_added', [
                    'shopping_list_id' => $lockedList->getKey(),
                    'ingredient_count' => $missingIngredients->count(),
                ]);
            }

            return $missingIngredients->count();
        });
    }

    /**
     * @return array{excluded_entries: int}
     */
    public function generate(User $actor, ShoppingList $list): array
    {
        Gate::forUser($actor)->authorize('update', $list);

        return DB::transaction(function () use ($list): array {
            // Serialises regenerations; item rows are locked below so concurrent ticks cannot be overwritten.
            ShoppingList::query()->whereKey($list->getKey())->lockForUpdate()->firstOrFail();

            $plan = $list->mealPlan()->firstOrFail();
            $collected = $this->collector->collect($plan);

            $needed = collect($this->consolidator->consolidate($this->forShopping($collected['lines'])))
                ->keyBy(fn (ConsolidatedQuantity $row): string => $this->keyFor($row));

            $existing = $list->items()->where('is_manual', false)->lockForUpdate()->get()->keyBy('generated_key');

            foreach ($needed as $key => $row) {
                $item = $existing->get($key) ?? new ShoppingListItem(['shopping_list_id' => $list->getKey(), 'generated_key' => $key, 'ingredient_id' => $row->ingredientId]);
                $this->applyRequirement($item, $row);
            }

            foreach ($existing->diffKeys($needed) as $item) {
                $this->retire($item);
            }

            $list->forceFill(['last_generated_at' => now()])->save();
            $this->audit->record('shopping_list_generated', ['shopping_list_id' => $list->getKey(), 'meal_plan_id' => $list->meal_plan_id]);

            return ['excluded_entries' => count($collected['excluded_entry_ids'])];
        });
    }

    public function setChecked(User $actor, ShoppingListItem $item, int $expectedVersion, bool $checked): void
    {
        Gate::forUser($actor)->authorize('update', $item->shoppingList);

        DB::transaction(function () use ($actor, $item, $expectedVersion, $checked): void {
            $current = ShoppingListItem::query()->whereKey($item->getKey())->lockForUpdate()->first();

            if ($current === null || $current->version !== $expectedVersion) {
                throw new StaleShoppingListException;
            }

            if (! $checked && $current->is_stale) {
                // A bought item the plan no longer needs simply disappears once un-ticked.
                $current->delete();

                return;
            }

            $current->forceFill([
                'checked_at' => $checked ? now() : null,
                'checked_by' => $checked ? $actor->getKey() : null,
                // What has been bought is exactly what was required at this moment; later increases show as extra.
                'checked_base_quantity' => $checked ? $this->baseOf($current->quantity, $current->unit) : null,
                'extra_quantity' => null,
                'version' => $current->version + 1,
            ])->save();
        });
    }

    public function addManualItem(User $actor, ShoppingList $list, string $name, ?string $quantity = null, ?int $unitId = null): ShoppingListItem
    {
        Gate::forUser($actor)->authorize('update', $list);

        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['manualName' => __('planner.shopping.errors.name')]);
        }

        $quantity = filled($quantity) ? trim($quantity) : null;

        if ($quantity !== null && (preg_match('/^\d{1,8}(\.\d{1,4})?$/D', $quantity) !== 1 || preg_match('/[1-9]/', $quantity) !== 1)) {
            throw ValidationException::withMessages(['manualQuantity' => __('planner.shopping.errors.quantity')]);
        }

        if ($unitId !== null && ! Unit::query()->whereKey($unitId)->exists()) {
            throw ValidationException::withMessages(['manualUnit' => __('planner.shopping.errors.unit')]);
        }

        return DB::transaction(function () use ($list, $name, $quantity, $unitId): ShoppingListItem {
            ShoppingList::query()->whereKey($list->getKey())->lockForUpdate()->firstOrFail();

            if ($list->items()->count() >= config('planner.max_shopping_items_per_list')) {
                throw ValidationException::withMessages(['manualName' => __('planner.shopping.errors.limit')]);
            }

            return $list->items()->create([
                'custom_name' => $name,
                'is_manual' => true,
                'quantity' => $quantity,
                'unit_id' => $quantity !== null ? $unitId : null,
            ]);
        });
    }

    /**
     * Only manual items can be removed; generated rows would simply return on the next regeneration.
     */
    public function removeManualItem(User $actor, ShoppingListItem $item, int $expectedVersion): void
    {
        Gate::forUser($actor)->authorize('update', $item->shoppingList);
        abort_unless($item->is_manual, 404);

        $current = ShoppingListItem::query()->whereKey($item->getKey())->value('version');

        if ($current === null) {
            return;
        }

        if (ShoppingListItem::query()->whereKey($item->getKey())->where('version', $expectedVersion)->delete() === 0) {
            throw new StaleShoppingListException;
        }
    }

    /**
     * Display rows for one viewer in one locale.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function itemsView(User $viewer, ShoppingList $list, string $locale): Collection
    {
        Gate::forUser($viewer)->authorize('view', $list);

        $presenter = app(RecipeContentPresenter::class);
        $label = fn (?Unit $unit): ?string => $unit?->translateOrDefault($locale)?->abbreviation;
        $amount = fn (?string $quantity, ?Unit $unit): ?string => $quantity === null ? null : trim($presenter->formatQuantity($quantity, $locale).' '.$label($unit));

        return $list->items()->with(['ingredient.translations', 'ingredient.catalogGroup.translations', 'unit.translations'])->get()
            ->map(fn (ShoppingListItem $item): array => [
                'id' => $item->getKey(),
                'version' => $item->version,
                'name' => $item->custom_name ?? (string) ($item->ingredient?->translateOrDefault($locale)?->name ?? $item->ingredient?->code),
                'ingredient_url' => $item->custom_name === null ? $item->ingredient?->encyclopediaUrl($locale) : null,
                'amount' => $amount($item->quantity, $item->unit),
                'extra' => $item->extra_quantity !== null ? $amount($item->extra_quantity, $item->unit) : null,
                'checked' => $item->isChecked(),
                'optional' => $item->is_optional,
                'stale' => $item->is_stale,
                'manual' => $item->is_manual,
            ])
            ->sortBy([['checked', 'asc'], ['optional', 'asc'], fn (array $a, array $b): int => strcoll(mb_strtolower($a['name']), mb_strtolower($b['name']))])
            ->values();
    }

    /**
     * @param  list<QuantityLine>  $lines
     * @return list<QuantityLine>
     */
    private function forShopping(array $lines): array
    {
        return array_map(fn (QuantityLine $line): QuantityLine => new QuantityLine(
            $line->ingredientId, $line->unit, $line->quantityMax ?? $line->quantity, null, $line->isOptional, $line->source,
        ), $lines);
    }

    private function keyFor(ConsolidatedQuantity $row): string
    {
        $family = $row->quantity === null ? 'noamount' : $this->converter->family($row->unit);

        return $row->ingredientId.':'.$family.':'.($row->isOptional ? 'o' : 'r');
    }

    private function applyRequirement(ShoppingListItem $item, ConsolidatedQuantity $row): void
    {
        $item->fill([
            'source_requirements' => $item->exists && $item->source_requirements == $row->sources ? $item->source_requirements : $row->sources,
            'unit_id' => $row->unit?->getKey(),
            'quantity' => $row->quantity,
            'is_optional' => $row->isOptional,
            'is_stale' => false,
            'extra_quantity' => $item->isChecked() ? $this->extraNeeded($item, $row) : null,
        ]);

        $this->save($item);
    }

    private function extraNeeded(ShoppingListItem $item, ConsolidatedQuantity $row): ?string
    {
        if ($row->quantity === null) {
            return null;
        }

        $required = $this->baseOf($row->quantity, $row->unit);
        $bought = $item->checked_base_quantity === null ? BigDecimal::zero() : BigDecimal::of($item->checked_base_quantity);
        $missing = BigDecimal::of($required)->minus($bought);

        if ($missing->isLessThanOrEqualTo(0)) {
            return null;
        }

        return $row->unit ? $this->converter->fromBase($missing, $row->unit) : (string) $missing->toScale(UnitConverter::SCALE, RoundingMode::HalfUp);
    }

    // Bought items are kept (and flagged) when the plan no longer needs them; untouched ones are dropped.
    private function retire(ShoppingListItem $item): void
    {
        if (! $item->isChecked()) {
            $item->delete();

            return;
        }

        $item->fill(['is_stale' => true, 'extra_quantity' => null]);
        $this->save($item);
    }

    // Unchanged rows are not rewritten, so repeated generation leaves versions alone.
    private function save(ShoppingListItem $item): void
    {
        if ($item->exists && ! $item->isDirty()) {
            return;
        }

        if ($item->exists) {
            $item->version++;
        }

        $item->save();
    }

    private function baseOf(?string $quantity, ?Unit $unit): ?string
    {
        if ($quantity === null) {
            return null;
        }

        $base = $unit ? $this->converter->toBase($quantity, $unit) : BigDecimal::of($quantity);

        return (string) $base->toScale(UnitConverter::SCALE, RoundingMode::HalfUp);
    }

    private function authorizeCreate(User $actor, MealPlan $plan): void
    {
        Gate::forUser($actor)->authorize('view', $plan);

        if (! $plan->isPersonal()) {
            abort_unless($this->householdAccess->can($actor, $plan->household, HouseholdAbility::EditShopping), 403);
        }
    }
}
