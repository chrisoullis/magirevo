<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['recipes', 'recipe_translations', 'ingredient_groups', 'ingredient_group_translations', 'recipe_ingredients', 'recipe_ingredient_translations', 'recipe_steps', 'recipe_step_translations', 'recipe_media', 'recipe_step_media', 'recipe_category', 'recipe_tag', 'recipe_derivation_translations'];

    public function up(): void
    {
        Schema::table('recipe_revisions', function (Blueprint $table): void {
            $table->jsonb('publication_context')->nullable();
            $table->char('publication_context_hash', 64)->nullable();
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE recipe_revisions ADD CONSTRAINT revision_publication_context_check CHECK (
    (publication_context IS NULL AND publication_context_hash IS NULL) OR
    (publication_context IS NOT NULL AND jsonb_typeof(publication_context) = 'object' AND publication_context_hash IS NOT NULL AND publication_context_hash ~ '^[a-f0-9]{64}$'));
CREATE OR REPLACE FUNCTION variation_submission_freeze() RETURNS trigger AS $$
DECLARE rid bigint; old_id bigint; new_id bigint; publisher text;
BEGIN
    publisher := current_setting('app.recipe_publisher', true);
    IF TG_TABLE_NAME = 'recipe_revisions' THEN
        IF TG_OP = 'UPDATE' AND OLD.status <> 'draft' AND
            (NEW.publication_context IS DISTINCT FROM OLD.publication_context OR NEW.publication_context_hash IS DISTINCT FROM OLD.publication_context_hash) THEN
            RAISE EXCEPTION 'Publication declarations are frozen with the submitted revision.' USING ERRCODE = 'integrity_constraint_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF TG_OP <> 'INSERT' THEN
        IF TG_TABLE_NAME = 'recipe_derivation_translations' THEN
            SELECT recipe_id INTO old_id FROM recipe_derivations WHERE id = OLD.recipe_derivation_id;
        ELSE old_id := recipe_content_owner(TG_TABLE_NAME, to_jsonb(OLD)); END IF;
    END IF;
    IF TG_OP <> 'DELETE' THEN
        IF TG_TABLE_NAME = 'recipe_derivation_translations' THEN
            SELECT recipe_id INTO new_id FROM recipe_derivations WHERE id = NEW.recipe_derivation_id;
        ELSE new_id := recipe_content_owner(TG_TABLE_NAME, to_jsonb(NEW)); END IF;
    END IF;
    FOREACH rid IN ARRAY ARRAY[old_id, new_id] LOOP
        CONTINUE WHEN rid IS NULL;
        PERFORM 1 FROM recipes WHERE id = rid FOR UPDATE;
        IF publisher IS DISTINCT FROM rid::text AND EXISTS (
            SELECT 1 FROM recipes WHERE id = rid AND derived_from_recipe_id IS NOT NULL AND status = 'pending'
        ) THEN
            RAISE EXCEPTION 'A submitted variation is frozen until review or withdrawal.' USING ERRCODE = 'integrity_constraint_violation';
        END IF;
        IF TG_TABLE_NAME = 'recipes' AND TG_OP <> 'DELETE' THEN
            IF NEW.derived_from_recipe_id IS NOT NULL AND NEW.visibility = 'public' AND publisher IS DISTINCT FROM rid::text THEN
                RAISE EXCEPTION 'A variation can only be published by the revision publisher.' USING ERRCODE = 'integrity_constraint_violation';
            END IF;
        END IF;
    END LOOP;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER variation_revision_context_freeze BEFORE UPDATE ON recipe_revisions FOR EACH ROW EXECUTE FUNCTION variation_submission_freeze();
SQL);
        DB::table('recipe_revision_settings')->insertOrIgnore(['key' => 'variation_edit_audit_since', 'value' => now()->startOfSecond()->toIso8601String(), 'created_at' => now(), 'updated_at' => now()]);
        foreach (self::TABLES as $table) {
            DB::unprepared("CREATE TRIGGER variation_submission_freeze BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION variation_submission_freeze()");
        }
    }

    public function down(): void
    {
        if (DB::table('recipe_revisions')->whereNotNull('publication_context')->exists()) {
            throw new LogicException('Publication declarations are retained history; migration rollback requires a reviewed retention plan.');
        }
        DB::table('recipe_revision_settings')->where('key', 'variation_edit_audit_since')->delete();
        foreach (self::TABLES as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS variation_submission_freeze ON {$table}");
        }
        DB::unprepared('DROP TRIGGER IF EXISTS variation_revision_context_freeze ON recipe_revisions; DROP FUNCTION IF EXISTS variation_submission_freeze(); ALTER TABLE recipe_revisions DROP CONSTRAINT IF EXISTS revision_publication_context_check');
        Schema::table('recipe_revisions', fn (Blueprint $table) => $table->dropColumn(['publication_context', 'publication_context_hash']));
    }
};
