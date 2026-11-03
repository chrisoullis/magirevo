<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_reuse_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipe_revision_id')->constrained()->restrictOnDelete();
            $table->foreignId('supersedes_id')->nullable()->constrained('recipe_reuse_permissions')->restrictOnDelete();
            $table->string('mode', 40)->default('none');
            $table->string('rights_basis', 50);
            $table->text('permission_reference')->nullable();
            // Paths are relative to the dedicated private disk, never a public URL.
            $table->text('evidence_path')->nullable();
            $table->char('evidence_sha256', 64)->nullable();
            $table->string('license_identifier')->nullable();
            $table->text('license_url')->nullable();
            $table->jsonb('license_conditions')->default('{}');
            foreach (['allows_text_reproduction', 'allows_private_adaptation', 'allows_public_adaptation', 'allows_sharing', 'allows_commercial_use', 'allows_public_attribution', 'requires_attribution', 'requires_share_alike', 'prohibits_derivatives'] as $flag) {
                $table->boolean($flag)->default(false);
            }
            $table->string('verification_status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('suspension_reason')->nullable();
            $table->timestamps();
            $table->index(['recipe_id', 'recipe_revision_id', 'id'], 'recipe_reuse_permissions_source_index');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE recipe_reuse_permissions
    ADD CONSTRAINT reuse_mode_check CHECK (mode IN ('none', 'personal', 'personal_and_public_submission')),
    ADD CONSTRAINT reuse_verification_check CHECK (verification_status IN ('pending', 'verified', 'rejected')),
    ADD CONSTRAINT reuse_basis_check CHECK (length(btrim(rights_basis)) > 0),
    ADD CONSTRAINT reuse_conditions_check CHECK (jsonb_typeof(license_conditions) = 'object'),
    ADD CONSTRAINT reuse_evidence_check CHECK (
        (evidence_path IS NULL AND evidence_sha256 IS NULL) OR
        (evidence_path IS NOT NULL AND evidence_sha256 IS NOT NULL AND length(btrim(evidence_path)) > 0 AND evidence_sha256 ~ '^[a-f0-9]{64}$'
         AND evidence_path !~ '(^/|(^|/)\.\.(/|$)|://)')),
    ADD CONSTRAINT reuse_review_check CHECK (verification_status = 'pending' OR
        (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND coalesce(length(btrim(review_notes)), 0) > 0)),
    ADD CONSTRAINT reuse_verified_evidence_check CHECK (verification_status <> 'verified' OR
        (coalesce(length(btrim(permission_reference)), 0) > 0 OR evidence_path IS NOT NULL)),
    ADD CONSTRAINT reuse_adaptation_check CHECK (mode = 'none' OR
        (verification_status = 'verified' AND allows_text_reproduction AND allows_private_adaptation AND NOT prohibits_derivatives)),
    ADD CONSTRAINT reuse_public_adaptation_check CHECK (mode <> 'personal_and_public_submission' OR (allows_public_adaptation AND (NOT requires_attribution OR allows_public_attribution))),
    ADD CONSTRAINT reuse_suspension_check CHECK (
        (suspended_at IS NULL AND suspended_by IS NULL AND suspension_reason IS NULL) OR
        (suspended_at IS NOT NULL AND suspended_by IS NOT NULL AND coalesce(length(btrim(suspension_reason)), 0) > 0));

CREATE OR REPLACE FUNCTION recipe_reuse_permission_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
        RAISE EXCEPTION 'Rights evidence is retained history.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF (to_jsonb(NEW) - ARRAY['suspended_at', 'suspended_by', 'suspension_reason', 'updated_at'])
            IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['suspended_at', 'suspended_by', 'suspension_reason', 'updated_at'])
            OR OLD.suspended_at IS NOT NULL THEN
            RAISE EXCEPTION 'Create a new rights version; historical evidence cannot be rewritten.' USING ERRCODE = 'integrity_constraint_violation';
        END IF;
        RETURN NEW;
    END IF;
    PERFORM 1 FROM recipes WHERE id = NEW.recipe_id FOR UPDATE;
    IF NOT EXISTS (SELECT 1 FROM recipe_revisions WHERE id = NEW.recipe_revision_id AND recipe_id = NEW.recipe_id AND status IN ('published', 'superseded')) THEN
        RAISE EXCEPTION 'Rights evidence must identify a retained published source revision.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    IF NEW.supersedes_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM recipe_reuse_permissions WHERE id = NEW.supersedes_id AND recipe_id = NEW.recipe_id
    ) THEN
        RAISE EXCEPTION 'Rights versions must belong to the same source recipe.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER recipe_reuse_permissions_guard BEFORE INSERT OR UPDATE OR DELETE ON recipe_reuse_permissions
    FOR EACH ROW EXECUTE FUNCTION recipe_reuse_permission_guard();
CREATE TRIGGER recipe_reuse_permissions_no_truncate BEFORE TRUNCATE ON recipe_reuse_permissions
    FOR EACH STATEMENT EXECUTE FUNCTION recipe_reuse_permission_guard();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_reuse_permissions');
        DB::unprepared('DROP FUNCTION IF EXISTS recipe_reuse_permission_guard()');
    }
};
