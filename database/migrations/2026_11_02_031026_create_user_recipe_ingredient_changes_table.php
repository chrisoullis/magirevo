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
        Schema::table('recipe_ingredients', function (Blueprint $table): void {
            $table->unique(['id', 'recipe_id'], 'recipe_ingredients_id_recipe_id_unique');
        });

        Schema::create('user_recipe_ingredient_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('recipe_ingredient_id');
            $table->foreignId('alternative_ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'recipe_ingredient_id']);
            $table->index(['user_id', 'recipe_id']);
            $table->foreign(['recipe_ingredient_id', 'recipe_id'], 'user_recipe_ingredient_changes_line_recipe_foreign')
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
        Schema::dropIfExists('user_recipe_ingredient_changes');

        Schema::table('recipe_ingredients', function (Blueprint $table): void {
            $table->dropUnique('recipe_ingredients_id_recipe_id_unique');
        });
    }
};
