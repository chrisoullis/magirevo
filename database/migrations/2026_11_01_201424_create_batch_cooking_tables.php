<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_cooks', function (Blueprint $table): void {
            $table->id();
            // Null household means a personal batch owned by created_by.
            $table->foreignId('household_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            // The planned cooking meal whose ingredients already cover every prepared portion.
            $table->foreignId('source_entry_id')->nullable()->constrained('meal_plan_entries')->nullOnDelete();
            $table->date('cooked_on');
            $table->unsignedSmallInteger('prepared_portions');
            // Earmarked for scheduled leftover meals, not yet eaten.
            $table->unsignedSmallInteger('reserved_portions')->default(0);
            $table->unsignedSmallInteger('consumed_portions')->default(0);
            $table->string('notes', 255)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['household_id', 'created_by']);
            $table->index('recipe_id');
        });

        DB::statement('ALTER TABLE batch_cooks ADD CONSTRAINT batch_cooks_prepared_check CHECK (prepared_portions BETWEEN 1 AND 200)');
        // The core ledger invariant: nothing can be reserved or eaten beyond what was cooked.
        DB::statement('ALTER TABLE batch_cooks ADD CONSTRAINT batch_cooks_balance_check CHECK (reserved_portions + consumed_portions <= prepared_portions)');
        DB::statement('CREATE UNIQUE INDEX batch_cooks_source_entry_unique ON batch_cooks (source_entry_id) WHERE source_entry_id IS NOT NULL');

        Schema::create('batch_cook_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_cook_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedSmallInteger('portions');
            // A consumption that draws on reserved portions (a scheduled leftover) rather than free ones.
            $table->boolean('from_reserved')->default(false);
            $table->foreignId('meal_plan_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['batch_cook_id', 'idempotency_key']);
        });

        DB::statement("ALTER TABLE batch_cook_movements ADD CONSTRAINT batch_cook_movements_type_check CHECK (type IN ('prepared', 'reserved', 'released', 'consumed'))");
        DB::statement('ALTER TABLE batch_cook_movements ADD CONSTRAINT batch_cook_movements_portions_check CHECK (portions >= 1)');
        DB::statement("ALTER TABLE batch_cook_movements ADD CONSTRAINT batch_cook_movements_reserved_check CHECK (type = 'consumed' OR NOT from_reserved)");

        Schema::table('meal_plan_entries', function (Blueprint $table): void {
            // A leftover meal draws on a batch; its ingredients are already counted by the batch's cooking meal.
            $table->foreignId('batch_cook_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('consumed_at')->nullable();
            $table->index('batch_cook_id');
        });

        DB::statement('ALTER TABLE meal_plan_entries ADD CONSTRAINT meal_plan_entries_consumed_check CHECK (consumed_at IS NULL OR batch_cook_id IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE meal_plan_entries DROP CONSTRAINT IF EXISTS meal_plan_entries_consumed_check');

        Schema::table('meal_plan_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('batch_cook_id');
            $table->dropColumn('consumed_at');
        });

        Schema::dropIfExists('batch_cook_movements');
        Schema::dropIfExists('batch_cooks');
    }
};
