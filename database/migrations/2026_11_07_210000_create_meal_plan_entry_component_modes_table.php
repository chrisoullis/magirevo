<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nested leaf rows belong to the source recipe, while the entry belongs to its parent.
        // Keep the row/recipe foreign key; entry identity no longer implies the leaf recipe.
        Schema::table('meal_plan_entry_substitutions', function (Blueprint $table) {
            $table->dropForeign('meal_plan_entry_substitutions_entry_recipe_foreign');
            $table->foreign('meal_plan_entry_id', 'entry_substitution_entry_foreign')->references('id')->on('meal_plan_entries')->cascadeOnDelete();
        });

        Schema::create('meal_plan_entry_component_modes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_plan_entry_id')->constrained()->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('mode', 16);
            $table->timestamps();
            $table->unique(['meal_plan_entry_id', 'path'], 'entry_component_mode_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plan_entry_component_modes');
        // A populated nested confirmation cannot be represented by the old parent-only schema.
        DB::table('meal_plan_entry_substitutions')->whereExists(function ($query) {
            $query->selectRaw('1')->from('meal_plan_entries')->whereColumn('meal_plan_entries.id', 'meal_plan_entry_substitutions.meal_plan_entry_id')->whereColumn('meal_plan_entries.recipe_id', '<>', 'meal_plan_entry_substitutions.recipe_id');
        })->delete();
        Schema::table('meal_plan_entry_substitutions', function (Blueprint $table) {
            $table->dropForeign('entry_substitution_entry_foreign');
            $table->foreign(['meal_plan_entry_id', 'recipe_id'], 'meal_plan_entry_substitutions_entry_recipe_foreign')->references(['id', 'recipe_id'])->on('meal_plan_entries')->cascadeOnDelete();
        });
    }
};
