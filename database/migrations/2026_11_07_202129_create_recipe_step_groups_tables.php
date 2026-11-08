<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_step_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->unique(['recipe_id', 'id']);
            $table->index(['recipe_id', 'position', 'id'], 'recipe_step_groups_order_index');
        });
        Schema::create('recipe_step_group_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_step_group_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['recipe_step_group_id', 'locale'], 'recipe_step_group_translations_locale_unique');
        });
        Schema::table('recipe_steps', function (Blueprint $table): void {
            $table->foreignId('recipe_step_group_id')->nullable();
            $table->foreign(['recipe_id', 'recipe_step_group_id'], 'recipe_steps_recipe_step_group_foreign')
                ->references(['recipe_id', 'id'])->on('recipe_step_groups')->restrictOnDelete();
            $table->index(['recipe_id', 'recipe_step_group_id', 'step_number'], 'recipe_steps_group_order_index');
        });
        DB::statement('ALTER TABLE recipe_step_groups ADD CONSTRAINT recipe_step_groups_position_check CHECK (position >= 0)');
        DB::statement("ALTER TABLE recipe_step_group_translations ADD CONSTRAINT recipe_step_group_translations_locale_check CHECK (locale IN ('en', 'el'))");

        // Reuse the existing publisher guard without changing its owner resolver or historical migrations.
        DB::unprepared(<<<'SQL'
CREATE FUNCTION recipe_step_group_translation_guard() RETURNS trigger AS $$
DECLARE
    owner_id bigint;
    group_id bigint;
    guard_mode text;
    publisher text;
BEGIN
    SELECT value INTO guard_mode FROM recipe_revision_settings WHERE key = 'content_guard';
    publisher := current_setting('app.recipe_publisher', true);
    FOR group_id IN SELECT DISTINCT id FROM unnest(ARRAY[
        CASE WHEN TG_OP <> 'INSERT' THEN OLD.recipe_step_group_id END,
        CASE WHEN TG_OP <> 'DELETE' THEN NEW.recipe_step_group_id END
    ]) AS groups(id) LOOP
        SELECT recipe_id INTO owner_id FROM recipe_step_groups WHERE id = group_id;
        IF COALESCE(guard_mode, 'monitor') <> 'off'
           AND owner_id::text IS DISTINCT FROM publisher
           AND EXISTS (SELECT 1 FROM recipes WHERE id = owner_id AND status = 'published' AND visibility = 'public' AND deleted_at IS NULL) THEN
            IF guard_mode = 'enforce' THEN
                RAISE EXCEPTION 'Published recipe % can only be changed through a reviewed revision.', owner_id USING ERRCODE = 'integrity_constraint_violation';
            END IF;
            RAISE WARNING 'recipe_content_guard: unreviewed % on % for published recipe %', TG_OP, TG_TABLE_NAME, owner_id;
        END IF;
    END LOOP;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER recipe_step_groups_content_guard BEFORE INSERT OR UPDATE OR DELETE ON recipe_step_groups
    FOR EACH ROW EXECUTE FUNCTION recipe_content_guard();
CREATE TRIGGER recipe_step_group_translations_content_guard BEFORE INSERT OR UPDATE OR DELETE ON recipe_step_group_translations
    FOR EACH ROW EXECUTE FUNCTION recipe_step_group_translation_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::table('recipe_step_groups')->exists()) {
            throw new LogicException('Step groups contain recipe content; rollback would destroy it.');
        }
        Schema::table('recipe_steps', function (Blueprint $table): void {
            $table->dropForeign('recipe_steps_recipe_step_group_foreign');
            $table->dropIndex('recipe_steps_group_order_index');
            $table->dropColumn('recipe_step_group_id');
        });
        Schema::dropIfExists('recipe_step_group_translations');
        Schema::dropIfExists('recipe_step_groups');
        DB::unprepared('DROP FUNCTION IF EXISTS recipe_step_group_translation_guard()');
    }
};
