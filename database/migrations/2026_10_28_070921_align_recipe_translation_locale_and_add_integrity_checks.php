<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE recipe_translations ALTER COLUMN locale TYPE VARCHAR(5)');

        DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_base_servings_positive_check CHECK (base_servings > 0)');
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_prep_minutes_nonnegative_check CHECK (prep_minutes >= 0)');
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_cook_minutes_nonnegative_check CHECK (cook_minutes >= 0)');
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_rest_minutes_nonnegative_check CHECK (rest_minutes >= 0)');
        DB::statement("ALTER TABLE recipes ADD CONSTRAINT recipes_status_valid_check CHECK (status IN ('draft', 'pending', 'published', 'archived'))");
        DB::statement("ALTER TABLE recipes ADD CONSTRAINT recipes_published_at_required_check CHECK (status <> 'published' OR published_at IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE recipes DROP CONSTRAINT recipes_published_at_required_check');
        DB::statement('ALTER TABLE recipes DROP CONSTRAINT recipes_status_valid_check');
        DB::statement('ALTER TABLE recipes DROP CONSTRAINT recipes_rest_minutes_nonnegative_check');
        DB::statement('ALTER TABLE recipes DROP CONSTRAINT recipes_cook_minutes_nonnegative_check');
        DB::statement('ALTER TABLE recipes DROP CONSTRAINT recipes_prep_minutes_nonnegative_check');
        DB::statement('ALTER TABLE recipes DROP CONSTRAINT recipes_base_servings_positive_check');

        DB::statement('ALTER TABLE recipe_translations ALTER COLUMN locale TYPE VARCHAR(255)');
    }
};
