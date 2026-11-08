<?php

namespace App\Console\Commands;

use App\Models\Ingredient;
use App\Models\IngredientCatalogGroup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('ingredients:audit-catalogue {--json : Output aggregate counts as JSON}')]
#[Description('Report missing ingredient catalogue content without changing records')]
class AuditIngredientCatalogue extends Command
{
    public function handle(): int
    {
        $seed = require database_path('seeders/data/ingredient_catalog.php');
        $codes = array_column($seed['ingredients'], 'code');
        $report = [
            'seed_ingredients' => count($codes), 'seed_groups' => count($seed['groups']),
            'database_ingredients' => Ingredient::query()->count(), 'database_groups' => IngredientCatalogGroup::query()->count(),
            'missing_seed_codes' => count($codes) - Ingredient::query()->whereIn('code', $codes)->count(),
            'without_public_primary_photo' => Ingredient::query()->whereDoesntHave('publicPrimaryImage')->count(),
        ];
        foreach (['en', 'el'] as $locale) {
            $query = DB::table('ingredients')->leftJoin('ingredient_translations as texts', fn ($join) => $join->on('texts.ingredient_id', '=', 'ingredients.id')->where('texts.locale', $locale));
            foreach (['name', 'slug', 'description', 'culinary_uses', 'preparation_tips', 'storage_tips'] as $field) {
                $query->selectRaw("COUNT(CASE WHEN texts.{$field} IS NULL OR btrim(texts.{$field}) = '' THEN 1 END) AS missing_{$field}");
            }
            $report[$locale] = (array) $query->first();
        }
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            foreach ($report as $key => $value) {
                $this->line($key.': '.(is_array($value) ? json_encode($value) : $value));
            }
        }

        return self::SUCCESS;
    }
}
