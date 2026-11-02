<?php

use App\Services\RecipeRevisionSnapshotter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the immutable media metadata snapshot (English and Greek alt text and captions, file identity, credits) to
     * revisions. Purely additive: the payload and its hash are untouched, so existing revisions, approvals and claims
     * stay valid. Currently published revisions are backfilled once from the metadata they show today.
     */
    public function up(): void
    {
        Schema::table('recipe_revisions', function (Blueprint $table): void {
            $table->jsonb('media_snapshot')->nullable();
            $table->string('media_snapshot_hash', 64)->nullable();
        });

        $this->installFrozenSnapshotRule(true);
        $this->installMediaIdentityGuard();
        $this->backfillPublishedRevisions();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS media_retained_identity_guard ON media');
        DB::unprepared('DROP FUNCTION IF EXISTS media_retained_identity_guard()');
        $this->installFrozenSnapshotRule(false);

        Schema::table('recipe_revisions', function (Blueprint $table): void {
            $table->dropColumn(['media_snapshot', 'media_snapshot_hash']);
        });
    }

    private function backfillPublishedRevisions(): void
    {
        $snapshotter = app(RecipeRevisionSnapshotter::class);

        DB::table('recipe_revisions')->where('status', 'published')->whereNull('media_snapshot')->orderBy('id')->get(['id', 'payload'])->each(function (object $revision) use ($snapshotter): void {
            $snapshot = $snapshotter->snapshotMedia(json_decode($revision->payload, true));

            DB::table('recipe_revisions')->where('id', $revision->id)->update([
                'media_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'media_snapshot_hash' => $snapshotter->mediaSnapshotHash($snapshot),
            ]);
        });
    }

    /**
     * Once a revision has left draft its media snapshot is frozen, except that a missing one may be filled in once.
     */
    private function installFrozenSnapshotRule(bool $withSnapshot): void
    {
        $snapshotRule = $withSnapshot ? <<<'SQL'
    IF OLD.status <> 'draft' AND OLD.media_snapshot IS NOT NULL AND (NEW.media_snapshot IS DISTINCT FROM OLD.media_snapshot OR NEW.media_snapshot_hash IS DISTINCT FROM OLD.media_snapshot_hash) THEN
        RAISE EXCEPTION 'Recipe revision % media metadata is frozen after it leaves draft.', OLD.id USING ERRCODE = 'integrity_constraint_violation';
    END IF;

SQL : '';

        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION recipe_revisions_enforce_immutability() RETURNS trigger AS \$\$
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

{$snapshotRule}    IF NEW.status <> OLD.status AND NOT (
        (OLD.status = 'draft' AND NEW.status IN ('in_review', 'published'))
        OR (OLD.status = 'in_review' AND NEW.status IN ('published', 'rejected'))
        OR (OLD.status = 'published' AND NEW.status = 'superseded')
    ) THEN
        RAISE EXCEPTION 'Recipe revision cannot move from % to %.', OLD.status, NEW.status USING ERRCODE = 'integrity_constraint_violation';
    END IF;

    RETURN NEW;
END;
\$\$ LANGUAGE plpgsql;
SQL);
    }

    /**
     * The file or remote address a retained revision recorded cannot be swapped for different content.
     */
    private function installMediaIdentityGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION media_retained_identity_guard() RETURNS trigger AS $$
BEGIN
    IF (NEW.source IS DISTINCT FROM OLD.source OR NEW.file_path IS DISTINCT FROM OLD.file_path OR NEW.url IS DISTINCT FROM OLD.url)
        AND EXISTS (SELECT 1 FROM recipe_revision_media WHERE media_id = OLD.id) THEN
        RAISE EXCEPTION 'Media % is retained by a recipe revision; its file or address cannot be replaced.', OLD.id USING ERRCODE = 'integrity_constraint_violation';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared('CREATE TRIGGER media_retained_identity_guard BEFORE UPDATE ON media FOR EACH ROW EXECUTE FUNCTION media_retained_identity_guard()');
    }
};
