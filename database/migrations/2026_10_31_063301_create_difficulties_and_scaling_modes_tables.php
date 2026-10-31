<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('difficulties', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('difficulty_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('difficulty_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['difficulty_id', 'locale']);
        });

        Schema::create('scaling_modes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('scaling_mode_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scaling_mode_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['scaling_mode_id', 'locale']);
        });

        DB::statement("ALTER TABLE difficulty_translations ADD CONSTRAINT difficulty_translations_locale_check CHECK (locale IN ('en', 'el'))");
        DB::statement("ALTER TABLE scaling_mode_translations ADD CONSTRAINT scaling_mode_translations_locale_check CHECK (locale IN ('en', 'el'))");

        // Rows must exist before the foreign keys so existing recipes keep valid values.
        $now = now();
        $difficulties = collect(['Easy', 'Medium', 'A little project'])
            ->merge(DB::table('recipes')->whereNotNull('difficulty')->distinct()->pluck('difficulty'))
            ->unique()
            ->values()
            ->map(fn (string $code, int $index): array => [
                'code' => $code,
                'sort_order' => ($index + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        DB::table('difficulties')->insert($difficulties);
        DB::table('scaling_modes')->insert(collect(['linear', 'fixed', 'manual'])
            ->map(fn (string $code, int $index): array => [
                'code' => $code,
                'sort_order' => ($index + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all());

        DB::statement('ALTER TABLE recipe_ingredients DROP CONSTRAINT recipe_ingredients_scaling_mode_check');

        Schema::table('recipes', function (Blueprint $table): void {
            $table->foreign('difficulty')->references('code')->on('difficulties')->restrictOnDelete()->cascadeOnUpdate();
        });

        Schema::table('recipe_ingredients', function (Blueprint $table): void {
            $table->foreign('scaling_mode')->references('code')->on('scaling_modes')->restrictOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('recipe_ingredients', function (Blueprint $table): void {
            $table->dropForeign(['scaling_mode']);
        });

        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropForeign(['difficulty']);
        });

        DB::table('recipe_ingredients')->whereNotIn('scaling_mode', ['linear', 'fixed', 'manual'])->update(['scaling_mode' => 'linear']);
        DB::statement("ALTER TABLE recipe_ingredients ADD CONSTRAINT recipe_ingredients_scaling_mode_check CHECK (scaling_mode IN ('linear', 'fixed', 'manual'))");

        Schema::dropIfExists('scaling_mode_translations');
        Schema::dropIfExists('scaling_modes');
        Schema::dropIfExists('difficulty_translations');
        Schema::dropIfExists('difficulties');
    }
};
