<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('unit_type', 20);
            $table->decimal('base_conversion', 18, 8)->nullable();
            $table->boolean('allow_fraction')->default(true);
            $table->boolean('is_scalable')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('unit_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name', 100);
            $table->string('abbreviation', 30);
            $table->timestamps();
            $table->unique(['unit_id', 'locale']);
        });

        Schema::create('ingredients', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100)->unique();
            $table->foreignId('default_unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('default_unit_id');
        });

        Schema::create('ingredient_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name', 255);
            $table->string('plural_name', 255)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['ingredient_id', 'locale']);
        });

        Schema::create('ingredient_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['recipe_id', 'id']);
            $table->index(['recipe_id', 'sort_order']);
        });

        Schema::create('ingredient_group_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_group_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name', 150);
            $table->timestamps();
            $table->unique(['ingredient_group_id', 'locale']);
        });

        Schema::create('recipe_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->foreignId('ingredient_group_id')->nullable();
            $table->decimal('quantity', 12, 4)->nullable();
            $table->decimal('quantity_max', 12, 4)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('scaling_mode', 20)->default('linear');
            $table->boolean('is_optional')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->foreign(['recipe_id', 'ingredient_group_id'], 'recipe_ingredients_recipe_group_foreign')
                ->references(['recipe_id', 'id'])
                ->on('ingredient_groups')
                ->restrictOnDelete();
            $table->index(['recipe_id', 'sort_order']);
            $table->index('ingredient_id');
            $table->index('unit_id');
        });

        Schema::create('recipe_ingredient_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('preparation_note', 255)->nullable();
            $table->string('quantity_text', 100)->nullable();
            $table->timestamps();
            $table->unique(['recipe_ingredient_id', 'locale']);
        });

        foreach ([
            'unit_translations',
            'ingredient_translations',
            'ingredient_group_translations',
            'recipe_ingredient_translations',
        ] as $translationTable) {
            DB::statement(
                "ALTER TABLE {$translationTable} ADD CONSTRAINT {$translationTable}_locale_check CHECK (locale IN ('en', 'el'))"
            );
        }

        DB::statement("ALTER TABLE units ADD CONSTRAINT units_unit_type_check CHECK (unit_type IN ('mass', 'volume', 'count'))");
        DB::statement('ALTER TABLE units ADD CONSTRAINT units_base_conversion_positive_check CHECK (base_conversion IS NULL OR base_conversion > 0)');
        DB::statement("ALTER TABLE recipe_ingredients ADD CONSTRAINT recipe_ingredients_scaling_mode_check CHECK (scaling_mode IN ('linear', 'fixed', 'manual'))");
        DB::statement('ALTER TABLE recipe_ingredients ADD CONSTRAINT recipe_ingredients_quantity_nonnegative_check CHECK (quantity IS NULL OR quantity >= 0)');
        DB::statement('ALTER TABLE recipe_ingredients ADD CONSTRAINT recipe_ingredients_quantity_max_valid_check CHECK (quantity_max IS NULL OR (quantity IS NOT NULL AND quantity_max >= quantity))');
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_ingredient_translations');
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('ingredient_group_translations');
        Schema::dropIfExists('ingredient_groups');
        Schema::dropIfExists('ingredient_translations');
        Schema::dropIfExists('ingredients');
        Schema::dropIfExists('unit_translations');
        Schema::dropIfExists('units');
    }
};
