<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private const GUARDED_TABLES = [
        'recipe_translations' => 'recipe_translations_content_guard',
        'recipe_ingredients' => 'recipe_ingredients_content_guard',
        'recipe_ingredient_translations' => 'recipe_ingredient_translations_content_guard',
        'recipe_steps' => 'recipe_steps_content_guard',
        'recipe_step_translations' => 'recipe_step_translations_content_guard',
        'recipe_step_media' => 'recipe_step_media_content_guard',
        'recipe_media' => 'recipe_media_content_guard',
        'ingredient_groups' => 'ingredient_groups_content_guard',
        'ingredient_group_translations' => 'ingredient_group_translations_content_guard',
        'recipe_category' => 'recipe_category_content_guard',
        'recipe_tag' => 'recipe_tag_content_guard',
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION recipe_content_owner(tbl text, r jsonb) RETURNS bigint AS $$
BEGIN
    RETURN CASE tbl
        WHEN 'recipes' THEN (r->>'id')::bigint
        WHEN 'recipe_ingredient_translations' THEN (SELECT recipe_id FROM recipe_ingredients WHERE id = (r->>'recipe_ingredient_id')::bigint)
        WHEN 'recipe_step_translations' THEN (SELECT recipe_id FROM recipe_steps WHERE id = (r->>'recipe_step_id')::bigint)
        WHEN 'recipe_step_media' THEN (SELECT recipe_id FROM recipe_steps WHERE id = (r->>'recipe_step_id')::bigint)
        WHEN 'ingredient_group_translations' THEN (SELECT recipe_id FROM ingredient_groups WHERE id = (r->>'ingredient_group_id')::bigint)
        ELSE (r->>'recipe_id')::bigint
    END;
END;
$$ LANGUAGE plpgsql STABLE;
SQL);

        // Public content changes only inside the publisher, which announces the recipe through app.recipe_publisher.
        // Mode comes from recipe_revision_settings: off, monitor (warn only) or enforce (reject).
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION recipe_content_guard() RETURNS trigger AS $$
DECLARE
    guard_mode text;
    publisher text;
    owners bigint[] := ARRAY[]::bigint[];
    owner_id bigint;
BEGIN
    IF TG_TABLE_NAME = 'recipes' THEN
        IF NOT (
            NEW.prep_minutes IS DISTINCT FROM OLD.prep_minutes
            OR NEW.cook_minutes IS DISTINCT FROM OLD.cook_minutes
            OR NEW.rest_minutes IS DISTINCT FROM OLD.rest_minutes
            OR NEW.base_servings IS DISTINCT FROM OLD.base_servings
            OR NEW.difficulty IS DISTINCT FROM OLD.difficulty
        ) THEN
            RETURN NEW;
        END IF;
    END IF;

    SELECT value INTO guard_mode FROM recipe_revision_settings WHERE key = 'content_guard';
    guard_mode := COALESCE(guard_mode, 'monitor');

    IF guard_mode <> 'off' THEN
        publisher := current_setting('app.recipe_publisher', true);

        IF TG_OP IN ('UPDATE', 'DELETE') THEN
            owners := owners || recipe_content_owner(TG_TABLE_NAME, to_jsonb(OLD));
        END IF;

        IF TG_OP IN ('INSERT', 'UPDATE') THEN
            owners := owners || recipe_content_owner(TG_TABLE_NAME, to_jsonb(NEW));
        END IF;

        FOREACH owner_id IN ARRAY owners LOOP
            CONTINUE WHEN owner_id IS NULL OR (publisher IS NOT NULL AND publisher = owner_id::text);

            IF EXISTS (SELECT 1 FROM recipes WHERE id = owner_id AND status = 'published' AND visibility = 'public' AND deleted_at IS NULL) THEN
                IF guard_mode = 'enforce' THEN
                    RAISE EXCEPTION 'Published recipe % can only be changed through a reviewed revision (% on %).', owner_id, TG_OP, TG_TABLE_NAME USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RAISE WARNING 'recipe_content_guard: unreviewed % on % for published recipe %', TG_OP, TG_TABLE_NAME, owner_id;
            END IF;
        END LOOP;
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        foreach (self::GUARDED_TABLES as $table => $trigger) {
            DB::unprepared("CREATE TRIGGER {$trigger} BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION recipe_content_guard()");
        }

        DB::unprepared('CREATE TRIGGER recipes_content_guard BEFORE UPDATE ON recipes FOR EACH ROW EXECUTE FUNCTION recipe_content_guard()');

        // The line foreign keys cascade, so personal data is copied aside before a line (and with it that data) is deleted.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION archive_recipe_ingredient_dependents() RETURNS trigger AS $$
DECLARE
    retired_revision bigint := NULLIF(current_setting('app.recipe_revision_id', true), '')::bigint;
    retire_reason text := COALESCE(NULLIF(current_setting('app.line_retire_reason', true), ''), 'line_removed');
BEGIN
    IF EXISTS (SELECT 1 FROM recipes WHERE id = OLD.recipe_id) THEN
        INSERT INTO archived_personal_ingredient_changes
            (original_id, user_id, recipe_id, recipe_ingredient_id, alternative_ingredient_id, note, source_recipe_revision_id, retired_in_revision_id, reason, original_created_at)
        SELECT id, user_id, recipe_id, recipe_ingredient_id, alternative_ingredient_id, note, source_recipe_revision_id, retired_revision, retire_reason, created_at
        FROM user_recipe_ingredient_changes WHERE recipe_ingredient_id = OLD.id;

        INSERT INTO archived_meal_plan_entry_substitutions
            (original_id, meal_plan_entry_id, recipe_id, recipe_ingredient_id, ingredient_substitution_id, replacement_ingredient_id, confirmed_by, confirmed_at, retired_in_revision_id, reason)
        SELECT id, meal_plan_entry_id, recipe_id, recipe_ingredient_id, ingredient_substitution_id, replacement_ingredient_id, confirmed_by, confirmed_at, retired_revision, retire_reason
        FROM meal_plan_entry_substitutions WHERE recipe_ingredient_id = OLD.id;
    END IF;

    RETURN OLD;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared('CREATE TRIGGER recipe_ingredients_archive_dependents BEFORE DELETE ON recipe_ingredients FOR EACH ROW EXECUTE FUNCTION archive_recipe_ingredient_dependents()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS recipe_ingredients_archive_dependents ON recipe_ingredients');
        DB::unprepared('DROP FUNCTION IF EXISTS archive_recipe_ingredient_dependents()');
        DB::unprepared('DROP TRIGGER IF EXISTS recipes_content_guard ON recipes');

        foreach (self::GUARDED_TABLES as $table => $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
        }

        DB::unprepared('DROP FUNCTION IF EXISTS recipe_content_guard()');
        DB::unprepared('DROP FUNCTION IF EXISTS recipe_content_owner(text, jsonb)');
    }
};
