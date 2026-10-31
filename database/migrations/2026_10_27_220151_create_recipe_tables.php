<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->string('difficulty', 20)->nullable();
            $table->unsignedSmallInteger('prep_minutes')->default(0);
            $table->unsignedSmallInteger('cook_minutes')->default(0);
            $table->unsignedSmallInteger('rest_minutes')->default(0);
            $table->decimal('base_servings', 8, 2)->default(2);
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('difficulty')->references('code')->on('difficulties')->restrictOnDelete()->cascadeOnUpdate();
            $table->index(['status', 'published_at']);
        });

        Schema::create('recipe_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('title', 255);
            $table->string('slug', 255);
            $table->text('summary')->nullable();
            $table->text('introduction')->nullable();
            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamps();
            $table->unique(['recipe_id', 'locale']);
            $table->unique(['locale', 'slug']);
        });

        Schema::create('recipe_favorites', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'recipe_id']);
            $table->index('recipe_id');
        });

        Schema::create('recipe_category', function (Blueprint $table): void {
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['recipe_id', 'category_id']);
            $table->index('category_id');
        });

        Schema::create('recipe_tag', function (Blueprint $table): void {
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['recipe_id', 'tag_id']);
            $table->index('tag_id');
        });

        Schema::create('ingredient_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            // Lets recipe_ingredients reference (recipe_id, id) so a group cannot belong to another recipe.
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

            $table->foreign('scaling_mode')->references('code')->on('scaling_modes')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign(['recipe_id', 'ingredient_group_id'], 'recipe_ingredients_recipe_group_foreign')
                ->references(['recipe_id', 'id'])
                ->on('ingredient_groups')
                ->restrictOnDelete();
            $table->index(['recipe_id', 'sort_order']);
            $table->index(['ingredient_id', 'recipe_id']);
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

        Schema::create('recipe_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('step_number');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->timestamps();
            $table->unique(['recipe_id', 'step_number']);
        });

        Schema::create('recipe_step_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_step_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->text('instruction');
            $table->timestamps();
            $table->unique(['recipe_step_id', 'locale']);
        });

        foreach (['recipe_translations', 'ingredient_group_translations', 'recipe_ingredient_translations', 'recipe_step_translations'] as $translations) {
            DB::statement("ALTER TABLE {$translations} ADD CONSTRAINT {$translations}_locale_check CHECK (locale IN ('en', 'el'))");
        }

        DB::statement("ALTER TABLE recipe_translations ADD CONSTRAINT recipe_translations_slug_format_check CHECK (slug = lower(slug) AND slug ~ '^[^\\s/?#]+$')");
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_base_servings_positive_check CHECK (base_servings > 0)');
        DB::statement("ALTER TABLE recipes ADD CONSTRAINT recipes_status_valid_check CHECK (status IN ('draft', 'pending', 'published', 'archived'))");
        DB::statement("ALTER TABLE recipes ADD CONSTRAINT recipes_published_at_required_check CHECK (status <> 'published' OR published_at IS NOT NULL)");
        DB::statement('ALTER TABLE recipe_ingredients ADD CONSTRAINT recipe_ingredients_quantity_nonnegative_check CHECK (quantity IS NULL OR quantity >= 0)');
        DB::statement('ALTER TABLE recipe_ingredients ADD CONSTRAINT recipe_ingredients_quantity_max_valid_check CHECK (quantity_max IS NULL OR (quantity IS NOT NULL AND quantity_max >= quantity))');
        DB::statement('ALTER TABLE recipe_steps ADD CONSTRAINT recipe_steps_step_number_positive_check CHECK (step_number > 0)');
        DB::statement('ALTER TABLE recipe_steps ADD CONSTRAINT recipe_steps_duration_positive_check CHECK (duration_minutes IS NULL OR duration_minutes > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_step_translations');
        Schema::dropIfExists('recipe_steps');
        Schema::dropIfExists('recipe_ingredient_translations');
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('ingredient_group_translations');
        Schema::dropIfExists('ingredient_groups');
        Schema::dropIfExists('recipe_tag');
        Schema::dropIfExists('recipe_category');
        Schema::dropIfExists('recipe_favorites');
        Schema::dropIfExists('recipe_translations');
        Schema::dropIfExists('recipes');
    }
};
