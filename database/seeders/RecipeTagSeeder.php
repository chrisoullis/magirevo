<?php

namespace Database\Seeders;

use App\Enums\TagClassification;
use App\Enums\TagGroup;
use App\Models\Tag;
use App\Models\TagTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class RecipeTagSeeder extends Seeder
{
    public function run(): void
    {
        $rows = require __DIR__.'/data/recipe_tags.php';

        Validator::make(['tags' => $rows], [
            'tags' => ['required', 'array', 'size:338'],
            'tags.*' => ['required', 'array:group_code,code,en,el,requires_claim_approval'],
            'tags.*.code' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'distinct:strict'],
            'tags.*.group_code' => ['required', Rule::enum(TagGroup::class)],
            'tags.*.en' => ['required', 'string', 'max:150'],
            'tags.*.el' => ['required', 'string', 'max:150'],
            'tags.*.requires_claim_approval' => ['required', 'boolean'],
        ])->validate();

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $position => $row) {
                $classification = $this->classification($row['group_code'], $row['code']);

                if ($row['requires_claim_approval'] !== $classification->isDietaryClaim()) {
                    throw new RuntimeException("Claim approval metadata conflicts for tag [{$row['code']}].");
                }

                $existing = Tag::query()->where('code', $row['code'])->lockForUpdate()->first();

                if ($existing !== null && $existing->classification !== $classification) {
                    throw new RuntimeException("Classification conflict for tag [{$row['code']}]: existing [{$existing->classification->value}], expected [{$classification->value}]. Review manually; no tags were seeded.");
                }

                $tag = Tag::query()->updateOrCreate(['code' => $row['code']], $existing === null ? [
                    'classification' => $classification,
                    'group_code' => $row['group_code'],
                    'is_active' => true,
                    'is_public_enabled' => false,
                    'sort_order' => ($position + 1) * 10,
                ] : []);

                if ($tag->group_code === null) {
                    $tag->update(['group_code' => $row['group_code']]);
                }

                foreach (['en', 'el'] as $locale) {
                    $translation = $tag->translations()->where('locale', $locale)->first();
                    $tag->translations()->updateOrCreate(['tag_id' => $tag->getKey(), 'locale' => $locale], $translation === null ? [
                        'name' => $row[$locale],
                        'slug' => $this->availableSlug($row[$locale], $row['code'], $locale),
                    ] : []);
                }
            }
        });

        foreach (array_count_values(array_column($rows, 'group_code')) as $group => $count) {
            $this->command?->info("{$group}: {$count}");
        }

        $this->command?->info('Recipe tag catalogue seeded: 338 entries. Existing content and states preserved; no recipe associations or dietary claims created.');
    }

    private function classification(string $group, string $code): TagClassification
    {
        if ($group === TagGroup::Cuisine->value) {
            return TagClassification::Cuisine;
        }

        if ($group !== TagGroup::DietaryClaim->value) {
            return TagClassification::Descriptive;
        }

        return match ($code) {
            'vegetarian', 'vegan', 'pescatarian', 'halal', 'kosher' => TagClassification::DietStyle,
            'gluten-free', 'dairy-free', 'lactose-free', 'egg-free', 'peanut-free', 'tree-nut-free',
            'sesame-free', 'soy-free', 'fish-free', 'shellfish-free' => TagClassification::IngredientAllergen,
            default => TagClassification::NutritionClaim,
        };
    }

    private function availableSlug(string $name, string $code, string $locale): string
    {
        $base = Str::slug($name) ?: $code;
        $slug = $base;
        $suffix = 1;

        while (TagTranslation::query()->where('locale', $locale)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$code.'-'.$suffix++;
        }

        return $slug;
    }
}
