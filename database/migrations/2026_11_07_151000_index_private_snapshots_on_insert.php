<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DRAFT_ONLY = "IF parent_status <> 'draft' THEN\n        RAISE EXCEPTION 'Dependencies must be recorded before the parent revision is frozen.'";

    private const SNAPSHOT_INSERT = "IF parent_status <> 'draft' AND NOT (TG_OP = 'INSERT' AND parent_status = 'retained' AND pg_trigger_depth() > 1) THEN\n        RAISE EXCEPTION 'Dependencies must be recorded before the parent revision is frozen.'";

    public function up(): void
    {
        $this->replaceGuard(self::DRAFT_ONLY, self::SNAPSHOT_INSERT);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION recipe_retained_dependencies_on_insert() RETURNS trigger AS $$
BEGIN
    INSERT INTO recipe_revision_dependencies
        (recipe_revision_id, line_uuid, component_recipe_id, component_recipe_revision_id, created_at, updated_at)
    SELECT NEW.id, (line->>'line_uuid')::uuid, (line->>'component_recipe_id')::bigint,
        (line->>'component_recipe_revision_id')::bigint, NOW(), NOW()
    FROM jsonb_array_elements(COALESCE(NEW.payload->'ingredients', '[]'::jsonb)) AS line
    WHERE line->>'line_type' = 'recipe';
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER recipe_retained_dependencies_on_insert AFTER INSERT ON recipe_revisions
    FOR EACH ROW WHEN (NEW.status = 'retained') EXECUTE FUNCTION recipe_retained_dependencies_on_insert();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER recipe_retained_dependencies_on_insert ON recipe_revisions; DROP FUNCTION recipe_retained_dependencies_on_insert()');
        $this->replaceGuard(self::SNAPSHOT_INSERT, self::DRAFT_ONLY);
    }

    private function replaceGuard(string $before, string $after): void
    {
        // Keep Stage 1's source checks and all existing immutability checks intact.
        $definition = DB::selectOne("SELECT pg_get_functiondef('recipe_revision_dependency_guard()'::regprocedure) AS definition")->definition;
        if (! str_contains($definition, $before)) {
            throw new LogicException('Unexpected recipe dependency guard definition.');
        }
        DB::unprepared(str_replace($before, $after, $definition));
    }
};
