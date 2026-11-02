<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('meal_plan_entries', function (Blueprint $table): void {
            $table->unique(['id', 'recipe_id'], 'meal_plan_entries_id_recipe_id_unique');
        });

        Schema::create('meal_plan_entry_substitutions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meal_plan_entry_id');
            $table->unsignedBigInteger('recipe_id');
            $table->unsignedBigInteger('recipe_ingredient_id');
            $table->foreignId('ingredient_substitution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('replacement_ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->unique(
                ['meal_plan_entry_id', 'recipe_ingredient_id'],
                'meal_plan_entry_substitutions_entry_line_unique',
            );
            $table->foreign(['meal_plan_entry_id', 'recipe_id'], 'meal_plan_entry_substitutions_entry_recipe_foreign')
                ->references(['id', 'recipe_id'])
                ->on('meal_plan_entries')
                ->cascadeOnDelete();
            $table->foreign(['recipe_ingredient_id', 'recipe_id'], 'meal_plan_entry_substitutions_line_recipe_foreign')
                ->references(['id', 'recipe_id'])
                ->on('recipe_ingredients')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meal_plan_entry_substitutions');

        Schema::table('meal_plan_entries', function (Blueprint $table): void {
            $table->dropUnique('meal_plan_entries_id_recipe_id_unique');
        });
    }
};
