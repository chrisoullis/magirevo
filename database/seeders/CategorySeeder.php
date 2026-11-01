<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\CategoryTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the default bilingual category taxonomy, identified by the stable category `code`.
 *
 * A normal run only creates what is missing and never overwrites names, slugs, sort
 * positions, active flags or parents that administrators have changed. Categories that
 * are not part of the taxonomy are left untouched, and recipe relationships are never modified.
 * restoreDefaults() is the explicit way to reset the seeded categories back to the defaults.
 */
class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $this->sync(overwrite: false);
    }

    /**
     * Resets every seeded category (parent, names, slugs, sort order, active flag) to the defaults.
     * Intended for development only; see the categories:restore-defaults command.
     */
    public function restoreDefaults(): void
    {
        $this->sync(overwrite: true);
    }

    private function sync(bool $overwrite): void
    {
        DB::transaction(function () use ($overwrite): void {
            foreach ($this->taxonomy() as $parentData) {
                $parent = $this->syncCategory($parentData, null, $overwrite);

                foreach ($parentData['children'] ?? [] as $childData) {
                    $this->syncCategory($childData, $parent, $overwrite);
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncCategory(array $data, ?Category $parent, bool $overwrite): Category
    {
        $category = Category::query()->where('code', $data['code'])->first();
        $defaults = [
            'parent_id' => $parent?->getKey(),
            'sort_order' => $data['sort_order'],
            'is_active' => $data['is_active'],
        ];

        if ($category === null) {
            $category = Category::query()->create(['code' => $data['code'], ...$defaults]);
        } elseif ($overwrite) {
            $category->fill($defaults)->save();
        }

        foreach ($data['name'] as $locale => $name) {
            $translation = $category->translations()->where('locale', $locale)->first();

            if ($translation !== null && ! $overwrite) {
                continue;
            }

            $translation ??= new CategoryTranslation(['locale' => $locale]);
            $translation->fill([
                'name' => $name,
                'slug' => $this->uniqueSlug($data['slug'][$locale], $locale, $translation->getKey()),
            ]);
            $category->translations()->save($translation);
        }

        return $category;
    }

    private function uniqueSlug(string $slug, string $locale, ?int $ignoreTranslationId): string
    {
        $candidate = $slug;
        $suffix = 2;

        while (CategoryTranslation::query()
            ->where('locale', $locale)
            ->where('slug', $candidate)
            ->when($ignoreTranslationId, fn ($query) => $query->whereKeyNot($ignoreTranslationId))
            ->exists()) {
            $candidate = "{$slug}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function taxonomy(): array
    {
        return require __DIR__.'/data/category_taxonomy.php';
    }
}
