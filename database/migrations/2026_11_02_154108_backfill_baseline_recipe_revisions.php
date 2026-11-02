<?php

use App\Services\RecipeRevisionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Idempotent: recipes that already have a published revision are skipped, and nothing is deleted or rewritten.
     */
    public function up(): void
    {
        $revisions = app(RecipeRevisionService::class);

        DB::table('recipes')
            ->where('status', 'published')
            ->where('visibility', 'public')
            ->whereNull('current_revision_id')
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $recipeId) => $revisions->syncPublishedFromLive($recipeId, null, 'backfill'));

        // Existing claims, plan entries and personal changes pointed at live content that is now revision 1, so they are linked to it. Nothing is re-approved.
        DB::statement('UPDATE recipe_dietary_claims c SET recipe_revision_id = r.current_revision_id FROM recipes r WHERE r.id = c.recipe_id AND c.recipe_revision_id IS NULL AND r.current_revision_id IS NOT NULL');
        DB::statement('UPDATE meal_plan_entries e SET planned_recipe_revision_id = r.current_revision_id FROM recipes r WHERE r.id = e.recipe_id AND e.planned_recipe_revision_id IS NULL AND r.current_revision_id IS NOT NULL');
        DB::statement('UPDATE user_recipe_ingredient_changes c SET source_recipe_revision_id = r.current_revision_id FROM recipes r WHERE r.id = c.recipe_id AND c.source_recipe_revision_id IS NULL AND r.current_revision_id IS NOT NULL');
    }

    /**
     * The backfill only adds links and revision rows; the table migrations own their removal.
     */
    public function down(): void
    {
        DB::table('user_recipe_ingredient_changes')->update(['source_recipe_revision_id' => null]);
        DB::table('meal_plan_entries')->update(['planned_recipe_revision_id' => null]);
        DB::table('recipe_dietary_claims')->update(['recipe_revision_id' => null]);
    }
};
