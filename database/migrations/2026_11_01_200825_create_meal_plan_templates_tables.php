<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plan_templates', function (Blueprint $table): void {
            $table->id();
            // Null household means a personal template owned by created_by.
            $table->foreignId('household_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['household_id', 'created_by']);
        });

        Schema::create('meal_plan_template_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meal_plan_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            // ISO weekday: 1 = Monday ... 7 = Sunday, so a template fits any week.
            $table->unsignedSmallInteger('day_of_week');
            $table->string('meal_type', 20);
            $table->unsignedSmallInteger('servings');
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->index('meal_plan_template_id');
            $table->index('recipe_id');
        });

        DB::statement('ALTER TABLE meal_plan_template_entries ADD CONSTRAINT meal_plan_template_entries_day_check CHECK (day_of_week BETWEEN 1 AND 7)');
        DB::statement("ALTER TABLE meal_plan_template_entries ADD CONSTRAINT meal_plan_template_entries_meal_type_check CHECK (meal_type IN ('breakfast', 'lunch', 'dinner', 'snack'))");
        DB::statement('ALTER TABLE meal_plan_template_entries ADD CONSTRAINT meal_plan_template_entries_servings_check CHECK (servings BETWEEN 1 AND 99)');
        DB::statement('CREATE UNIQUE INDEX meal_plan_template_entries_slot_unique ON meal_plan_template_entries (meal_plan_template_id, recipe_id, day_of_week, meal_type)');
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plan_template_entries');
        Schema::dropIfExists('meal_plan_templates');
    }
};
