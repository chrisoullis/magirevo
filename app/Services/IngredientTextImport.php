<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientCatalogGroup;
use App\Models\IngredientTranslation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

class IngredientTextImport
{
    /** @return array<string, mixed> */
    public function preview(User $actor, string $json): array
    {
        Gate::forUser($actor)->authorize('create', Ingredient::class);
        Validator::make(['payload' => $json], ['payload' => ['required', 'string', 'max:200000']])->validate();
        try {
            $rows = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['payload' => 'Enter a valid JSON array of ingredient records.']);
        }
        Validator::make(['rows' => $rows], [
            'rows' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'rows.*' => ['required', 'array:code,group_code,is_active,en,el'],
            'rows.*.code' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'distinct:strict'],
            'rows.*.group_code' => ['required', 'string', 'exists:ingredient_catalog_groups,code'],
            'rows.*.is_active' => ['sometimes', 'boolean'],
            'rows.*.en' => ['required', 'array:name,plural_name,description,culinary_uses,preparation_tips,storage_tips'],
            'rows.*.el' => ['required', 'array:name,plural_name,description,culinary_uses,preparation_tips,storage_tips'],
            'rows.*.en.name' => ['required', 'string', 'max:255'],
            'rows.*.el.name' => ['required', 'string', 'max:255'],
        ])->validate();
        $state = [];
        $changes = [];
        $seen = [];
        foreach ($rows as $row) {
            $ingredient = Ingredient::query()->with('translations')->where('code', $row['code'])->first();
            if ($ingredient !== null) {
                Gate::forUser($actor)->authorize('update', $ingredient);
            }
            $group = IngredientCatalogGroup::query()->where('code', $row['group_code'])->firstOrFail();
            $state[] = [$ingredient?->getAttributes(), $ingredient?->translations->map->getAttributes()->all(), $group->getAttributes()];
            $diff = [];
            if ($ingredient?->catalog_group_id !== $group->id) {
                $diff['group_code'] = ['from' => $ingredient?->catalogGroup?->code, 'to' => $group->code];
            }
            if (array_key_exists('is_active', $row) && $ingredient?->is_active !== (bool) $row['is_active']) {
                $diff['is_active'] = ['from' => $ingredient?->is_active, 'to' => (bool) $row['is_active']];
            }
            foreach (['en', 'el'] as $locale) {
                foreach ($row[$locale] as $field => $value) {
                    Validator::make(['value' => $value], ['value' => ['nullable', 'string', 'max:'.(in_array($field, ['name', 'plural_name'], true) ? '255' : '10000')]])->validate();
                    $previous = $ingredient?->getTranslation($locale, false)?->getAttribute($field);
                    if ($previous !== $value) {
                        $diff[$locale.'.'.$field] = ['from' => $previous, 'to' => $value];
                    }
                }
                $key = $locale.':'.mb_strtolower($row[$locale]['name']);
                if (isset($seen[$key]) || IngredientTranslation::query()->where('locale', $locale)->whereRaw('lower(name) = lower(?)', [$row[$locale]['name']])
                    ->whereHas('ingredient', fn ($q) => $q->where('code', '!=', $row['code']))->exists()) {
                    throw ValidationException::withMessages(['payload' => "Duplicate ingredient name for [{$row['code']}] in {$locale}. No merge is performed."]);
                }
                $seen[$key] = true;
                $slug = $ingredient?->getTranslation($locale, false)?->slug ?? $this->newSlug($row['code'], $locale);
                if (IngredientTranslation::query()->where('locale', $locale)->where('slug', $slug)->whereHas('ingredient', fn ($q) => $q->where('code', '!=', $row['code']))->exists()) {
                    throw ValidationException::withMessages(['payload' => "Slug conflict for [{$row['code']}]. Review manually."]);
                }
            }
            $changes[] = ['code' => $row['code'], 'action' => $ingredient === null ? 'create' : ($diff === [] ? 'unchanged' : 'update'), 'changes' => $diff];
        }

        return ['rows' => $rows, 'changes' => $changes, 'token' => hash_hmac('sha256', json_encode([$rows, $state]), (string) config('app.key'))];
    }

    public function apply(User $actor, string $json, string $token): int
    {
        $preview = $this->preview($actor, $json);

        return DB::transaction(function () use ($actor, $json, $token, $preview): int {
            Ingredient::query()->whereIn('code', array_column($preview['rows'], 'code'))->orderBy('id')->lockForUpdate()->get();
            $current = $this->preview($actor, $json);
            if (! hash_equals($current['token'], $token)) {
                throw ValidationException::withMessages(['payload' => 'The input or existing records changed. Preview the import again before applying it.']);
            }
            foreach ($current['rows'] as $row) {
                $groupId = IngredientCatalogGroup::query()->where('code', $row['group_code'])->value('id');
                $ingredient = Ingredient::query()->firstOrCreate(['code' => $row['code']], ['catalog_group_id' => $groupId, 'is_active' => true]);
                $expected = collect($current['changes'])->firstWhere('code', $row['code']);
                if ($expected['action'] === 'create' && ! $ingredient->wasRecentlyCreated) {
                    throw ValidationException::withMessages(['payload' => 'An ingredient was created concurrently. Preview the import again.']);
                }
                $ingredient->fill(['catalog_group_id' => $groupId, ...(array_key_exists('is_active', $row) ? ['is_active' => (bool) $row['is_active']] : [])])->save();
                foreach (['en', 'el'] as $locale) {
                    $fields = $row[$locale];
                    if ($ingredient->getTranslation($locale, false)?->slug === null) {
                        $fields['slug'] = $this->newSlug($row['code'], $locale);
                    }
                    $ingredient->translations()->updateOrCreate(['locale' => $locale], $fields);
                }
            }

            return count($current['rows']);
        });
    }

    private function newSlug(string $code, string $locale): string
    {
        return $locale === 'en' ? $code : $code.'-el';
    }
}
