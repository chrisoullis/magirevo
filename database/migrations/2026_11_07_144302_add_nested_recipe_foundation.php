<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_revisions', function (Blueprint $table): void {
            $table->unique(['recipe_id', 'id'], 'recipe_revisions_recipe_identity_unique');
        });

        Schema::table('recipe_ingredients', function (Blueprint $table): void {
            $table->uuid('line_uuid')->default(DB::raw('gen_random_uuid()'));
            $table->string('line_type', 20)->default('ingredient');
            $table->unsignedBigInteger('ingredient_id')->nullable()->change();
            $table->foreignId('component_recipe_id')->nullable()->constrained('recipes')->restrictOnDelete();
            $table->unsignedBigInteger('component_recipe_revision_id')->nullable();
            $table->string('quantity_basis', 20)->nullable();
            $table->string('shopping_mode', 20)->nullable();
            $table->foreignId('prepared_ingredient_id')->nullable()->constrained('ingredients')->restrictOnDelete();
            $table->decimal('prepared_quantity_per_batch', 12, 4)->nullable();
            $table->foreignId('prepared_unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->unique(['recipe_id', 'line_uuid']);
            $table->index('component_recipe_revision_id');
            $table->foreign(['component_recipe_id', 'component_recipe_revision_id'], 'recipe_ingredients_component_revision_foreign')
                ->references(['recipe_id', 'id'])->on('recipe_revisions')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE recipe_ingredients ADD CONSTRAINT recipe_ingredients_line_type_check CHECK (
    (line_type = 'ingredient' AND ingredient_id IS NOT NULL
        AND component_recipe_id IS NULL AND component_recipe_revision_id IS NULL
        AND quantity_basis IS NULL AND shopping_mode IS NULL
        AND prepared_ingredient_id IS NULL AND prepared_quantity_per_batch IS NULL AND prepared_unit_id IS NULL)
    OR
    (line_type = 'recipe' AND ingredient_id IS NULL
        AND component_recipe_id IS NOT NULL AND component_recipe_revision_id IS NOT NULL
        AND component_recipe_id <> recipe_id
        AND quantity_basis IS NOT NULL AND quantity_basis IN ('whole_batch', 'servings', 'weight', 'volume')
        AND shopping_mode IS NOT NULL AND shopping_mode IN ('scratch', 'prepared')
        AND ((quantity IS NOT NULL AND quantity > 0) OR (quantity IS NULL AND scaling_mode = 'manual'))
        AND (quantity_basis IN ('weight', 'volume') OR unit_id IS NULL))
);
ALTER TABLE recipe_ingredients ADD CONSTRAINT recipe_ingredients_prepared_mapping_check CHECK (
    (prepared_ingredient_id IS NULL AND prepared_quantity_per_batch IS NULL AND prepared_unit_id IS NULL AND shopping_mode IS DISTINCT FROM 'prepared')
    OR (prepared_ingredient_id IS NOT NULL AND prepared_quantity_per_batch IS NOT NULL AND prepared_quantity_per_batch > 0)
);
SQL);

        Schema::create('recipe_yields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('basis', 20);
            $table->decimal('quantity', 12, 4);
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->jsonb('evidence');
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at');
            $table->timestamps();
            $table->unique(['recipe_id', 'basis']);
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE recipe_yields ADD CONSTRAINT recipe_yields_basis_check CHECK (basis IN ('weight', 'volume'));
ALTER TABLE recipe_yields ADD CONSTRAINT recipe_yields_quantity_check CHECK (quantity > 0);
ALTER TABLE recipe_yields ADD CONSTRAINT recipe_yields_evidence_check CHECK (jsonb_typeof(evidence) = 'object' AND evidence <> '{}'::jsonb);
CREATE TRIGGER recipe_yields_content_guard BEFORE INSERT OR UPDATE OR DELETE ON recipe_yields FOR EACH ROW EXECUTE FUNCTION recipe_content_guard();
CREATE TRIGGER variation_submission_freeze BEFORE INSERT OR UPDATE OR DELETE ON recipe_yields FOR EACH ROW EXECUTE FUNCTION variation_submission_freeze();
SQL);

        Schema::create('recipe_revision_dependencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_revision_id')->constrained('recipe_revisions')->cascadeOnDelete();
            $table->uuid('line_uuid');
            $table->foreignId('component_recipe_id')->constrained('recipes')->restrictOnDelete();
            $table->unsignedBigInteger('component_recipe_revision_id');
            $table->timestamps();
            $table->unique(['recipe_revision_id', 'line_uuid'], 'recipe_revision_dependencies_line_unique');
            $table->index('component_recipe_revision_id');
            $table->foreign(['component_recipe_id', 'component_recipe_revision_id'], 'recipe_revision_dependencies_source_foreign')
                ->references(['recipe_id', 'id'])->on('recipe_revisions')->restrictOnDelete();
        });

        $this->installGuards();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('recipe_ingredients')->where('line_type', 'recipe')->exists()
            || DB::table('recipe_yields')->exists()
            || DB::table('recipe_revision_dependencies')->exists()) {
            throw new LogicException('Nested recipe data must be retained; rollback requires a reviewed retention plan.');
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS recipe_ingredients_component_guard ON recipe_ingredients;
DROP TRIGGER IF EXISTS recipe_yields_measurement_guard ON recipe_yields;
DROP TRIGGER IF EXISTS units_recipe_yield_guard ON units;
DROP TRIGGER IF EXISTS recipe_revision_dependencies_guard ON recipe_revision_dependencies;
DROP FUNCTION IF EXISTS recipe_component_line_guard();
DROP FUNCTION IF EXISTS recipe_yield_measurement_guard();
DROP FUNCTION IF EXISTS units_recipe_yield_guard();
DROP FUNCTION IF EXISTS recipe_revision_dependency_guard();
SQL);
        Schema::dropIfExists('recipe_revision_dependencies');
        Schema::dropIfExists('recipe_yields');
        DB::statement('ALTER TABLE recipe_ingredients DROP CONSTRAINT recipe_ingredients_line_type_check, DROP CONSTRAINT recipe_ingredients_prepared_mapping_check');

        Schema::table('recipe_ingredients', function (Blueprint $table): void {
            $table->dropForeign('recipe_ingredients_component_revision_foreign');
            $table->dropForeign(['component_recipe_id']);
            $table->dropForeign(['prepared_ingredient_id']);
            $table->dropForeign(['prepared_unit_id']);
            $table->dropUnique(['recipe_id', 'line_uuid']);
            $table->dropIndex(['component_recipe_revision_id']);
            $table->dropColumn(['line_uuid', 'line_type', 'component_recipe_id', 'component_recipe_revision_id', 'quantity_basis', 'shopping_mode', 'prepared_ingredient_id', 'prepared_quantity_per_batch', 'prepared_unit_id']);
            $table->unsignedBigInteger('ingredient_id')->nullable(false)->change();
        });
        Schema::table('recipe_revisions', fn (Blueprint $table) => $table->dropUnique('recipe_revisions_recipe_identity_unique'));
    }

    private function installGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION recipe_component_line_guard() RETURNS trigger AS $$
DECLARE family text; factor numeric;
BEGIN
    IF TG_OP = 'UPDATE' AND (NEW.line_uuid IS DISTINCT FROM OLD.line_uuid OR NEW.recipe_id <> OLD.recipe_id) THEN
        RAISE EXCEPTION 'Ingredient line identity is immutable.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    IF NEW.line_type = 'recipe' THEN
        IF NEW.prepared_unit_id IS NOT NULL THEN
            SELECT unit_type, base_conversion INTO family, factor FROM units WHERE id = NEW.prepared_unit_id FOR SHARE;
            IF family IN ('mass', 'volume') AND (factor IS NULL OR factor <= 0) THEN
                RAISE EXCEPTION 'Prepared product measurement units require a positive conversion factor.' USING ERRCODE = 'integrity_constraint_violation';
            END IF;
        END IF;
        PERFORM 1 FROM recipe_revisions WHERE id = NEW.component_recipe_revision_id
            AND recipe_id = NEW.component_recipe_id AND status IN ('published', 'superseded') FOR SHARE;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'A component must reference a retained published revision.' USING ERRCODE = 'integrity_constraint_violation';
        END IF;
        IF NEW.quantity_basis IN ('weight', 'volume') THEN
            SELECT unit_type, base_conversion INTO family, factor FROM units WHERE id = NEW.unit_id FOR SHARE;
            IF family IS DISTINCT FROM (CASE NEW.quantity_basis WHEN 'weight' THEN 'mass' ELSE 'volume' END) OR factor IS NULL OR factor <= 0 THEN
                RAISE EXCEPTION 'Component quantity units must match their measurement basis.' USING ERRCODE = 'integrity_constraint_violation';
            END IF;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER recipe_ingredients_component_guard BEFORE INSERT OR UPDATE ON recipe_ingredients FOR EACH ROW EXECUTE FUNCTION recipe_component_line_guard();

CREATE OR REPLACE FUNCTION recipe_yield_measurement_guard() RETURNS trigger AS $$
DECLARE family text; factor numeric;
BEGIN
    SELECT unit_type, base_conversion INTO family, factor FROM units WHERE id = NEW.unit_id FOR SHARE;
    IF family IS DISTINCT FROM (CASE NEW.basis WHEN 'weight' THEN 'mass' ELSE 'volume' END) OR factor IS NULL OR factor <= 0 THEN
        RAISE EXCEPTION 'Recipe yield units must match their measurement basis and have a positive conversion factor.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER recipe_yields_measurement_guard BEFORE INSERT OR UPDATE ON recipe_yields FOR EACH ROW EXECUTE FUNCTION recipe_yield_measurement_guard();

CREATE OR REPLACE FUNCTION units_recipe_yield_guard() RETURNS trigger AS $$
BEGIN
    IF (NEW.unit_type IS DISTINCT FROM OLD.unit_type OR NEW.base_conversion IS DISTINCT FROM OLD.base_conversion)
        AND (EXISTS (SELECT 1 FROM recipe_yields WHERE unit_id = OLD.id)
            OR EXISTS (SELECT 1 FROM recipe_ingredients WHERE line_type = 'recipe' AND (unit_id = OLD.id OR prepared_unit_id = OLD.id))
            OR EXISTS (SELECT 1 FROM recipe_revisions WHERE status <> 'draft'
                AND (COALESCE(payload->'units', '{}'::jsonb) ? OLD.id::text
                    OR payload->'ingredients' @> jsonb_build_array(jsonb_build_object('unit_id', OLD.id))
                    OR payload->'ingredients' @> jsonb_build_array(jsonb_build_object('prepared_unit_id', OLD.id))))) THEN
        RAISE EXCEPTION 'A unit used by recipe yields or components cannot change its measurement definition.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER units_recipe_yield_guard BEFORE UPDATE ON units FOR EACH ROW EXECUTE FUNCTION units_recipe_yield_guard();

CREATE OR REPLACE FUNCTION recipe_revision_dependency_guard() RETURNS trigger AS $$
DECLARE parent_id bigint; parent_status text;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        SELECT recipe_id, status INTO parent_id, parent_status FROM recipe_revisions WHERE id = OLD.recipe_revision_id FOR SHARE;
        IF parent_status <> 'draft' THEN
            RAISE EXCEPTION 'Retained recipe dependencies are immutable.' USING ERRCODE = 'integrity_constraint_violation';
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    SELECT recipe_id, status INTO parent_id, parent_status FROM recipe_revisions WHERE id = NEW.recipe_revision_id FOR SHARE;
    IF parent_status <> 'draft' THEN
        RAISE EXCEPTION 'Dependencies must be recorded before the parent revision is frozen.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    IF parent_id = NEW.component_recipe_id THEN
        RAISE EXCEPTION 'A recipe cannot depend on itself.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    PERFORM 1 FROM recipe_revisions WHERE id = NEW.component_recipe_revision_id
        AND recipe_id = NEW.component_recipe_id AND status IN ('published', 'superseded') FOR SHARE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'A dependency must reference a retained published revision.' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER recipe_revision_dependencies_guard BEFORE INSERT OR UPDATE OR DELETE ON recipe_revision_dependencies FOR EACH ROW EXECUTE FUNCTION recipe_revision_dependency_guard();
SQL);
    }
};
