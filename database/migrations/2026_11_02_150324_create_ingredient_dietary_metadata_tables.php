<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_dietary_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('composition_status', 20)->default('unknown');
            $table->string('origin_classification', 30)->default('unknown');
            $table->jsonb('evidence')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ingredient_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_ingredient_id')->nullable()->constrained('ingredients')->restrictOnDelete();
            $table->text('unresolved_component')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->timestamps();
            $table->index(['ingredient_id', 'component_ingredient_id']);
        });

        Schema::create('ingredient_dietary_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->restrictOnDelete();
            $table->string('outcome', 20)->default('unknown');
            $table->string('verification_method', 40)->nullable();
            $table->jsonb('evidence')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedSmallInteger('rule_version')->default(1);
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->unique(['ingredient_id', 'tag_id']);
            $table->index(['tag_id', 'outcome']);
        });

        Schema::create('ingredient_allergen_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('allergen_code', 50);
            $table->string('presence', 20)->default('unknown');
            $table->string('verification_method', 40)->nullable();
            $table->jsonb('evidence')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->unique(['ingredient_id', 'allergen_code']);
            $table->index(['allergen_code', 'presence']);
        });

        DB::statement("ALTER TABLE ingredient_dietary_profiles ADD CONSTRAINT ingredient_dietary_profiles_composition_status_check CHECK (composition_status IN ('complete', 'incomplete', 'unknown'))");
        DB::statement("ALTER TABLE ingredient_dietary_profiles ADD CONSTRAINT ingredient_dietary_profiles_origin_classification_check CHECK (origin_classification IN ('animal_flesh', 'animal_derived_other', 'not_animal_derived', 'unknown'))");
        DB::statement("ALTER TABLE ingredient_components ADD CONSTRAINT ingredient_components_resolution_check CHECK ((component_ingredient_id IS NOT NULL AND unresolved_component IS NULL) OR (component_ingredient_id IS NULL AND NULLIF(BTRIM(unresolved_component), '') IS NOT NULL))");
        DB::statement('ALTER TABLE ingredient_components ADD CONSTRAINT ingredient_components_not_self_check CHECK (component_ingredient_id IS NULL OR component_ingredient_id <> ingredient_id)');
        DB::statement("ALTER TABLE ingredient_dietary_assessments ADD CONSTRAINT ingredient_dietary_assessments_outcome_check CHECK (outcome IN ('compatible', 'incompatible', 'unknown'))");
        DB::statement("ALTER TABLE ingredient_allergen_assessments ADD CONSTRAINT ingredient_allergen_assessments_presence_check CHECK (presence IN ('contains', 'may_contain', 'not_present', 'unknown'))");
        DB::statement("ALTER TABLE ingredient_allergen_assessments ADD CONSTRAINT ingredient_allergen_assessments_code_check CHECK (allergen_code IN ('celery', 'cereals_containing_gluten', 'crustaceans', 'eggs', 'fish', 'lupin', 'milk', 'molluscs', 'mustard', 'peanuts', 'sesame', 'soybeans', 'sulphites', 'tree_nuts'))");
        DB::statement('ALTER TABLE ingredient_dietary_assessments ADD CONSTRAINT ingredient_dietary_assessments_rule_version_positive_check CHECK (rule_version > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_allergen_assessments');
        Schema::dropIfExists('ingredient_dietary_assessments');
        Schema::dropIfExists('ingredient_components');
        Schema::dropIfExists('ingredient_dietary_profiles');
    }
};
