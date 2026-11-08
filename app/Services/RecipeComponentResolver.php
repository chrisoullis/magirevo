<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeRevision;
use App\Models\Unit;
use App\Models\User;
use App\Support\QuantityLine;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RecipeComponentResolver
{
    public const MAX_DEPTH = 5;

    public const MAX_COMPONENT_OCCURRENCES = 500;

    public const MAX_EXPANDED_LINES = 10000;

    /** @var array<int, RecipeRevision|null> */
    private array $revisionCache = [];

    /** @var array<int, bool> */
    private array $ingredientCache = [];

    public function __construct(private ServingAdjustmentService $scaling, private UnitConverter $converter) {}

    /**
     * Resolve each occurrence independently. The same sub-recipe used twice contributes twice; a prepared
     * occurrence contributes its mapped product instead of its underlying ingredients. No partial requirements
     * are returned when any dependency cannot be resolved safely.
     *
     * @param  array<string, string>  $modes  Stable component paths mapped to scratch/prepared choices.
     * @return array{lines: list<QuantityLine>, components: list<array<string, mixed>>, unresolved: list<array<string, mixed>>}
     */
    public function resolve(Recipe|RecipeRevision $subject, ?User $viewer = null, ?string $servings = null, array $modes = [], string $purpose = 'shopping'): array
    {
        $recipe = $subject instanceof RecipeRevision ? $subject->recipe : $subject;
        if ($recipe === null || $recipe->trashed() || ! $this->canViewDirect($viewer, $recipe)) {
            return $this->unavailable();
        }
        try {
            if ($subject instanceof RecipeRevision && ! $this->readableRevision($subject, $recipe, $viewer)) {
                return $this->unavailable();
            }
            $payload = $this->payload($subject);
            $this->assertValidPayload($payload, $recipe->id, $viewer, $this->requiresPublicSources($recipe));
            $result = $this->expand($payload, $recipe->id, $servings ?? (string) ($payload['recipe']['base_servings'] ?? '1'), $modes, $purpose, '', [], $subject instanceof RecipeRevision ? $subject->id : ($this->requiresPublicSources($recipe) ? $recipe->current_revision_id : null));
            if ($result['unresolved'] !== []) {
                $result['lines'] = [];
            }

            return $result;
        } catch (ValidationException|InvalidArgumentException $exception) {
            return $this->unavailable();
        }
    }

    public function canResolve(Recipe|RecipeRevision $subject, ?User $viewer = null): bool
    {
        $recipe = $subject instanceof RecipeRevision ? $subject->recipe : $subject;
        if ($recipe === null || $recipe->trashed() || ! $this->canViewDirect($viewer, $recipe)) {
            return false;
        }
        try {
            if ($subject instanceof RecipeRevision && ! $this->readableRevision($subject, $recipe, $viewer)) {
                return false;
            }
            if ($subject instanceof Recipe && ! $this->requiresPublicSources($recipe)
                && ! $subject->recipeIngredients()->where('line_type', 'recipe')->exists()) {
                return true;
            }
            $this->assertValidPayload($this->payload($subject), $recipe->id, $viewer, $this->requiresPublicSources($recipe));

            return true;
        } catch (ValidationException $exception) {
            return false;
        }
    }

    /** Root-only check for callers that have already established that a payload has no component rows. */
    public function canReadSubject(Recipe|RecipeRevision $subject, ?User $viewer = null): bool
    {
        $recipe = $subject instanceof RecipeRevision ? $subject->recipe : $subject;
        if ($recipe === null || $recipe->trashed() || ! $this->canViewDirect($viewer, $recipe)) {
            return false;
        }

        return ! $subject instanceof RecipeRevision || ($this->readableRevision($subject, $recipe, $viewer)
            && hash_equals($subject->content_hash, app(RecipeRevisionSnapshotter::class)->hash($subject->payload)));
    }

    public function unitForPayload(array $payload, int|string|null $id): ?Unit
    {
        return $this->unit($payload, $id);
    }

    /**
     * The actor may select a private retained revision only while they can view its recipe. Public publication
     * additionally requires every source to remain public and every source revision to have been published.
     */
    public function assertValidPayload(array $payload, int $parentRecipeId, ?User $actor = null, bool $requirePublic = false): void
    {
        if (! config('nested-recipes.enabled') && collect($payload['ingredients'] ?? [])->contains(fn (array $line): bool => ($line['line_type'] ?? 'ingredient') === 'recipe')) {
            throw ValidationException::withMessages(['ingredients' => __('nested.disabled')]);
        }
        $this->revisionCache = [];
        $this->ingredientCache = [];
        $budget = ['components' => 0, 'lines' => 0];
        $this->validateGraph($payload, [$parentRecipeId], $actor, $requirePublic, 0, $budget);
        if ($this->positive($payload['recipe']['base_servings'] ?? null) && collect($payload['ingredients'] ?? [])->contains(fn (array $line): bool => ($line['line_type'] ?? 'ingredient') === 'recipe')) {
            $this->expand($payload, $parentRecipeId, (string) $payload['recipe']['base_servings'], [], 'display', '', []);
        }
    }

    private function validateGraph(array $payload, array $ancestors, ?User $actor, bool $requirePublic, int $depth, array &$budget): void
    {
        $ingredientIds = collect($payload['ingredients'] ?? [])->filter(fn (array $line): bool => ($line['line_type'] ?? 'ingredient') !== 'recipe')
            ->pluck('ingredient_id')->map(fn ($id): int => (int) $id)->unique()->all();
        $unknown = array_values(array_diff($ingredientIds, array_keys($this->ingredientCache)));
        if ($unknown !== []) {
            $existing = Ingredient::whereIn('id', $unknown)->pluck('id')->all();
            foreach ($unknown as $id) {
                $this->ingredientCache[$id] = in_array($id, $existing, true);
            }
        }
        if (array_filter($ingredientIds, fn (int $id): bool => ! $this->ingredientCache[$id]) !== []) {
            throw ValidationException::withMessages(['ingredients' => __('nested_validation.ingredient')]);
        }
        $lockedSources = [];
        if ($requirePublic && DB::transactionLevel() > 0) {
            $sourceIds = collect($payload['ingredients'] ?? [])->where('line_type', 'recipe')->pluck('component_recipe_id')->filter()->unique()->sort()->values();
            if ($sourceIds->isNotEmpty()) {
                $lockedSources = Recipe::withTrashed()->whereIn('id', $sourceIds)->orderBy('id')->sharedLock()->get()->keyBy('id')->all();
            }
        }
        $seen = [];
        foreach ($payload['ingredients'] ?? [] as $index => $line) {
            if (($line['unit_id'] ?? null) !== null) {
                $lineUnit = $this->unit($payload, $line['unit_id']);
                if ($lineUnit === null || (in_array($lineUnit->unit_type, ['mass', 'volume'], true) && ! $this->positive($lineUnit->base_conversion))) {
                    throw ValidationException::withMessages(['ingredients.'.$index.'.unit_id' => __('nested_validation.basis')]);
                }
            }
            if (($line['line_type'] ?? 'ingredient') !== 'recipe') {
                if (++$budget['lines'] > self::MAX_EXPANDED_LINES) {
                    throw ValidationException::withMessages(['ingredients' => __('nested_validation.lines')]);
                }

                continue;
            }
            $error = fn (string $code) => ValidationException::withMessages(['ingredients.'.$index.'.component_recipe_id' => __('nested_validation.'.$code)]);
            if (++$budget['components'] > self::MAX_COMPONENT_OCCURRENCES) {
                throw $error('limit');
            }
            $uuid = $line['line_uuid'] ?? null;
            if (! is_string($uuid) || ! Str::isUuid($uuid) || isset($seen[$uuid])) {
                throw $error('identity');
            }
            $seen[$uuid] = true;
            $sourceId = (int) ($line['component_recipe_id'] ?? 0);
            if (in_array($sourceId, $ancestors, true)) {
                throw $error('cycle');
            }
            if ($depth >= self::MAX_DEPTH) {
                throw $error('depth');
            }
            $revision = $this->revision((int) ($line['component_recipe_revision_id'] ?? 0));
            $source = $revision ? ($lockedSources[$revision->recipe_id] ?? $revision->recipe) : null;
            $status = $revision?->getRawOriginal('status');
            if ($revision === null || $source === null || $source->trashed() || $source->status === 'archived' || $source->id !== $sourceId
                || ! in_array($status, ['published', 'superseded', 'retained'], true)
                || ! $this->canViewDirect($actor, $source)) {
                throw $error('unavailable');
            }
            if (($status !== 'retained' && ($revision->published_at === null || $source->status !== 'published'))
                || ($status === 'retained' && ! $this->canReadRetained($actor, $source))
                || ($requirePublic && (! $this->publicState($source) || $status === 'retained'))) {
                throw $error('unavailable');
            }
            if (! hash_equals($revision->content_hash, app(RecipeRevisionSnapshotter::class)->hash($revision->payload))) {
                throw $error('revision');
            }
            $basis = $line['quantity_basis'] ?? null;
            if (! in_array($basis, ['whole_batch', 'servings', 'weight', 'volume'], true)) {
                throw $error('basis');
            }
            if (($line['quantity'] ?? null) !== null && ! $this->positive($line['quantity'])
                || (($line['quantity'] ?? null) === null && ($line['scaling_mode'] ?? 'linear') !== 'manual')
                || (($line['quantity_max'] ?? null) !== null && (! $this->positive($line['quantity_max'])
                    || (($line['quantity'] ?? null) !== null && BigDecimal::of($line['quantity_max'])->isLessThan($line['quantity']))))) {
                throw $error('basis');
            }
            if (! in_array($basis, ['weight', 'volume'], true) && ($line['unit_id'] ?? null) !== null) {
                throw $error('basis');
            }
            if (in_array($basis, ['weight', 'volume'], true)) {
                $yield = collect($revision->payload['yields'] ?? [])->firstWhere('basis', $basis);
                $unit = $this->unit($payload, $line['unit_id'] ?? null);
                $yieldUnit = $yield ? $this->unit($revision->payload, $yield['unit_id'] ?? null) : null;
                if ($yield === null || ! $this->positive($yield['quantity'] ?? null) || empty($yield['verified_by']) || empty($yield['verified_at']) || empty($yield['evidence'])
                    || $unit === null || $yieldUnit === null || $unit->unit_type !== ($basis === 'weight' ? 'mass' : 'volume')
                    || ! $this->converter->canCombine($unit, $yieldUnit)
                    || ! $this->positive($unit->base_conversion) || ! $this->positive($yieldUnit->base_conversion)) {
                    throw $error('yield');
                }
            }
            if ($basis === 'servings' && ! $this->positive($revision->payload['recipe']['base_servings'] ?? null)) {
                throw $error('servings');
            }
            $this->validateGraph($revision->payload, [...$ancestors, $sourceId], $actor, $requirePublic, $depth + 1, $budget);
        }
    }

    private function expand(array $payload, int $recipeId, string|BigRational $servings, array $modes, string $purpose, string $prefix, array $sourcePath, ?int $revisionId = null, array $revisionPath = []): array
    {
        $result = ['lines' => [], 'components' => [], 'unresolved' => []];
        $base = (string) ($payload['recipe']['base_servings'] ?? '0');
        if (! $this->positive($base) || ! $this->positive($servings)) {
            throw new InvalidArgumentException('Recipe servings must be positive.');
        }
        foreach ($payload['ingredients'] ?? [] as $index => $line) {
            $uuid = (string) ($line['line_uuid'] ?? 'legacy-'.$recipeId.'-'.($line['id'] ?? $index));
            $path = $prefix === '' ? $uuid : $prefix.'/'.$uuid;
            $unit = $this->unit($payload, $line['unit_id'] ?? null);
            $scaled = $this->scaled($line, $base, $servings);
            $source = ['recipe_id' => $recipeId, 'recipe_ingredient_id' => $line['id'] ?? ($revisionId !== null ? ($this->revision($revisionId)?->resolved_ids['ingredients'][$index] ?? null) : null), 'line_uuid' => $uuid, 'component_path' => $sourcePath, 'path' => $path,
                'recipe_revision_id' => $revisionId,
                'recipe_revision_status' => $revisionId !== null ? $this->revision($revisionId)?->getRawOriginal('status') : null,
                'revision_path' => [...$revisionPath, ['recipe_id' => $recipeId, 'recipe_revision_id' => $revisionId]]];
            if (($line['line_type'] ?? 'ingredient') !== 'recipe') {
                $result['lines'][] = new QuantityLine((int) $line['ingredient_id'], $unit, $scaled['quantity'], $scaled['quantity_max'], (bool) ($line['is_optional'] ?? false), $source);

                continue;
            }
            $revision = $this->revision((int) $line['component_recipe_revision_id']);
            if ($revision === null) {
                throw ValidationException::withMessages(['ingredients' => __('nested_validation.unavailable')]);
            }
            $childPayload = $revision->payload;
            $basis = $line['quantity_basis'];
            $mode = $purpose === 'dietary' || $purpose === 'display' ? 'scratch' : ($modes[$path] ?? $line['shopping_mode'] ?? 'scratch');
            if (! in_array($mode, ['scratch', 'prepared'], true)) {
                throw new InvalidArgumentException('Unknown component preparation mode.');
            }
            $translation = $childPayload['translations'][app()->getLocale()] ?? collect($childPayload['translations'] ?? [])->first() ?? [];
            $component = [
                'path' => $path, 'line_uuid' => $uuid, 'recipe_id' => $revision->recipe_id, 'revision_id' => $revision->id,
                'title' => $translation['title'] ?? '', 'translations' => $childPayload['translations'] ?? [],
                'quantity' => $scaled['quantity'], 'quantity_max' => $scaled['quantity_max'], 'unit_id' => $line['unit_id'] ?? null,
                'unit' => $unit, 'quantity_basis' => $basis, 'shopping_mode' => $mode,
                'can_use_prepared' => $scaled['quantity'] !== null && ($line['scaling_mode'] ?? 'linear') !== 'manual'
                    && ! empty($line['prepared_ingredient_id']) && $this->positive($line['prepared_quantity_per_batch'] ?? null),
                'payload' => $childPayload, 'lines' => [], 'is_optional' => (bool) ($line['is_optional'] ?? false),
                'translations_notes' => $line['translations'] ?? [],
            ];
            if ($scaled['quantity'] === null || ($line['scaling_mode'] ?? 'linear') === 'manual') {
                if ($purpose !== 'dietary') {
                    $result['components'][] = $component;
                    if ($purpose !== 'display') {
                        $result['unresolved'][] = ['path' => $path, 'code' => 'manual_quantity'];
                    }

                    continue;
                }
                $ratio = BigRational::of(1);
            } else {
                $ratio = $this->batchRatio($basis, $scaled['precise_quantity'], $unit, $childPayload);
            }
            $component['resolved_servings'] = (string) $ratio->multipliedBy($childPayload['recipe']['base_servings'])->toScale(16, RoundingMode::HalfUp);
            $maxRatio = $scaled['precise_quantity_max'] !== null ? $this->batchRatio($basis, $scaled['precise_quantity_max'], $unit, $childPayload) : $ratio;
            if ($mode === 'prepared') {
                if (! $component['can_use_prepared'] || ! Ingredient::whereKey($line['prepared_ingredient_id'])->where('is_active', true)->exists()) {
                    $result['unresolved'][] = ['path' => $path, 'code' => 'prepared_mapping_missing'];
                } else {
                    $mappedUnit = $this->unit($payload, $line['prepared_unit_id'] ?? null);
                    if ($mappedUnit !== null && in_array($mappedUnit->unit_type, ['mass', 'volume'], true) && ! $this->positive($mappedUnit->base_conversion)) {
                        throw new InvalidArgumentException('Prepared measurement requires a positive conversion factor.');
                    }
                    $component['lines'][] = new QuantityLine((int) $line['prepared_ingredient_id'], $mappedUnit,
                        $this->decimal($ratio->multipliedBy($line['prepared_quantity_per_batch'])),
                        $maxRatio->isEqualTo($ratio) ? null : $this->decimal($maxRatio->multipliedBy($line['prepared_quantity_per_batch'])),
                        $component['is_optional'], [...$source, 'component_recipe_id' => $revision->recipe_id, 'component_recipe_revision_id' => $revision->id]);
                }
            } else {
                $childServings = $ratio->multipliedBy($childPayload['recipe']['base_servings']);
                $child = $this->expand($childPayload, $revision->recipe_id, $childServings, $modes, $purpose, $path, [...$sourcePath, $uuid], $revision->id, $source['revision_path']);
                $component['children'] = $child['components'];
                $maxLines = [];
                if (! $maxRatio->isEqualTo($ratio)) {
                    $maximum = $this->expand($childPayload, $revision->recipe_id, $maxRatio->multipliedBy($childPayload['recipe']['base_servings']), $modes, $purpose, $path, [...$sourcePath, $uuid], $revision->id, $source['revision_path']);
                    $maxLines = collect($maximum['lines'])->keyBy(fn (QuantityLine $leaf): string => $leaf->source['path'])->all();
                }
                foreach ($child['lines'] as $leaf) {
                    $maximum = $maxLines[$leaf->source['path']] ?? null;
                    $component['lines'][] = new QuantityLine($leaf->ingredientId, $leaf->unit, $leaf->quantity,
                        $maximum?->quantityMax ?? $maximum?->quantity ?? $leaf->quantityMax,
                        $component['is_optional'] || $leaf->isOptional, $leaf->source);
                }
                $result['unresolved'] = [...$result['unresolved'], ...$child['unresolved']];
            }
            $result['components'][] = $component;
            $result['components'] = [...$result['components'], ...($component['children'] ?? [])];
            $result['lines'] = [...$result['lines'], ...$component['lines']];
        }

        return $result;
    }

    private function batchRatio(string $basis, BigRational $quantity, ?Unit $unit, array $payload): BigRational
    {
        if (! $this->positive($quantity)) {
            throw new InvalidArgumentException('A component quantity must be positive.');
        }
        if ($basis === 'whole_batch') {
            return $quantity;
        }
        if ($basis === 'servings') {
            return $quantity->dividedBy($payload['recipe']['base_servings']);
        }
        $yield = collect($payload['yields'])->firstWhere('basis', $basis);
        $yieldUnit = $this->unit($payload, $yield['unit_id']);

        return $quantity->multipliedBy($this->converter->toBase('1', $unit))
            ->dividedBy($this->converter->toBase($yield['quantity'], $yieldUnit));
    }

    private function scaled(array $line, string $base, string|BigRational $servings): array
    {
        $model = new RecipeIngredient;
        $model->forceFill(['quantity' => $line['quantity'] ?? null, 'quantity_max' => $line['quantity_max'] ?? null, 'scaling_mode' => $line['scaling_mode'] ?? 'linear']);
        $model->setRelation('recipe', (new Recipe)->forceFill(['base_servings' => $base]));

        $scaled = $this->scaling->adjustForBatch($model, BigRational::of($servings)->dividedBy($base));
        $precision = ($line['line_type'] ?? 'ingredient') === 'recipe' ? 64 : UnitConverter::SCALE;

        foreach (['quantity', 'quantity_max'] as $field) {
            if ($scaled[$field]?->isGreaterThan(0) && $scaled[$field]->toScale($precision, RoundingMode::HalfUp)->isZero()) {
                throw ValidationException::withMessages(['ingredients' => __('nested_validation.precision')]);
            }
        }

        return [
            'quantity' => $scaled['quantity']?->toScale($precision, RoundingMode::HalfUp)->__toString(),
            'quantity_max' => $scaled['quantity_max']?->toScale($precision, RoundingMode::HalfUp)->__toString(),
            'precise_quantity' => $scaled['quantity'], 'precise_quantity_max' => $scaled['quantity_max'],
        ];
    }

    private function revision(int $id): ?RecipeRevision
    {
        if (! array_key_exists($id, $this->revisionCache)) {
            $this->revisionCache[$id] = RecipeRevision::query()->with('recipe')->find($id);
        }

        return $this->revisionCache[$id];
    }

    private function payload(Recipe|RecipeRevision $subject): array
    {
        if ($subject instanceof RecipeRevision) {
            return $subject->payload;
        }
        if ($this->requiresPublicSources($subject) && $subject->current_revision_id !== null) {
            $revision = $subject->currentRevision;
            if ($revision === null || $revision->getRawOriginal('status') !== 'published') {
                throw ValidationException::withMessages(['recipe' => 'Recipe revision is unavailable.']);
            }
            if (! hash_equals($revision->content_hash, app(RecipeRevisionSnapshotter::class)->hash($revision->payload))) {
                throw ValidationException::withMessages(['recipe' => __('nested_validation.revision')]);
            }

            return $revision->payload;
        }

        return app(RecipeRevisionSnapshotter::class)->serialize($subject->id);
    }

    private function unit(array $payload, int|string|null $id): ?Unit
    {
        if ($id === null) {
            return null;
        }
        $snapshot = $payload['units'][(string) $id] ?? null;
        if ($snapshot === null) {
            $snapshot = collect($payload['units'] ?? [])->first(fn (array $row): bool => (int) ($row['id'] ?? $row['unit_id'] ?? 0) === (int) $id);
        }
        if ($snapshot !== null) {
            return (new Unit)->forceFill(['id' => (int) $id, ...$snapshot]);
        }

        return Unit::find($id);
    }

    private function canViewDirect(?User $viewer, Recipe $recipe): bool
    {
        $access = app(RecipeAccessService::class);

        return method_exists($access, 'canViewDirect') ? $access->canViewDirect($viewer, $recipe) : $access->canView($viewer, $recipe);
    }

    private function readableRevision(RecipeRevision $revision, Recipe $recipe, ?User $viewer): bool
    {
        $status = $revision->getRawOriginal('status');

        return ($status === 'retained' && $this->canReadRetained($viewer, $recipe))
            || (in_array($status, ['published', 'superseded'], true) && $revision->published_at !== null && $recipe->status === 'published');
    }

    private function requiresPublicSources(Recipe $recipe): bool
    {
        return $recipe->visibility?->value === 'public';
    }

    private function publicState(Recipe $recipe): bool
    {
        return $this->requiresPublicSources($recipe) && $recipe->status === 'published'
            && $recipe->published_at !== null && ! $recipe->published_at->isFuture() && ! $recipe->trashed();
    }

    private function canReadRetained(?User $viewer, Recipe $recipe): bool
    {
        if ($viewer === null || ! $viewer->hasVerifiedEmail()) {
            return false;
        }
        $access = app(RecipeAccessService::class);

        return $access->owns($viewer, $recipe) || $access->hasActiveShare($viewer, $recipe)
            || $access->canManage($viewer, $recipe, 'View:Recipe');
    }

    private function positive(mixed $value): bool
    {
        if ($value instanceof BigRational) {
            return $value->isGreaterThan(0);
        }

        return is_scalar($value) && preg_match('/^\d+(?:\.\d+)?$/D', (string) $value) === 1 && preg_match('/[1-9]/', (string) $value) === 1;
    }

    private function decimal(BigDecimal|BigRational $value): string
    {
        return $value instanceof BigRational ? (string) $value->toScale(UnitConverter::SCALE, RoundingMode::HalfUp)
            : (string) $value->toScale(UnitConverter::SCALE, RoundingMode::HalfUp);
    }

    private function unavailable(): array
    {
        return ['lines' => [], 'components' => [], 'unresolved' => [['code' => 'component_unavailable']]];
    }
}
