<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('status', 20)->default('draft');
            $table->jsonb('payload');
            $table->jsonb('resolved_ids')->nullable();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->char('content_hash', 64);
            $table->foreignId('base_revision_id')->nullable()->constrained('recipe_revisions')->nullOnDelete();
            $table->foreignId('restored_from_revision_id')->nullable()->constrained('recipe_revisions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['recipe_id', 'revision_number']);
            $table->index(['recipe_id', 'status']);
        });

        DB::statement("ALTER TABLE recipe_revisions ADD CONSTRAINT recipe_revisions_status_check CHECK (status IN ('draft', 'in_review', 'rejected', 'published', 'superseded'))");
        DB::statement("CREATE UNIQUE INDEX recipe_revisions_one_published_per_recipe ON recipe_revisions (recipe_id) WHERE status = 'published'");
        DB::statement("CREATE UNIQUE INDEX recipe_revisions_one_open_per_recipe ON recipe_revisions (recipe_id) WHERE status IN ('draft', 'in_review')");

        Schema::create('recipe_revision_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_revision_id')->nullable()->constrained('recipe_revisions')->nullOnDelete();
            $table->unsignedBigInteger('recipe_id');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('note')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['recipe_id', 'created_at']);
            $table->index(['recipe_revision_id', 'created_at']);
        });

        // Media referenced by a retained revision cannot be deleted while that revision exists.
        Schema::create('recipe_revision_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_revision_id')->constrained('recipe_revisions')->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->restrictOnDelete();
            $table->string('usage', 20);
            $table->timestamps();

            $table->unique(['recipe_revision_id', 'media_id', 'usage']);
            $table->index('media_id');
        });

        Schema::create('recipe_revision_settings', function (Blueprint $table): void {
            $table->string('key', 50)->primary();
            $table->string('value', 50);
            $table->timestamps();
        });

        DB::table('recipe_revision_settings')->insert([
            'key' => 'content_guard',
            'value' => 'monitor',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Personal data that depended on an ingredient line which was removed or replaced. Kept, never silently dropped.
        Schema::create('archived_personal_ingredient_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('original_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('recipe_id');
            $table->unsignedBigInteger('recipe_ingredient_id');
            $table->unsignedBigInteger('alternative_ingredient_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('source_recipe_revision_id')->nullable();
            $table->unsignedBigInteger('retired_in_revision_id')->nullable();
            $table->string('reason', 40)->default('line_removed');
            $table->timestamp('original_created_at')->nullable();
            $table->timestamp('archived_at')->useCurrent();

            $table->index(['user_id', 'recipe_id']);
        });

        Schema::create('archived_meal_plan_entry_substitutions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('original_id');
            $table->unsignedBigInteger('meal_plan_entry_id');
            $table->unsignedBigInteger('recipe_id');
            $table->unsignedBigInteger('recipe_ingredient_id');
            $table->unsignedBigInteger('ingredient_substitution_id')->nullable();
            $table->unsignedBigInteger('replacement_ingredient_id')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('retired_in_revision_id')->nullable();
            $table->string('reason', 40)->default('line_removed');
            $table->timestamp('archived_at')->useCurrent();

            $table->index(['meal_plan_entry_id', 'recipe_id']);
        });

        // The permanent link from a variation to the recipe and exact revision it was derived from.
        Schema::create('recipe_derivations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('source_recipe_id')->constrained('recipes')->restrictOnDelete();
            $table->foreignId('source_recipe_revision_id')->nullable()->constrained('recipe_revisions')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('source_recipe_id');
        });

        $this->installRevisionImmutability();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS recipe_revision_audits_immutability ON recipe_revision_audits');
        DB::unprepared('DROP FUNCTION IF EXISTS recipe_revision_audits_append_only()');
        DB::unprepared('DROP TRIGGER IF EXISTS recipe_revisions_immutability ON recipe_revisions');
        DB::unprepared('DROP FUNCTION IF EXISTS recipe_revisions_enforce_immutability()');

        Schema::dropIfExists('recipe_derivations');
        Schema::dropIfExists('archived_meal_plan_entry_substitutions');
        Schema::dropIfExists('archived_personal_ingredient_changes');
        Schema::dropIfExists('recipe_revision_settings');
        Schema::dropIfExists('recipe_revision_media');
        Schema::dropIfExists('recipe_revision_audits');
        Schema::dropIfExists('recipe_revisions');
    }

    /**
     * Content freezes once a revision leaves draft, history cannot be deleted and status only moves forward.
     * Deleting the whole recipe (a cascade) is the only way retained revisions disappear.
     */
    private function installRevisionImmutability(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION recipe_revisions_enforce_immutability() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status <> 'draft' AND EXISTS (SELECT 1 FROM recipes WHERE id = OLD.recipe_id) THEN
            RAISE EXCEPTION 'Recipe revision % is retained history and cannot be deleted.', OLD.id USING ERRCODE = 'integrity_constraint_violation';
        END IF;

        RETURN OLD;
    END IF;

    IF NEW.recipe_id <> OLD.recipe_id OR NEW.revision_number <> OLD.revision_number THEN
        RAISE EXCEPTION 'Recipe revision identity is immutable.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;

    IF OLD.status <> 'draft' AND (NEW.payload IS DISTINCT FROM OLD.payload OR NEW.content_hash <> OLD.content_hash OR NEW.schema_version <> OLD.schema_version) THEN
        RAISE EXCEPTION 'Recipe revision % content is frozen after it leaves draft.', OLD.id USING ERRCODE = 'integrity_constraint_violation';
    END IF;

    IF NEW.status <> OLD.status AND NOT (
        (OLD.status = 'draft' AND NEW.status IN ('in_review', 'published'))
        OR (OLD.status = 'in_review' AND NEW.status IN ('published', 'rejected'))
        OR (OLD.status = 'published' AND NEW.status = 'superseded')
    ) THEN
        RAISE EXCEPTION 'Recipe revision cannot move from % to %.', OLD.status, NEW.status USING ERRCODE = 'integrity_constraint_violation';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared('CREATE TRIGGER recipe_revisions_immutability BEFORE UPDATE OR DELETE ON recipe_revisions FOR EACH ROW EXECUTE FUNCTION recipe_revisions_enforce_immutability()');

        // The audit trail is append-only. The only tolerated change is the revision link being nulled when the whole recipe is removed.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION recipe_revision_audits_append_only() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF EXISTS (SELECT 1 FROM recipes WHERE id = OLD.recipe_id) THEN
            RAISE EXCEPTION 'Recipe revision audit records cannot be deleted.' USING ERRCODE = 'integrity_constraint_violation';
        END IF;

        RETURN OLD;
    END IF;

    IF to_jsonb(NEW) - 'recipe_revision_id' IS DISTINCT FROM to_jsonb(OLD) - 'recipe_revision_id' OR NEW.recipe_revision_id IS NOT NULL THEN
        RAISE EXCEPTION 'Recipe revision audit records cannot be changed.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared('CREATE TRIGGER recipe_revision_audits_immutability BEFORE UPDATE OR DELETE ON recipe_revision_audits FOR EACH ROW EXECUTE FUNCTION recipe_revision_audits_append_only()');
    }
};
