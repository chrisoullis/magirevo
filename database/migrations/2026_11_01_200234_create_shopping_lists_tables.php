<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One shopping list per meal plan, so it is always personal or household-scoped exactly like its plan.
        Schema::create('shopping_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meal_plan_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('last_generated_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('shopping_list_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('custom_name', 120)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            // ingredient:measurement-family:required|optional. Null for manual items, which regeneration never touches.
            $table->string('generated_key', 80)->nullable();
            $table->boolean('is_manual')->default(false);
            $table->boolean('is_optional')->default(false);
            // A checked generated item the plan no longer needs is kept (never silently deleted) and flagged.
            $table->boolean('is_stale')->default(false);
            $table->decimal('quantity', 14, 4)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            // Amount, in base units, that was covered when the item was checked.
            $table->decimal('checked_base_quantity', 18, 4)->nullable();
            // Extra amount (in this item's unit) still to buy after a regeneration raised the requirement.
            $table->decimal('extra_quantity', 14, 4)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['shopping_list_id', 'generated_key']);
            $table->index('shopping_list_id');
        });

        DB::statement('ALTER TABLE shopping_list_items ADD CONSTRAINT shopping_list_items_subject_check CHECK (ingredient_id IS NOT NULL OR custom_name IS NOT NULL)');
        DB::statement('ALTER TABLE shopping_list_items ADD CONSTRAINT shopping_list_items_origin_check CHECK ((is_manual AND generated_key IS NULL) OR (NOT is_manual AND generated_key IS NOT NULL AND ingredient_id IS NOT NULL))');
        DB::statement('ALTER TABLE shopping_list_items ADD CONSTRAINT shopping_list_items_quantity_check CHECK (quantity IS NULL OR quantity >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_list_items');
        Schema::dropIfExists('shopping_lists');
    }
};
