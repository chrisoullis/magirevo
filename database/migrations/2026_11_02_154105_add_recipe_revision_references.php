<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->foreignId('current_revision_id')->nullable()->constrained('recipe_revisions')->nullOnDelete();
        });

        Schema::table('recipe_dietary_claims', function (Blueprint $table): void {
            $table->foreignId('recipe_revision_id')->nullable()->constrained('recipe_revisions')->nullOnDelete();
        });

        Schema::table('dietary_claim_audits', function (Blueprint $table): void {
            $table->unsignedBigInteger('recipe_revision_id')->nullable();
        });

        Schema::table('meal_plan_entries', function (Blueprint $table): void {
            $table->foreignId('planned_recipe_revision_id')->nullable()->constrained('recipe_revisions')->nullOnDelete();
        });

        Schema::table('user_recipe_ingredient_changes', function (Blueprint $table): void {
            $table->foreignId('source_recipe_revision_id')->nullable()->constrained('recipe_revisions')->nullOnDelete();
        });

        // A claim belongs to one exact revision. Claims without a revision keep the old one-per-recipe-and-tag rule, so NULL cannot duplicate.
        DB::statement('ALTER TABLE recipe_dietary_claims DROP CONSTRAINT IF EXISTS recipe_dietary_claims_recipe_id_tag_id_unique');
        DB::statement('DROP INDEX IF EXISTS recipe_dietary_claims_recipe_id_tag_id_unique');
        DB::statement('CREATE UNIQUE INDEX recipe_dietary_claims_unrevised_unique ON recipe_dietary_claims (recipe_id, tag_id) WHERE recipe_revision_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX recipe_dietary_claims_revision_tag_unique ON recipe_dietary_claims (recipe_revision_id, tag_id) WHERE recipe_revision_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS recipe_dietary_claims_revision_tag_unique');
        DB::statement('DROP INDEX IF EXISTS recipe_dietary_claims_unrevised_unique');

        // Restoring the old unique index is only possible while no recipe has claims on several revisions.
        DB::statement('CREATE UNIQUE INDEX recipe_dietary_claims_recipe_id_tag_id_unique ON recipe_dietary_claims (recipe_id, tag_id)');

        Schema::table('user_recipe_ingredient_changes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_recipe_revision_id');
        });

        Schema::table('meal_plan_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('planned_recipe_revision_id');
        });

        Schema::table('dietary_claim_audits', function (Blueprint $table): void {
            $table->dropColumn('recipe_revision_id');
        });

        Schema::table('recipe_dietary_claims', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recipe_revision_id');
        });

        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_revision_id');
        });
    }
};
