<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\RecipeStepGroup;
use App\Models\User;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionComparer;
use App\Services\RecipeRevisionService;
use App\Services\RecipeRevisionSnapshotter;
use App\Services\RecipeRevisionValidator;
use App\Services\Recipes\RecipeEditorData;
use App\Services\Recipes\RecipeEditorService;
use App\Services\Recipes\RecipeForkService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\CreatesVariationSource;
use Tests\TestCase;

class CookingStepRevisionTest extends TestCase
{
    use CreatesVariationSource;
    use DatabaseTransactions;

    private function groupedRecipe(): Recipe
    {
        $recipe = Recipe::factory()->complete()->create();
        $this->addGroup($recipe);

        return $recipe;
    }

    private function addGroup(Recipe $recipe): void
    {
        $group = RecipeStepGroup::factory()->for($recipe)->create([
            'en' => ['title' => 'Dough', 'description' => 'Rest the dough.'],
            'el' => ['title' => 'Ζύμη', 'description' => 'Αφήστε τη ζύμη να ξεκουραστεί.'],
        ]);
        $recipe->steps()->first()->update(['recipe_step_group_id' => $group->id]);
    }

    public function test_serialized_group_fields_normalize_without_content_changes(): void
    {
        $recipe = $this->groupedRecipe();
        $snapshots = app(RecipeRevisionSnapshotter::class);
        $payload = $snapshots->serialize($recipe->id);

        $this->assertSame('Ζύμη', $payload['step_groups'][0]['translations']['el']['title']);
        $this->assertSame(0, $payload['steps'][0]['step_group_index']);
        $normalized = $snapshots->normalize($payload);
        $this->assertSame(
            $snapshots->hash(['step_groups' => $payload['step_groups'], 'steps' => $payload['steps']]),
            $snapshots->hash(['step_groups' => $normalized['step_groups'], 'steps' => $normalized['steps']]),
        );
    }

    public function test_hash_covers_group_metadata_and_membership_but_ignores_live_group_ids(): void
    {
        $snapshots = app(RecipeRevisionSnapshotter::class);
        $payload = $snapshots->serialize($this->groupedRecipe()->id);
        $hash = $snapshots->hash($payload);
        $payload['step_groups'][0]['id'] = 987654;
        $this->assertSame($hash, $snapshots->hash($payload));
        $payload['step_groups'][0]['translations']['el']['description'] = 'Άλλη περιγραφή.';
        $this->assertNotSame($hash, $snapshots->hash($payload));
        $payload = $snapshots->serialize($this->groupedRecipe()->id);
        $hash = $snapshots->hash($payload);
        $payload['steps'][0]['step_group_index'] = null;
        $this->assertNotSame($hash, $snapshots->hash($payload));
    }

    public function test_legacy_hash_does_not_gain_empty_group_fields(): void
    {
        $payload = ['ingredient_groups' => [], 'ingredients' => [], 'steps' => [['id' => 12, 'step_number' => 1]]];
        $expected = hash('sha256', '{"ingredient_groups":[],"ingredients":[],"steps":[{"step_number":1}]}');
        $snapshots = app(RecipeRevisionSnapshotter::class);

        $this->assertSame($expected, $snapshots->hash($payload));
        $this->assertSame($expected, $snapshots->hash($snapshots->forSchema([...$payload, 'step_groups' => []], 2)));
    }

    public function test_schema_one_and_two_adapters_remove_only_new_group_fields(): void
    {
        $snapshots = app(RecipeRevisionSnapshotter::class);
        $payload = $snapshots->serialize($this->groupedRecipe()->id);

        $v2 = $snapshots->forSchema($payload, 2);
        $v1 = $snapshots->forSchema($payload, 1);

        $this->assertArrayNotHasKey('step_groups', $v2);
        $this->assertArrayNotHasKey('step_group_index', $v2['steps'][0]);
        $this->assertArrayHasKey('line_uuid', $v2['ingredients'][0]);
        $this->assertArrayNotHasKey('line_uuid', $v1['ingredients'][0]);
        $this->assertArrayNotHasKey('units', $v1);
    }

    public function test_publication_requires_bilingual_group_titles(): void
    {
        $recipe = $this->groupedRecipe();
        $payload = app(RecipeRevisionSnapshotter::class)->serialize($recipe->id);
        $payload['step_groups'][0]['translations']['el']['title'] = '';

        $this->expectException(ValidationException::class);
        app(RecipeRevisionValidator::class)->assertSubmittable($payload, $recipe->id);
    }

    public function test_publication_rejects_a_missing_group_reference(): void
    {
        $recipe = $this->groupedRecipe();
        $payload = app(RecipeRevisionSnapshotter::class)->serialize($recipe->id);
        $payload['steps'][0]['step_group_index'] = 999;

        $this->expectException(ValidationException::class);
        app(RecipeRevisionValidator::class)->assertSubmittable($payload, $recipe->id);
    }

    #[TestWith([0.5])]
    #[TestWith(['invalid'])]
    #[TestWith([-1])]
    public function test_normalization_does_not_turn_invalid_membership_into_a_valid_group(mixed $reference): void
    {
        $recipe = $this->groupedRecipe();
        $snapshots = app(RecipeRevisionSnapshotter::class);
        $payload = $snapshots->serialize($recipe->id);
        $payload['steps'][0]['step_group_index'] = $reference;

        $this->expectException(ValidationException::class);
        app(RecipeRevisionValidator::class)->assertSubmittable($snapshots->normalize($payload), $recipe->id);
    }

    public function test_draft_group_changes_stay_isolated_then_publish_with_identical_hashes(): void
    {
        $author = User::factory()->contributor()->create();
        $recipe = $this->groupedRecipe();
        $recipe->update(['author_id' => $author->id]);
        $reviewer = User::factory()->admin()->create();
        $this->actingAs($reviewer);
        app(RecipePublishingService::class)->publish($recipe);
        $recipe->refresh();
        $original = $recipe->currentRevision;
        $originalPayload = $original->payload;
        $this->actingAs($author);
        $revisions = app(RecipeRevisionService::class);
        $draft = $revisions->createDraft($author, $recipe);
        $data = RecipeEditorData::fromPayload($draft->payload);
        $data['step_groups_data'][0]['translations']['en']['title'] = 'Prepare dough';
        $draft = app(RecipeEditorService::class)->saveRevision($author, $draft, $data, $revisions->draftVersion($draft));
        $this->assertSame('Dough', $recipe->fresh()->stepGroups[0]->translate('en')->title);

        $draft = $revisions->submit($author, $draft);
        $this->actingAs($reviewer);
        $published = $revisions->approve($reviewer, $draft);
        $snapshots = app(RecipeRevisionSnapshotter::class);

        $this->assertSame(3, $published->schema_version);
        $this->assertSame($published->content_hash, $snapshots->hash($snapshots->serialize($recipe->id)));
        $this->assertSame($originalPayload, $original->fresh()->payload);
        $this->assertSame('Prepare dough', $recipe->fresh()->stepGroups[0]->translate('en')->title);
        $this->assertSame($originalPayload['steps'][0]['id'], $recipe->steps()->first()->id);
        $this->assertSame($originalPayload['step_groups'][0]['id'], $recipe->fresh()->stepGroups[0]->id);

        $restored = $revisions->rollback($reviewer, $original->fresh(), 'Restore the original cooking stage.');
        $this->assertSame($snapshots->hash($snapshots->normalize($originalPayload)), $restored->content_hash);
        $this->assertSame('Dough', $recipe->fresh()->stepGroups[0]->translate('en')->title);
        $this->assertSame($originalPayload, $original->fresh()->payload);
    }

    public function test_rollback_to_a_legacy_snapshot_clears_groups_without_rewriting_history(): void
    {
        $reviewer = User::factory()->admin()->create();
        $this->actingAs($reviewer);
        $recipe = $this->groupedRecipe();
        $snapshots = app(RecipeRevisionSnapshotter::class);
        $legacyPayload = $snapshots->forSchema($snapshots->normalize($snapshots->serialize($recipe->id)), 2);
        $media = $snapshots->snapshotMedia($legacyPayload);
        $legacy = RecipeRevision::create([
            'recipe_id' => $recipe->id, 'revision_number' => 1, 'status' => 'superseded',
            'schema_version' => 2, 'payload' => $legacyPayload, 'content_hash' => $snapshots->hash($legacyPayload),
            'media_snapshot' => $media, 'media_snapshot_hash' => $snapshots->mediaSnapshotHash($media),
        ]);
        app(RecipePublishingService::class)->publish($recipe);
        $legacyBefore = $legacy->fresh()->getRawOriginal();
        $stepId = $recipe->steps()->first()->id;

        $restored = app(RecipeRevisionService::class)->rollback($reviewer, $legacy, 'Restore the older ungrouped instructions.');

        $this->assertSame(3, $restored->schema_version);
        $this->assertSame([], $restored->payload['step_groups']);
        $this->assertSame($stepId, $recipe->steps()->first()->id);
        $this->assertNull($recipe->steps()->first()->recipe_step_group_id);
        $this->assertCount(0, $recipe->fresh()->stepGroups);
        $this->assertSame($legacyBefore, $legacy->fresh()->getRawOriginal());
    }

    public function test_group_removal_requires_confirmation_and_keeps_step_ids_and_media(): void
    {
        $recipe = $this->groupedRecipe();
        $actor = User::factory()->contributor()->create();
        $recipe->update(['author_id' => $actor->id]);
        $this->actingAs($actor);
        $editor = app(RecipeEditorService::class);
        $photo = Media::factory()->remote()->create([
            'en' => ['alt_text' => 'Dough photograph', 'caption' => 'Before resting'],
            'el' => ['alt_text' => 'Φωτογραφία ζύμης', 'caption' => 'Πριν την ξεκούραση'],
        ]);
        $video = Media::query()->create([
            'disk' => 'remote', 'source' => 'embed', 'media_type' => 'video',
            'url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'mime_type' => 'video/embed',
            'en' => ['alt_text' => 'Mix dough', 'caption' => 'Mix gently'],
            'el' => ['alt_text' => 'Ανακατέψτε τη ζύμη', 'caption' => 'Ανακατέψτε απαλά'],
        ]);
        $recipe->steps()->first()->media()->attach($photo, ['kind' => 'image', 'sort_order' => 0]);
        $recipe->steps()->first()->media()->attach($video, ['kind' => 'video', 'sort_order' => 1]);
        $data = RecipeEditorData::fromRecipe($recipe);
        $stepId = $recipe->steps()->first()->id;
        $groupId = $recipe->stepGroups()->first()->id;
        $data['step_groups_data'] = [];
        $data['steps_data'][0]['step_group_key'] = null;
        try {
            $editor->update($actor, $recipe, $data, $editor->version($recipe));
            $this->fail('Deletion requires explicit confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('step_groups_data', $exception->errors());
        }
        $data['step_group_removals'] = [$groupId];

        $editor->update($actor, $recipe, $data, $editor->version($recipe));

        $this->assertSame($stepId, $recipe->steps()->first()->id);
        $this->assertNull($recipe->steps()->first()->recipe_step_group_id);
        $this->assertCount(0, $recipe->fresh()->stepGroups);
        $this->assertSame([$photo->id, $video->id], $recipe->steps()->first()->media->modelKeys());
        $this->assertSame('Φωτογραφία ζύμης', $photo->fresh()->translate('el')->alt_text);
        $this->assertSame('Ανακατέψτε απαλά', $video->fresh()->translate('el')->caption);
    }

    public function test_group_changes_invalidate_the_mutable_editor_version(): void
    {
        $recipe = $this->groupedRecipe();
        $editor = app(RecipeEditorService::class);
        $before = $editor->version($recipe);

        $recipe->stepGroups()->first()->fill(['en' => ['title' => 'Changed']])->save();

        $this->assertNotSame($before, $editor->version($recipe));
    }

    public function test_review_diff_includes_metadata_and_membership_changes(): void
    {
        $payload = app(RecipeRevisionSnapshotter::class)->serialize($this->groupedRecipe()->id);
        $after = $payload;
        $after['step_groups'][0]['translations']['el']['title'] = 'Γέμιση';
        $after['steps'][0]['step_group_index'] = null;

        $changes = app(RecipeRevisionComparer::class)->compare($payload, $after);

        $this->assertContains(__('cooking.groups'), array_column($changes, 'section'));
        $this->assertContains('Steps', array_column($changes, 'section'));
    }

    public function test_rebasing_a_copied_payload_drops_source_group_and_step_ids(): void
    {
        $snapshots = app(RecipeRevisionSnapshotter::class);
        $payload = $snapshots->serialize($this->groupedRecipe()->id);

        $rebased = $snapshots->rebaseIds($payload, Recipe::factory()->create()->id);

        $this->assertNull($rebased['step_groups'][0]['id']);
        $this->assertNull($rebased['steps'][0]['id']);
        $this->assertSame(0, $rebased['steps'][0]['step_group_index']);
    }

    public function test_permitted_fork_copies_groups_with_new_ids_and_excludes_media(): void
    {
        config(['recipe-variations.enabled' => true]);
        $this->actingAs(User::factory()->admin()->create());
        $source = $this->source(fn (Recipe $recipe) => $this->addGroup($recipe));
        $actor = User::factory()->create();

        $fork = app(RecipeForkService::class)->fork($actor, $source, ['idempotency_key' => (string) Str::uuid()]);

        $this->assertNotSame($source->stepGroups[0]->id, $fork->stepGroups[0]->id);
        $this->assertNotSame($source->steps[0]->id, $fork->steps[0]->id);
        $this->assertSame($fork->stepGroups[0]->id, $fork->steps[0]->recipe_step_group_id);
        $this->assertSame('Ζύμη', $fork->stepGroups[0]->translate('el')->title);
        $this->assertCount(0, $fork->steps[0]->media);
        $this->assertSame($source->current_revision_id, $fork->derivation->source_recipe_revision_id);
    }
}
