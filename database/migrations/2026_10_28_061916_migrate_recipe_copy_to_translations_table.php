<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_translations', function (Blueprint $table) {
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->enum('locale', ['en', 'el']);
            $table->string('title', 255);
            $table->string('slug', 255);
            $table->text('summary')->nullable();
            $table->text('introduction')->nullable();
            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->unique(['recipe_id', 'locale']);
            $table->unique(['locale', 'slug']);
        });

        DB::table('recipes')
            ->orderBy('id')
            ->chunkById(100, function ($recipes): void {
                foreach ($recipes as $recipe) {
                    DB::table('recipe_translations')->insert([
                        'recipe_id' => $recipe->id,
                        'locale' => 'en',
                        'title' => $recipe->title,
                        'slug' => $recipe->slug,
                        'summary' => $recipe->excerpt,
                        'introduction' => $recipe->description,
                        'seo_title' => $recipe->title,
                        'seo_description' => $recipe->excerpt,
                        'created_at' => $recipe->created_at,
                        'updated_at' => $recipe->updated_at,
                    ]);
                }
            });

        DB::table('recipes')->update([
            'status' => 'draft',
        ]);

        Schema::table('recipes', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['title', 'slug', 'excerpt', 'description']);
        });
    }

    /**
     * Restores the legacy columns from English; the previous schema cannot retain Greek translations.
     */
    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->string('title', 255)->nullable();
            $table->string('slug', 255)->nullable();
            $table->string('excerpt', 280)->nullable();
            $table->longText('description')->nullable();
            $table->unique('slug');
        });

        DB::table('recipe_translations')
            ->where('locale', 'en')
            ->chunkById(100, function ($translations): void {
                foreach ($translations as $translation) {
                    DB::table('recipes')
                        ->where('id', $translation->recipe_id)
                        ->update([
                            'title' => $translation->title,
                            'slug' => $translation->slug,
                            'excerpt' => $translation->summary,
                            'description' => $translation->introduction,
                        ]);
                }
            });

        DB::table('recipes')
            ->whereNotNull('published_at')
            ->update(['status' => 'published']);

        Schema::table('recipe_translations', function (Blueprint $table) {
            $table->dropForeign(['recipe_id']);
            $table->dropUnique(['recipe_id', 'locale']);
            $table->dropUnique(['locale', 'slug']);
            $table->dropColumn([
                'recipe_id',
                'locale',
                'title',
                'slug',
                'summary',
                'introduction',
                'seo_title',
                'seo_description',
            ]);
        });
    }
};
