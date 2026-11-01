<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single source of truth for the two-level category hierarchy and sibling ordering.
 * The Category model calls assertValid() on every save, so the rules also cover
 * seeders, imports and any future API, not just the Filament form.
 */
class CategoryHierarchyService
{
    public const SORT_STEP = 10;

    /**
     * @throws ValidationException
     */
    public function assertValid(Category $category): void
    {
        $this->assertCodeUnchanged($category);

        if ($category->parent_id === null) {
            return;
        }

        $message = $this->parentViolation($category->getKey(), (int) $category->parent_id);

        if ($message !== null) {
            throw ValidationException::withMessages(['parent_id' => $message]);
        }
    }

    /**
     * Returns why $parentId cannot be the parent of category $categoryId, or null when it is allowed.
     */
    public function parentViolation(?int $categoryId, ?int $parentId): ?string
    {
        if ($parentId === null) {
            return null;
        }

        if ($categoryId !== null && $categoryId === $parentId) {
            return 'A category cannot be its own parent.';
        }

        $parent = Category::query()->find($parentId);

        if ($parent === null) {
            return 'The selected parent category does not exist.';
        }

        if ($categoryId !== null && $parent->parent_id === $categoryId) {
            return 'A category cannot be moved under one of its own subcategories.';
        }

        if ($parent->parent_id !== null) {
            return 'Only two category levels are allowed: a subcategory cannot have subcategories.';
        }

        if ($categoryId !== null && Category::query()->where('parent_id', $categoryId)->exists()) {
            return 'This category has subcategories. Move or remove them before giving it a parent.';
        }

        return null;
    }

    public function nextSortOrder(?int $parentId): int
    {
        $max = Category::query()->where('parent_id', $parentId)->max('sort_order');

        return $max === null ? self::SORT_STEP : ((int) $max) + self::SORT_STEP;
    }

    /**
     * Moves a category under a new parent (or to the root) and places it last among its new siblings.
     */
    public function moveTo(Category $category, ?Category $parent): Category
    {
        return DB::transaction(function () use ($category, $parent): Category {
            $category = Category::query()->lockForUpdate()->findOrFail($category->getKey());
            $parentId = $parent?->getKey();

            if ($category->parent_id !== $parentId) {
                $category->parent_id = $parentId;
                $category->sort_order = $this->nextSortOrder($parentId);
                $category->save();
            }

            return $category;
        });
    }

    /**
     * Applies an explicit order to the siblings sharing $parentId (null = root categories).
     * Only the listed siblings are touched; ids belonging to other parents are rejected.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorderSiblings(?int $parentId, array $orderedIds): void
    {
        DB::transaction(function () use ($parentId, $orderedIds): void {
            $siblings = Category::query()
                ->where('parent_id', $parentId)
                ->whereIn('id', $orderedIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($siblings->count() !== count(array_unique($orderedIds))) {
                throw ValidationException::withMessages(['order' => 'Categories can only be reordered among siblings of the same parent.']);
            }

            foreach (array_values($orderedIds) as $position => $id) {
                $siblings[$id]->forceFill(['sort_order' => ($position + 1) * self::SORT_STEP])->saveQuietly();
            }
        });
    }

    /**
     * Swaps a category with its previous (-1) or next (+1) sibling, renumbering all siblings.
     */
    public function shift(Category $category, int $direction): void
    {
        DB::transaction(function () use ($category, $direction): void {
            $ids = Category::query()
                ->where('parent_id', $category->parent_id)
                ->lockForUpdate()
                ->ordered()
                ->pluck('id')
                ->all();

            $index = array_search($category->getKey(), $ids, true);
            $target = $index === false ? null : $index + ($direction < 0 ? -1 : 1);

            if ($target === null || ! isset($ids[$target])) {
                return;
            }

            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

            $this->reorderSiblings($category->parent_id, $ids);
        });
    }

    private function assertCodeUnchanged(Category $category): void
    {
        if ($category->exists && $category->isDirty('code')) {
            throw ValidationException::withMessages(['code' => 'A category code cannot be changed after creation.']);
        }
    }
}
