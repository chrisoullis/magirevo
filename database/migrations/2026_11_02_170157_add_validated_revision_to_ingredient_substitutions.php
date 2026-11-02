<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A recipe-specific substitution is approved against one recipe revision and only applies while that revision is
     * the current one. Rows are never deleted or re-pointed automatically, so history stays intact.
     */
    public function up(): void
    {
        Schema::table('ingredient_substitutions', function (Blueprint $table): void {
            $table->foreignId('validated_revision_id')->nullable()->constrained('recipe_revisions')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
        });

        // Existing approved recipe-specific rows were approved against what is live now, so they start validated for it.
        DB::statement("UPDATE ingredient_substitutions s SET validated_revision_id = r.current_revision_id, validated_at = NOW() FROM recipes r WHERE s.recipe_id = r.id AND s.status = 'approved' AND r.current_revision_id IS NOT NULL AND s.validated_revision_id IS NULL");
    }

    public function down(): void
    {
        Schema::table('ingredient_substitutions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('validated_revision_id');
            $table->dropColumn('validated_at');
        });
    }
};
