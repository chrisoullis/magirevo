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
            $table->foreignId('derived_from_recipe_id')->nullable()->constrained('recipes')->restrictOnDelete();
            $table->index('derived_from_recipe_id');
        });
        DB::statement('UPDATE recipes r SET derived_from_recipe_id = d.source_recipe_id FROM recipe_derivations d WHERE d.recipe_id = r.id');

        // Existing incomplete provenance remains explicitly legacy; no rights are inferred or fabricated.
        Schema::table('recipe_derivations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('provenance_version')->default(0);
            $table->text('source_title_snapshot')->nullable();
            $table->text('source_author_snapshot')->nullable();
            $table->text('source_url_snapshot')->nullable();
            $table->jsonb('source_license_snapshot')->nullable();
            $table->jsonb('attribution_snapshot')->nullable();
            $table->string('source_rights_basis', 50)->nullable();
            $table->text('source_permission_reference')->nullable();
            $table->foreignId('source_permission_evidence_id')->nullable()->constrained('recipe_reuse_permissions')->restrictOnDelete();
            $table->uuid('idempotency_key')->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->unique(['created_by', 'idempotency_key'], 'recipe_derivations_actor_request_unique');
        });
        DB::statement('ALTER TABLE recipe_derivations ALTER COLUMN provenance_version SET DEFAULT 1');

        // Stop instead of silently rewriting corrupt historical relationships.
        DB::unprepared(<<<'SQL'
DO $$ BEGIN
    IF EXISTS (
        WITH RECURSIVE chain AS (
            SELECT id, derived_from_recipe_id AS parent_id, ARRAY[id] AS visited, false AS cycle FROM recipes
            UNION ALL
            SELECT r.id, r.derived_from_recipe_id, c.visited || r.id, r.id = ANY(c.visited)
            FROM chain c JOIN recipes r ON r.id = c.parent_id WHERE NOT c.cycle
        ) SELECT 1 FROM chain WHERE cycle
    ) THEN
        RAISE EXCEPTION 'Existing derivations contain a cycle; review them before migrating.';
    END IF;
    IF EXISTS (
        SELECT 1 FROM recipe_derivations d JOIN recipes r ON r.id = d.recipe_id
        LEFT JOIN recipe_revisions v ON v.id = d.source_recipe_revision_id
        WHERE r.id = r.derived_from_recipe_id OR
            (d.source_recipe_revision_id IS NOT NULL AND v.recipe_id <> r.derived_from_recipe_id)
    ) THEN
        RAISE EXCEPTION 'Existing derivation provenance is inconsistent; review it before migrating.';
    END IF;
END $$;
SQL);
        Schema::table('recipe_derivations', function (Blueprint $table): void {
            $table->dropForeign(['source_recipe_id']);
            $table->dropColumn('source_recipe_id');
        });
        Schema::create('recipe_derivation_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_derivation_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->text('change_summary')->nullable();
            $table->timestamps();
            $table->unique(['recipe_derivation_id', 'locale'], 'recipe_derivation_translations_locale_unique');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE recipes ADD CONSTRAINT recipes_no_self_derivation CHECK (derived_from_recipe_id IS NULL OR derived_from_recipe_id <> id);
ALTER TABLE recipe_derivation_translations ADD CONSTRAINT derivation_translation_locale_check CHECK (locale IN ('en', 'el'));
ALTER TABLE recipe_derivations
    ADD CONSTRAINT derivation_version_check CHECK (provenance_version IN (0, 1)),
    ADD CONSTRAINT derivation_fingerprint_check CHECK (request_fingerprint IS NULL OR request_fingerprint ~ '^[a-f0-9]{64}$'),
    ADD CONSTRAINT derivation_complete_check CHECK (provenance_version = 0 OR
        (created_by IS NOT NULL AND source_recipe_revision_id IS NOT NULL AND source_permission_evidence_id IS NOT NULL
         AND source_rights_basis IS NOT NULL AND length(btrim(source_rights_basis)) > 0
         AND source_license_snapshot IS NOT NULL AND jsonb_typeof(source_license_snapshot) = 'object'
         AND attribution_snapshot IS NOT NULL AND jsonb_typeof(attribution_snapshot) = 'object'
         AND idempotency_key IS NOT NULL AND request_fingerprint IS NOT NULL));

CREATE OR REPLACE FUNCTION recipe_derivation_source_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.derived_from_recipe_id IS DISTINCT FROM OLD.derived_from_recipe_id THEN
        RAISE EXCEPTION 'A recipe source cannot be changed or unlinked.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    IF TG_OP = 'INSERT' AND NEW.derived_from_recipe_id IS NOT NULL THEN
        PERFORM 1 FROM recipes WHERE id = NEW.derived_from_recipe_id FOR UPDATE;
        IF NOT EXISTS (SELECT 1 FROM recipes WHERE id = NEW.derived_from_recipe_id AND derived_from_recipe_id IS NULL) THEN
            RAISE EXCEPTION 'Recursive variations are not supported.' USING ERRCODE = 'integrity_constraint_violation';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER recipes_derivation_source_guard BEFORE INSERT OR UPDATE ON recipes
    FOR EACH ROW EXECUTE FUNCTION recipe_derivation_source_guard();

CREATE OR REPLACE FUNCTION recipe_derivation_provenance_guard() RETURNS trigger AS $$
DECLARE
    source_id bigint;
BEGIN
    IF TG_OP = 'TRUNCATE' THEN
        RAISE EXCEPTION 'Permanent provenance cannot be truncated.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    IF TG_OP = 'DELETE' THEN
        IF EXISTS (SELECT 1 FROM recipes WHERE id = OLD.recipe_id) THEN
            RAISE EXCEPTION 'Provenance cannot be removed from a retained recipe.' USING ERRCODE = 'integrity_constraint_violation';
        END IF;
        RETURN OLD;
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF to_jsonb(NEW) - 'updated_at' IS DISTINCT FROM to_jsonb(OLD) - 'updated_at' THEN
            RAISE EXCEPTION 'Original provenance is immutable.' USING ERRCODE = 'integrity_constraint_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF NEW.provenance_version <> 1 THEN
        RAISE EXCEPTION 'New derivations require complete provenance.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    SELECT derived_from_recipe_id INTO source_id FROM recipes WHERE id = NEW.recipe_id;
    IF source_id IS NULL OR NOT EXISTS (
        SELECT 1 FROM recipe_revisions WHERE id = NEW.source_recipe_revision_id AND recipe_id = source_id AND status IN ('published', 'superseded')
    ) THEN
        RAISE EXCEPTION 'Provenance must reference the source recipe revision.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    PERFORM 1 FROM recipe_reuse_permissions WHERE id = NEW.source_permission_evidence_id FOR SHARE;
    IF NOT EXISTS (
        SELECT 1 FROM recipe_reuse_permissions p WHERE p.id = NEW.source_permission_evidence_id
            AND p.recipe_id = source_id AND p.recipe_revision_id = NEW.source_recipe_revision_id
            AND p.verification_status = 'verified' AND p.mode <> 'none' AND p.suspended_at IS NULL
            AND p.rights_basis = NEW.source_rights_basis
    ) THEN
        RAISE EXCEPTION 'Provenance requires matching verified reuse evidence.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM recipes WHERE id = NEW.recipe_id AND author_id = NEW.created_by) THEN
        RAISE EXCEPTION 'The variation creator must own the new recipe.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER recipe_derivations_provenance_guard BEFORE INSERT OR UPDATE OR DELETE ON recipe_derivations
    FOR EACH ROW EXECUTE FUNCTION recipe_derivation_provenance_guard();
CREATE TRIGGER recipe_derivations_no_truncate BEFORE TRUNCATE ON recipe_derivations
    FOR EACH STATEMENT EXECUTE FUNCTION recipe_derivation_provenance_guard();

-- Creation may insert the recipe and provenance in either order inside one transaction.
CREATE OR REPLACE FUNCTION recipe_derivation_requires_provenance() RETURNS trigger AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM recipes r WHERE r.id = NEW.id AND r.derived_from_recipe_id IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM recipe_derivations d WHERE d.recipe_id = r.id)) THEN
        RAISE EXCEPTION 'A derived recipe requires permanent provenance.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE CONSTRAINT TRIGGER recipes_require_derivation_provenance AFTER INSERT OR UPDATE ON recipes
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION recipe_derivation_requires_provenance();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS recipes_require_derivation_provenance ON recipes;
DROP TRIGGER IF EXISTS recipes_derivation_source_guard ON recipes;
DROP TRIGGER IF EXISTS recipe_derivations_provenance_guard ON recipe_derivations;
DROP TRIGGER IF EXISTS recipe_derivations_no_truncate ON recipe_derivations;
DROP FUNCTION IF EXISTS recipe_derivation_requires_provenance();
DROP FUNCTION IF EXISTS recipe_derivation_source_guard();
DROP FUNCTION IF EXISTS recipe_derivation_provenance_guard();
ALTER TABLE recipes DROP CONSTRAINT IF EXISTS recipes_no_self_derivation;
ALTER TABLE recipe_derivations DROP CONSTRAINT IF EXISTS derivation_version_check,
    DROP CONSTRAINT IF EXISTS derivation_fingerprint_check, DROP CONSTRAINT IF EXISTS derivation_complete_check;
SQL);
        Schema::dropIfExists('recipe_derivation_translations');
        Schema::table('recipe_derivations', function (Blueprint $table): void {
            $table->foreignId('source_recipe_id')->nullable()->constrained('recipes')->restrictOnDelete();
        });
        DB::statement('UPDATE recipe_derivations d SET source_recipe_id = r.derived_from_recipe_id FROM recipes r WHERE r.id = d.recipe_id');
        DB::statement('ALTER TABLE recipe_derivations ALTER COLUMN source_recipe_id SET NOT NULL');
        Schema::table('recipe_derivations', function (Blueprint $table): void {
            $table->dropForeign(['source_permission_evidence_id']);
            $table->dropUnique('recipe_derivations_actor_request_unique');
            $table->dropColumn(['provenance_version', 'source_title_snapshot', 'source_author_snapshot', 'source_url_snapshot', 'source_license_snapshot', 'attribution_snapshot', 'source_rights_basis', 'source_permission_reference', 'source_permission_evidence_id', 'idempotency_key', 'request_fingerprint']);
            $table->index('source_recipe_id');
        });
        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('derived_from_recipe_id');
        });
    }
};
