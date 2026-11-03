<?php

namespace Database\Seeders;

use App\Models\Ingredient;
use App\Models\IngredientCatalogGroup;
use App\Models\IngredientTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class IngredientCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $data = require __DIR__.'/data/ingredient_catalog.php';
        $this->validateDataset($data);
        $created = ['groups' => 0, 'ingredients' => 0, 'translations' => 0, 'aliases' => 0];

        DB::transaction(function () use ($data, &$created): void {
            DB::select('SELECT pg_advisory_xact_lock(?)', [76420321]);
            $this->assertNoNameConflicts($data['ingredients']);
            $groups = [];

            foreach ($data['groups'] as $row) {
                $group = IngredientCatalogGroup::query()->firstOrCreate(['code' => $row['code']], [
                    'is_active' => true, 'sort_order' => $row['sort_order'],
                ]);
                $created['groups'] += (int) $group->wasRecentlyCreated;

                foreach (['en', 'el'] as $locale) {
                    $group->translations()->firstOrCreate(['locale' => $locale], ['name' => $row[$locale]]);
                }

                $groups[$row['code']] = $group->id;
            }

            foreach ($data['ingredients'] as $row) {
                $ingredient = Ingredient::query()->firstOrCreate(['code' => $row['code']], [
                    'is_active' => true, 'catalog_group_id' => $groups[$row['group_code']],
                ]);
                $created['ingredients'] += (int) $ingredient->wasRecentlyCreated;

                Ingredient::query()->whereKey($ingredient->id)->whereNull('catalog_group_id')
                    ->update(['catalog_group_id' => $groups[$row['group_code']]]);

                foreach (['en', 'el'] as $locale) {
                    $translation = $ingredient->translations()->firstOrCreate(['locale' => $locale], fn (): array => [
                        'name' => $row[$locale],
                        'slug' => $this->availableSlug($row[$locale], $row['code'], $locale),
                    ]);
                    $created['translations'] += (int) $translation->wasRecentlyCreated;

                    if ($translation->slug === null) {
                        $slug = $this->availableSlug($translation->name, $row['code'], $locale);
                        IngredientTranslation::query()->whereKey($translation->id)->whereNull('slug')->update(['slug' => $slug]);
                    }

                    foreach ($row['aliases'][$locale] ?? [] as $alias) {
                        $existing = $ingredient->aliases()->where('locale', $locale)->whereRaw('lower(alias) = lower(?)', [$alias])->exists();

                        if (! $existing) {
                            $ingredient->aliases()->firstOrCreate(['locale' => $locale, 'alias' => $alias]);
                            $created['aliases']++;
                        }
                    }
                }
            }
        });

        foreach (array_count_values(array_column($data['ingredients'], 'group_code')) as $group => $count) {
            $this->command?->info("{$group}: {$count}");
        }

        $this->command?->info('Ingredient catalogue v'.$data['version'].': '.count($data['ingredients']).' entries, '.count($data['groups']).' groups. Created: '.json_encode($created));
        $this->command?->info('Existing content, units, states, media and recipe relationships preserved. No dietary evidence, approvals or substitutions seeded.');
    }

    /** @param array<string, mixed> $data */
    private function validateDataset(array $data): void
    {
        Validator::make($data, [
            'version' => ['required', 'integer', 'in:1'],
            'groups' => ['required', 'array', 'size:16'],
            'groups.*.code' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/', 'distinct:strict'],
            'groups.*.en' => ['required', 'string', 'max:150'],
            'groups.*.el' => ['required', 'string', 'max:150'],
            'groups.*.sort_order' => ['required', 'integer'],
            'ingredients' => ['required', 'array', 'min:500'],
        ])->validate();
        $groups = array_column($data['groups'], 'code');
        $seen = [];

        foreach ($data['ingredients'] as $row) {
            Validator::make($row, [
                'code' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
                'group_code' => ['required', Rule::in($groups)],
                'en' => ['required', 'string', 'max:255'],
                'el' => ['required', 'string', 'max:255'],
                'aliases' => ['sometimes', 'array:en,el'],
                'aliases.*' => ['array'],
                'aliases.*.*' => ['required', 'string', 'max:255'],
            ])->validate();

            foreach (['code', 'en', 'el'] as $key) {
                $value = mb_strtolower(trim($row[$key]));

                if (isset($seen[$key][$value])) {
                    throw new RuntimeException("Duplicate catalogue {$key} [{$row[$key]}].");
                }

                $seen[$key][$value] = true;
            }
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function assertNoNameConflicts(array $rows): void
    {
        $existing = IngredientTranslation::query()->with('ingredient')->whereIn('locale', ['en', 'el'])->get();
        $owners = [];

        foreach ($existing as $translation) {
            $owners[$translation->locale][mb_strtolower($translation->name)] = $translation->ingredient->code;
        }

        foreach ($rows as $row) {
            foreach (['en', 'el'] as $locale) {
                $owner = $owners[$locale][mb_strtolower($row[$locale])] ?? null;

                if ($owner !== null && $owner !== $row['code']) {
                    throw new RuntimeException("Ingredient name conflict: [{$row['code']}] {$locale} [{$row[$locale]}] belongs to existing code [{$owner}]. Review manually; nothing was seeded.");
                }
            }
        }
    }

    private function availableSlug(string $name, string $code, string $locale): string
    {
        $base = Str::substr(Str::slug($name) ?: $code, 0, 170);
        $slug = $base;
        $suffix = 1;

        while (IngredientTranslation::query()->where('locale', $locale)->where('slug', $slug)->exists()) {
            $ending = '-'.$code.'-'.$suffix++;
            $slug = Str::substr($base, 0, 180 - strlen($ending)).$ending;
        }

        return $slug;
    }
}
