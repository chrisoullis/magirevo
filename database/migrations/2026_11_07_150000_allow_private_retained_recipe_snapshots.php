<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE recipe_revisions DROP CONSTRAINT recipe_revisions_status_check, ADD CONSTRAINT recipe_revisions_status_check CHECK (status IN ('draft', 'in_review', 'rejected', 'published', 'superseded', 'retained'))");
        $this->replaceFunction('recipe_revisions_enforce_immutability', "NEW.status IN ('in_review', 'published')", "NEW.status IN ('in_review', 'published', 'retained')");
        foreach (['recipe_component_line_guard', 'recipe_revision_dependency_guard'] as $function) {
            $this->replaceFunction($function, "status IN ('published', 'superseded')", "status IN ('published', 'superseded', 'retained')");
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION recipe_retained_revision_guard() RETURNS trigger AS $$
BEGIN
    IF OLD.status = 'retained' AND (TG_OP = 'DELETE' OR NEW IS DISTINCT FROM OLD) THEN
        RAISE EXCEPTION 'Private retained revisions are immutable.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER recipe_retained_revision_guard BEFORE UPDATE OR DELETE ON recipe_revisions FOR EACH ROW EXECUTE FUNCTION recipe_retained_revision_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::table('recipe_revisions')->where('status', 'retained')->exists()) {
            throw new LogicException('Private retained history requires a reviewed retention plan before rollback.');
        }
        DB::unprepared('DROP TRIGGER recipe_retained_revision_guard ON recipe_revisions; DROP FUNCTION recipe_retained_revision_guard()');
        foreach (['recipe_component_line_guard', 'recipe_revision_dependency_guard'] as $function) {
            $this->replaceFunction($function, "status IN ('published', 'superseded', 'retained')", "status IN ('published', 'superseded')");
        }
        $this->replaceFunction('recipe_revisions_enforce_immutability', "NEW.status IN ('in_review', 'published', 'retained')", "NEW.status IN ('in_review', 'published')");
        DB::statement("ALTER TABLE recipe_revisions DROP CONSTRAINT recipe_revisions_status_check, ADD CONSTRAINT recipe_revisions_status_check CHECK (status IN ('draft', 'in_review', 'rejected', 'published', 'superseded'))");
    }

    private function replaceFunction(string $name, string $before, string $after): void
    {
        // Preserve guards installed by prior migrations, including media snapshot protection.
        $definition = DB::selectOne('SELECT pg_get_functiondef(CAST(? AS regprocedure)) AS definition', [$name.'()'])->definition;
        if (! str_contains($definition, $before)) {
            throw new LogicException('Unexpected recipe guard definition: '.$name);
        }
        DB::unprepared(str_replace($before, $after, $definition));
    }
};
