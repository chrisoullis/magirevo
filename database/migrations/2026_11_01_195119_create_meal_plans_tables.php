<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plans', function (Blueprint $table): void {
            $table->id();
            // Null household means a personal plan owned by created_by.
            $table->foreignId('household_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('week_start');
            $table->string('name', 100)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('meal_plan_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->date('planned_on');
            $table->string('meal_type', 20);
            $table->unsignedSmallInteger('servings');
            $table->string('notes', 255)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['meal_plan_id', 'planned_on', 'meal_type']);
            $table->index('recipe_id');
        });

        DB::statement("ALTER TABLE meal_plan_entries ADD CONSTRAINT meal_plan_entries_meal_type_check CHECK (meal_type IN ('breakfast', 'lunch', 'dinner', 'snack'))");
        DB::statement('ALTER TABLE meal_plan_entries ADD CONSTRAINT meal_plan_entries_servings_check CHECK (servings BETWEEN 1 AND 99)');
        // Plans always start on a Monday (ISO day 1).
        DB::statement('ALTER TABLE meal_plans ADD CONSTRAINT meal_plans_week_start_monday_check CHECK (EXTRACT(ISODOW FROM week_start) = 1)');
        // One plan per household per week, and one personal plan per person per week.
        DB::statement('CREATE UNIQUE INDEX meal_plans_household_week_unique ON meal_plans (household_id, week_start) WHERE household_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX meal_plans_personal_week_unique ON meal_plans (created_by, week_start) WHERE household_id IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plan_entries');
        Schema::dropIfExists('meal_plans');
    }
};
