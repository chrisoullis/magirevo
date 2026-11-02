<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ingredient_substitutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->foreignId('replacement_ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->foreignId('recipe_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index(['status', 'recipe_id', 'ingredient_id']);
        });

        Schema::create('ingredient_substitution_translations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ingredient_substitution_id');
            $table->string('locale', 5);
            $table->text('explanation')->nullable();
            $table->timestamps();

            $table->foreign('ingredient_substitution_id', 'ingredient_sub_translations_sub_id_foreign')
                ->references('id')
                ->on('ingredient_substitutions')
                ->cascadeOnDelete();
            $table->unique(['ingredient_substitution_id', 'locale'], 'ingredient_sub_translations_sub_locale_unique');
        });

        DB::statement("ALTER TABLE ingredient_substitutions ADD CONSTRAINT ingredient_substitutions_status_check CHECK (status IN ('pending', 'approved', 'inactive'))");
        DB::statement('ALTER TABLE ingredient_substitutions ADD CONSTRAINT ingredient_substitutions_distinct_ingredients_check CHECK (ingredient_id <> replacement_ingredient_id)');
        DB::statement('CREATE UNIQUE INDEX ingredient_substitutions_global_unique ON ingredient_substitutions (ingredient_id, replacement_ingredient_id) WHERE recipe_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX ingredient_substitutions_recipe_unique ON ingredient_substitutions (ingredient_id, replacement_ingredient_id, recipe_id) WHERE recipe_id IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_substitution_translations');
        Schema::dropIfExists('ingredient_substitutions');
    }
};
