<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LOOKUPS = [
        'categories' => ['category_id', 150],
        'tags' => ['tag_id', 150],
        'difficulties' => ['difficulty_id', 100],
        'scaling_modes' => ['scaling_mode_id', 100],
    ];

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

        foreach (self::LOOKUPS as $table => [$foreignKey, $nameLength]) {
            $singular = substr($foreignKey, 0, -3);
            $hasSlug = in_array($table, ['categories', 'tags'], true);

            Schema::create($table, function (Blueprint $blueprint) use ($hasSlug): void {
                $blueprint->id();
                $blueprint->string('code', $hasSlug ? 100 : 20)->unique();
                $blueprint->boolean('is_active')->default(true);
                $blueprint->integer('sort_order')->default(0);
                $blueprint->timestamps();
            });

            Schema::create("{$singular}_translations", function (Blueprint $blueprint) use ($table, $foreignKey, $nameLength, $hasSlug): void {
                $blueprint->id();
                $blueprint->foreignId($foreignKey)->constrained($table)->cascadeOnDelete();
                $blueprint->string('locale', 5);
                $blueprint->string('name', $nameLength);

                if ($hasSlug) {
                    $blueprint->string('slug', 180);
                }

                $blueprint->text('description')->nullable();
                $blueprint->timestamps();
                $blueprint->unique([$foreignKey, 'locale']);

                if ($hasSlug) {
                    $blueprint->unique(['locale', 'slug']);
                }
            });
        }

        foreach (['unit_translations', 'ingredient_translations', 'category_translations', 'tag_translations', 'difficulty_translations', 'scaling_mode_translations'] as $translations) {
            DB::statement("ALTER TABLE {$translations} ADD CONSTRAINT {$translations}_locale_check CHECK (locale IN ('en', 'el'))");
        }

        foreach (['category_translations', 'tag_translations'] as $translations) {
            DB::statement("ALTER TABLE {$translations} ADD CONSTRAINT {$translations}_slug_format_check CHECK (slug = lower(slug) AND slug ~ '^[^\\s/?#]+$')");
        }

        foreach (['unit_translations', 'ingredient_translations'] as $translations) {
            DB::statement("CREATE UNIQUE INDEX {$translations}_locale_name_unique ON {$translations} (locale, lower(name))");
        }

        DB::statement("ALTER TABLE units ADD CONSTRAINT units_unit_type_check CHECK (unit_type IN ('mass', 'volume', 'count'))");
        DB::statement('ALTER TABLE units ADD CONSTRAINT units_base_conversion_positive_check CHECK (base_conversion IS NULL OR base_conversion > 0)');
    }

    public function down(): void
    {
        foreach (['scaling_mode', 'difficulty', 'tag', 'category'] as $singular) {
            Schema::dropIfExists("{$singular}_translations");
        }

        foreach (array_keys(self::LOOKUPS) as $table) {
            Schema::dropIfExists($table);
        }

        Schema::dropIfExists('ingredient_translations');
        Schema::dropIfExists('ingredients');
        Schema::dropIfExists('unit_translations');
        Schema::dropIfExists('units');
    }
};
