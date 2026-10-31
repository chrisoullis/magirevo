<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEXED = [
        'recipe_translations' => ['title', 'summary'],
        'ingredient_translations' => ['name'],
        'category_translations' => ['name'],
        'tag_translations' => ['name'],
    ];

    /**
     * Trigram indexes over accent-insensitive text speed up substring search in English and Greek.
     * They need the pg_trgm and unaccent extensions; without them the site falls back to unindexed search.
     */
    public function up(): void
    {
        if (! $this->enableExtensions()) {
            return;
        }

        DB::statement('CREATE OR REPLACE FUNCTION f_unaccent(text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS $$ SELECT public.unaccent(\'public.unaccent\', $1) $$');

        foreach (self::INDEXED as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("CREATE INDEX {$table}_{$column}_search_idx ON {$table} USING gin (f_unaccent(lower({$column})) gin_trgm_ops)");
            }
        }

        DB::statement('CREATE INDEX recipe_translations_locale_recipe_id_index ON recipe_translations (locale, recipe_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS recipe_translations_locale_recipe_id_index');

        foreach (self::INDEXED as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("DROP INDEX IF EXISTS {$table}_{$column}_search_idx");
            }
        }

        DB::statement('DROP FUNCTION IF EXISTS f_unaccent(text)');
    }

    private function enableExtensions(): bool
    {
        try {
            DB::transaction(function (): void {
                DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
                DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
            });

            return true;
        } catch (Throwable) {
            return false;
        }
    }
};
