<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cooking_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('recipe_revision_id');
            $table->uuid('request_key');
            $table->char('content_hash', 64);
            $table->char('media_snapshot_hash', 64);
            $table->string('access_scope', 20);
            $table->decimal('servings', 8, 2);
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('version')->default(1);
            $table->jsonb('state');
            $table->timestampsTz();
            $table->unique(['user_id', 'request_key']);
            $table->index(['user_id', 'recipe_id', 'status', 'updated_at'], 'cooking_sessions_resume_index');
            $table->index('recipe_revision_id');
            $table->foreign(['recipe_id', 'recipe_revision_id'], 'cooking_sessions_recipe_revision_foreign')
                ->references(['recipe_id', 'id'])->on('recipe_revisions')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE cooking_sessions ADD CONSTRAINT cooking_sessions_values_check CHECK (
    servings > 0 AND servings <= 1000 AND version > 0
    AND status IN ('active', 'completed') AND access_scope IN ('public', 'restricted')
    AND jsonb_typeof(state) = 'object'
);
CREATE FUNCTION cooking_sessions_identity_guard() RETURNS trigger AS $$
DECLARE
    revision record;
BEGIN
    IF TG_OP = 'INSERT' THEN
        SELECT status, content_hash, media_snapshot_hash, published_at INTO revision
        FROM recipe_revisions WHERE id = NEW.recipe_revision_id AND recipe_id = NEW.recipe_id;
        IF NOT FOUND OR revision.status NOT IN ('retained', 'published', 'superseded')
           OR (revision.status <> 'retained' AND revision.published_at IS NULL)
           OR revision.content_hash IS DISTINCT FROM NEW.content_hash
           OR revision.media_snapshot_hash IS DISTINCT FROM NEW.media_snapshot_hash
           OR (NEW.access_scope = 'public' AND revision.status = 'retained') THEN
            RAISE EXCEPTION 'Cooking sessions require an exact immutable recipe revision.' USING ERRCODE = 'integrity_constraint_violation';
        END IF;
    ELSE
        IF NEW.id IS DISTINCT FROM OLD.id OR NEW.user_id IS DISTINCT FROM OLD.user_id
           OR NEW.recipe_id IS DISTINCT FROM OLD.recipe_id OR NEW.recipe_revision_id IS DISTINCT FROM OLD.recipe_revision_id
           OR NEW.request_key IS DISTINCT FROM OLD.request_key OR NEW.access_scope IS DISTINCT FROM OLD.access_scope
           OR NEW.content_hash IS DISTINCT FROM OLD.content_hash OR NEW.media_snapshot_hash IS DISTINCT FROM OLD.media_snapshot_hash THEN
            RAISE EXCEPTION 'Cooking session ownership and content identity are immutable.' USING ERRCODE = 'integrity_constraint_violation';
        END IF;
        IF NEW.state IS DISTINCT FROM OLD.state OR NEW.servings IS DISTINCT FROM OLD.servings OR NEW.status IS DISTINCT FROM OLD.status THEN
            IF NEW.version <> OLD.version + 1 THEN
                RAISE EXCEPTION 'Cooking session changes must advance their version.' USING ERRCODE = 'integrity_constraint_violation';
            END IF;
        ELSIF NEW.version <> OLD.version THEN
            RAISE EXCEPTION 'An unchanged cooking session must keep its version.' USING ERRCODE = 'integrity_constraint_violation';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER cooking_sessions_identity_guard BEFORE INSERT OR UPDATE ON cooking_sessions
    FOR EACH ROW EXECUTE FUNCTION cooking_sessions_identity_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::table('cooking_sessions')->exists()) {
            throw new LogicException('Cooking progress exists; rollback would destroy it.');
        }
        Schema::dropIfExists('cooking_sessions');
        DB::unprepared('DROP FUNCTION IF EXISTS cooking_sessions_identity_guard()');
    }
};
