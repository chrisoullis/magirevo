<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cooking records keep the recipe revision that was current when they were created. Earlier batches have no
     * recorded revision and stay NULL rather than being guessed; planned entries without one are pinned to the
     * revision they are showing today so later changes can be flagged.
     */
    public function up(): void
    {
        Schema::table('batch_cooks', function (Blueprint $table): void {
            $table->foreignId('recipe_revision_id')->nullable()->constrained('recipe_revisions')->nullOnDelete();
        });

        DB::statement('UPDATE meal_plan_entries e SET planned_recipe_revision_id = r.current_revision_id FROM recipes r WHERE e.recipe_id = r.id AND e.planned_recipe_revision_id IS NULL AND r.current_revision_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('batch_cooks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recipe_revision_id');
        });
    }
};
