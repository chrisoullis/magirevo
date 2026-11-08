<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\RecipeStepGroup;
use App\Services\RecipeContentGuard;
use App\Services\Recipes\RecipeStepGroups;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecipeStepGroupsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_groups_store_bilingual_titles_and_optional_descriptions(): void
    {
        $group = RecipeStepGroup::factory()->create();
        $group->fill([
            'en' => ['title' => 'Dough', 'description' => 'Let it rise.'],
            'el' => ['title' => 'Ζύμη', 'description' => null],
        ])->save();

        $this->assertSame('Dough', $group->fresh()->translate('en')->title);
        $this->assertSame('Ζύμη', $group->fresh()->translate('el')->title);
        $this->assertNull($group->fresh()->translate('el')->description);
    }

    public function test_mixed_steps_use_ungrouped_then_group_position_and_preserve_step_numbers(): void
    {
        $recipe = Recipe::factory()->create();
        $later = RecipeStepGroup::factory()->for($recipe)->create(['position' => 2]);
        $earlier = RecipeStepGroup::factory()->for($recipe)->create(['position' => 1]);
        $a = RecipeStep::factory()->for($recipe)->create(['step_number' => 1, 'recipe_step_group_id' => $later->id]);
        $b = RecipeStep::factory()->for($recipe)->create(['step_number' => 2]);
        $c = RecipeStep::factory()->for($recipe)->create(['step_number' => 3, 'recipe_step_group_id' => $earlier->id]);

        $this->assertSame([$b->id, $c->id, $a->id], app(RecipeStepGroups::class)->orderedSteps($recipe)->modelKeys());
        $this->assertSame([1, 2, 3], $recipe->steps()->pluck('step_number')->all());
        $earlier->update(['position' => 3]);
        $this->assertSame([$b->id, $a->id, $c->id], app(RecipeStepGroups::class)->orderedSteps($recipe)->modelKeys());
    }

    public function test_ungrouped_recipes_keep_legacy_order(): void
    {
        $recipe = Recipe::factory()->create();
        $second = RecipeStep::factory()->for($recipe)->create(['step_number' => 2]);
        $first = RecipeStep::factory()->for($recipe)->create(['step_number' => 1]);

        $this->assertSame([$first->id, $second->id], app(RecipeStepGroups::class)->orderedSteps($recipe)->modelKeys());
        $this->assertCount(0, $recipe->stepGroups);
    }

    public function test_confirmed_group_removal_preserves_steps_translations_and_media(): void
    {
        $group = RecipeStepGroup::factory()->create();
        $step = RecipeStep::factory()->for($group->recipe)->create(['recipe_step_group_id' => $group->id]);
        $step->fill(['en' => ['instruction' => 'Mix.'], 'el' => ['instruction' => 'Ανακατέψτε.']])->save();
        $media = Media::factory()->create();
        $step->media()->attach($media, ['kind' => 'image', 'sort_order' => 0]);

        app(RecipeStepGroups::class)->remove($group->recipe, $group->id, confirmed: true);

        $this->assertModelMissing($group);
        $this->assertModelExists($step);
        $this->assertNull($step->fresh()->recipe_step_group_id);
        $this->assertSame('Mix.', $step->fresh()->translate('en')->instruction);
        $this->assertSame([$media->id], $step->fresh()->media->modelKeys());
    }

    public function test_group_removal_can_move_steps_to_another_group(): void
    {
        $group = RecipeStepGroup::factory()->create();
        $destination = RecipeStepGroup::factory()->for($group->recipe)->create();
        $step = RecipeStep::factory()->for($group->recipe)->create(['recipe_step_group_id' => $group->id]);

        app(RecipeStepGroups::class)->remove($group->recipe, $group->id, true, $destination->id);

        $this->assertSame($destination->id, $step->fresh()->recipe_step_group_id);
        $this->assertModelMissing($group);
    }

    public function test_removal_without_confirmation_leaves_content_unchanged(): void
    {
        $group = RecipeStepGroup::factory()->create();
        try {
            app(RecipeStepGroups::class)->remove($group->recipe, $group->id, false);
            $this->fail('Group removal requires confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('step_groups', $exception->errors());
        }
        $this->assertModelExists($group);
    }

    public function test_model_rejects_cross_recipe_group_assignment(): void
    {
        $group = RecipeStepGroup::factory()->create();
        $step = RecipeStep::factory()->create();

        $this->expectException(ValidationException::class);
        $step->update(['recipe_step_group_id' => $group->id]);
    }

    public function test_database_rejects_cross_recipe_group_assignment_when_model_is_bypassed(): void
    {
        $group = RecipeStepGroup::factory()->create();
        $step = RecipeStep::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('recipe_steps')->where('id', $step->id)->update(['recipe_step_group_id' => $group->id]);
    }

    public function test_direct_group_deletion_cannot_silently_delete_steps(): void
    {
        $group = RecipeStepGroup::factory()->create();
        RecipeStep::factory()->for($group->recipe)->create(['recipe_step_group_id' => $group->id]);

        $this->expectException(QueryException::class);
        $group->delete();
    }

    public function test_guard_blocks_group_edits_outside_the_publisher(): void
    {
        $group = RecipeStepGroup::factory()->for(Recipe::factory()->complete())->create();
        $group->recipe->update(['status' => 'published', 'visibility' => 'public', 'published_at' => now()]);
        app(RecipeContentGuard::class)->setMode('enforce');

        $this->expectException(QueryException::class);
        $group->update(['position' => 3]);
    }

    public function test_guard_blocks_translation_edits_outside_the_publisher(): void
    {
        $group = RecipeStepGroup::factory()->for(Recipe::factory()->complete())->create();
        $group->recipe->update(['status' => 'published', 'visibility' => 'public', 'published_at' => now()]);
        app(RecipeContentGuard::class)->setMode('enforce');

        $this->expectException(QueryException::class);
        $group->fill(['en' => ['title' => 'Unreviewed']])->save();
    }

    public function test_existing_publisher_scope_allows_group_translations(): void
    {
        $group = RecipeStepGroup::factory()->for(Recipe::factory()->complete())->create();
        $group->recipe->update(['status' => 'published', 'visibility' => 'public', 'published_at' => now()]);
        $guard = app(RecipeContentGuard::class);
        $guard->setMode('enforce');

        $guard->asPublisher($group->recipe_id, fn () => $group->fill(['en' => ['title' => 'Reviewed']])->save());

        $this->assertSame('Reviewed', $group->fresh()->translate('en')->title);
    }

    public function test_removal_rejects_a_destination_from_another_recipe_without_changing_steps(): void
    {
        $group = RecipeStepGroup::factory()->create();
        $other = RecipeStepGroup::factory()->create();
        $step = RecipeStep::factory()->for($group->recipe)->create(['recipe_step_group_id' => $group->id]);
        try {
            app(RecipeStepGroups::class)->remove($group->recipe, $group->id, true, $other->id);
            $this->fail('A destination must belong to the recipe.');
        } catch (ModelNotFoundException) {
            $this->assertModelExists($group);
            $this->assertSame($group->id, $step->fresh()->recipe_step_group_id);
        }
    }

    public function test_group_removal_rejects_itself_as_destination(): void
    {
        $group = RecipeStepGroup::factory()->create();

        $this->expectException(ValidationException::class);
        app(RecipeStepGroups::class)->remove($group->recipe, $group->id, true, $group->id);
    }

    public function test_steps_can_move_between_groups_without_changing_their_identity(): void
    {
        $group = RecipeStepGroup::factory()->create();
        $other = RecipeStepGroup::factory()->for($group->recipe)->create();
        $step = RecipeStep::factory()->for($group->recipe)->create(['recipe_step_group_id' => $group->id]);

        $step->update(['recipe_step_group_id' => $other->id]);

        $this->assertTrue($step->fresh()->stepGroup->is($other));
        $this->assertSame([$step->id], $other->steps->modelKeys());
        $this->assertCount(0, $group->fresh()->steps);
    }

    public function test_group_positions_reject_negative_values(): void
    {
        $this->expectException(QueryException::class);
        RecipeStepGroup::factory()->create(['position' => -1]);
    }

    public function test_group_translations_reject_unsupported_locales(): void
    {
        $group = RecipeStepGroup::factory()->create();

        $this->expectException(QueryException::class);
        $group->translations()->create(['locale' => 'fr', 'title' => 'Pâte']);
    }

    public function test_group_translations_reject_duplicate_locales(): void
    {
        $group = RecipeStepGroup::factory()->create();
        $group->translations()->create(['locale' => 'en', 'title' => 'Dough']);

        $this->expectException(QueryException::class);
        $group->translations()->create(['locale' => 'en', 'title' => 'Filling']);
    }

    public function test_equal_group_positions_have_a_stable_id_tiebreaker(): void
    {
        $recipe = Recipe::factory()->create();
        $first = RecipeStepGroup::factory()->for($recipe)->create();
        $second = RecipeStepGroup::factory()->for($recipe)->create();
        $laterStep = RecipeStep::factory()->for($recipe)->create(['step_number' => 1, 'recipe_step_group_id' => $second->id]);
        $earlierStep = RecipeStep::factory()->for($recipe)->create(['step_number' => 2, 'recipe_step_group_id' => $first->id]);

        $this->assertSame([$earlierStep->id, $laterStep->id], app(RecipeStepGroups::class)->orderedSteps($recipe)->modelKeys());
    }

    public function test_rollback_refuses_to_destroy_group_content(): void
    {
        RecipeStepGroup::factory()->create();
        $migration = require database_path('migrations/2026_11_07_202129_create_recipe_step_groups_tables.php');

        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
