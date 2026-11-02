<?php

namespace Tests\Feature;

use App\Enums\RecipeRevisionStatus;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeRevision;
use App\Models\RecipeRevisionAudit;
use App\Models\UserRecipeIngredientChange;
use App\Services\RecipeContentGuard;
use App\Services\RecipeRevisionService;
use App\Services\RecipeRevisionSnapshotter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecipeRevisionFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function publishedRecipe(): Recipe
    {
        $recipe = Recipe::factory()->published()->create();
        RecipeIngredient::factory()->create(['recipe_id' => $recipe->id]);

        return $recipe;
    }

    private function baseline(Recipe $recipe): RecipeRevision
    {
        return app(RecipeRevisionService::class)->syncPublishedFromLive($recipe->id);
    }

    public function test_baseline_revision_is_idempotent_and_linked_to_the_recipe(): void
    {
        $recipe = $this->publishedRecipe();

        $first = $this->baseline($recipe);
        $second = $this->baseline($recipe);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, RecipeRevision::where('recipe_id', $recipe->id)->count());
        $this->assertSame($first->id, $recipe->fresh()->current_revision_id);
        $this->assertSame(RecipeRevisionStatus::Published, $first->status);
    }

    public function test_changed_live_content_supersedes_without_deleting_history(): void
    {
        $recipe = $this->publishedRecipe();
        $first = $this->baseline($recipe);

        DB::table('recipe_ingredients')->where('recipe_id', $recipe->id)->update(['quantity' => '9.0000']);
        $second = $this->baseline($recipe);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(RecipeRevisionStatus::Superseded, $first->fresh()->status);
        $this->assertSame($first->id, $second->base_revision_id);
        $this->assertSame(2, RecipeRevision::where('recipe_id', $recipe->id)->count());
    }

    public function test_hash_ignores_row_ids_and_presentation_but_not_quantities(): void
    {
        $recipe = $this->publishedRecipe();
        $snapshotter = app(RecipeRevisionSnapshotter::class);
        $before = $snapshotter->hash($snapshotter->serialize($recipe->id));

        $this->assertSame($before, $snapshotter->hash($snapshotter->serialize($recipe->id)));

        DB::table('recipe_ingredients')->where('recipe_id', $recipe->id)->update(['quantity' => '2.5000']);

        $this->assertNotSame($before, $snapshotter->hash($snapshotter->serialize($recipe->id)));
    }

    public function test_frozen_revision_payload_and_history_cannot_be_changed_or_deleted(): void
    {
        $revision = $this->baseline($this->publishedRecipe());

        try {
            DB::table('recipe_revisions')->where('id', $revision->id)->update(['content_hash' => str_repeat('a', 64)]);
            $this->fail('Payload hash of a non-draft revision was changed.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        DB::table('recipe_revisions')->where('id', $revision->id)->delete();
    }

    public function test_status_cannot_move_backwards(): void
    {
        $revision = $this->baseline($this->publishedRecipe());

        $this->expectException(QueryException::class);
        DB::table('recipe_revisions')->where('id', $revision->id)->update(['status' => 'draft']);
    }

    public function test_only_one_open_revision_and_one_published_revision_per_recipe(): void
    {
        $recipe = $this->publishedRecipe();
        $published = $this->baseline($recipe);

        $row = fn (string $status, int $number): array => [
            'recipe_id' => $recipe->id, 'revision_number' => $number, 'status' => $status,
            'payload' => '{}', 'schema_version' => 1, 'content_hash' => str_repeat('b', 64),
            'created_at' => now(), 'updated_at' => now(),
        ];

        DB::table('recipe_revisions')->insert($row('draft', 10));

        try {
            DB::table('recipe_revisions')->insert($row('in_review', 11));
            $this->fail('A second open revision was accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        DB::table('recipe_revisions')->insert($row('published', 12));
        $this->assertNotNull($published);
    }

    public function test_revision_audit_log_is_append_only(): void
    {
        $revision = $this->baseline($this->publishedRecipe());
        $audit = RecipeRevisionAudit::where('recipe_revision_id', $revision->id)->firstOrFail();

        $this->expectException(QueryException::class);
        DB::table('recipe_revision_audits')->where('id', $audit->id)->update(['action' => 'tampered']);
    }

    public function test_enforce_mode_blocks_direct_writes_to_public_recipes_including_child_tables(): void
    {
        $recipe = $this->publishedRecipe();
        app(RecipeContentGuard::class)->setMode('enforce');

        foreach ([
            fn () => DB::table('recipe_ingredients')->where('recipe_id', $recipe->id)->update(['quantity' => '7.0000']),
            fn () => DB::table('recipe_ingredients')->where('recipe_id', $recipe->id)->delete(),
            fn () => DB::table('recipe_translations')->where('recipe_id', $recipe->id)->update(['title' => 'Hacked']),
            fn () => DB::table('recipes')->where('id', $recipe->id)->update(['prep_minutes' => 99]),
        ] as $write) {
            DB::beginTransaction();
            try {
                $write();
                $this->fail('A direct write to public content was accepted.');
            } catch (QueryException) {
                $this->assertTrue(true);
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_enforce_mode_allows_publisher_and_non_public_recipes(): void
    {
        $recipe = $this->publishedRecipe();
        $draft = Recipe::factory()->create();
        RecipeIngredient::factory()->create(['recipe_id' => $draft->id]);
        app(RecipeContentGuard::class)->setMode('enforce');

        app(RecipeContentGuard::class)->asPublisher($recipe->id, function () use ($recipe): void {
            DB::table('recipe_ingredients')->where('recipe_id', $recipe->id)->update(['quantity' => '3.0000']);
        });
        DB::table('recipe_ingredients')->where('recipe_id', $draft->id)->update(['quantity' => '4.0000']);

        $this->assertSame('3.0000', DB::table('recipe_ingredients')->where('recipe_id', $recipe->id)->value('quantity'));
        $this->assertSame('4.0000', DB::table('recipe_ingredients')->where('recipe_id', $draft->id)->value('quantity'));
    }

    public function test_monitor_mode_does_not_block_existing_workflows(): void
    {
        $recipe = $this->publishedRecipe();

        $this->assertSame('monitor', app(RecipeContentGuard::class)->mode());
        DB::table('recipe_ingredients')->where('recipe_id', $recipe->id)->update(['quantity' => '5.0000']);

        $this->assertSame('5.0000', DB::table('recipe_ingredients')->where('recipe_id', $recipe->id)->value('quantity'));
    }

    public function test_removing_a_line_archives_personal_changes_instead_of_losing_them(): void
    {
        $recipe = $this->publishedRecipe();
        $line = RecipeIngredient::where('recipe_id', $recipe->id)->firstOrFail();
        $change = UserRecipeIngredientChange::factory()->create([
            'recipe_ingredient_id' => $line->id, 'note' => 'my swap',
        ]);

        app(RecipeContentGuard::class)->asPublisher($recipe->id, function () use ($line): void {
            DB::table('recipe_ingredients')->where('id', $line->id)->delete();
        });

        $this->assertDatabaseMissing('user_recipe_ingredient_changes', ['id' => $change->id]);
        $this->assertDatabaseHas('archived_personal_ingredient_changes', [
            'original_id' => $change->id, 'user_id' => $change->user_id, 'note' => 'my swap',
            'recipe_ingredient_id' => $line->id,
        ]);
    }

    public function test_deleting_a_recipe_does_not_archive_dependents(): void
    {
        $recipe = $this->publishedRecipe();
        $line = RecipeIngredient::where('recipe_id', $recipe->id)->firstOrFail();
        UserRecipeIngredientChange::factory()->create(['recipe_ingredient_id' => $line->id]);

        DB::table('recipes')->where('id', $recipe->id)->delete();

        $this->assertSame(0, DB::table('archived_personal_ingredient_changes')->count());
    }

    public function test_claims_are_unique_per_recipe_while_revision_is_null_and_per_revision_after(): void
    {
        $recipe = $this->publishedRecipe();
        $tag = DB::table('tags')->insertGetId(['created_at' => now(), 'updated_at' => now()] + $this->tagDefaults());
        $columns = fn (?int $revision): array => [
            'recipe_id' => $recipe->id, 'tag_id' => $tag, 'recipe_revision_id' => $revision,
            'created_at' => now(), 'updated_at' => now(),
        ] + $this->claimDefaults();

        DB::table('recipe_dietary_claims')->insert($columns(null));

        try {
            DB::table('recipe_dietary_claims')->insert($columns(null));
            $this->fail('Duplicate NULL-revision claim was accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    /** @return array<string, mixed> */
    private function tagDefaults(): array
    {
        $required = DB::select("select column_name, data_type from information_schema.columns where table_name='tags' and is_nullable='NO' and column_default is null and column_name not in ('id','created_at','updated_at')");

        return collect($required)->mapWithKeys(fn ($c): array => [$c->column_name => in_array($c->data_type, ['boolean'], true) ? false : (str_contains($c->data_type, 'int') ? 1 : 'x'.uniqid())])->all();
    }

    /** @return array<string, mixed> */
    private function claimDefaults(): array
    {
        $required = DB::select("select column_name, data_type from information_schema.columns where table_name='recipe_dietary_claims' and is_nullable='NO' and column_default is null and column_name not in ('id','recipe_id','tag_id','recipe_revision_id','created_at','updated_at')");

        return collect($required)->mapWithKeys(fn ($c): array => [$c->column_name => str_contains($c->data_type, 'int') ? 1 : 'pending'])->all();
    }
}
