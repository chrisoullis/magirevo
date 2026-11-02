<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\User;
use App\Services\RecipeContentPresenter;
use App\Services\RecipeMediaMetadata;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class RecipeMediaSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private Recipe $recipe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->contributor()->create();
        $this->recipe = Recipe::factory()->complete()->create(['author_id' => $this->author->id]);
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($this->recipe);
        $this->recipe->refresh();
    }

    private function service(): RecipeRevisionService
    {
        return app(RecipeRevisionService::class);
    }

    private function media(): Media
    {
        $media = Media::factory()->remote()->create();
        $media->translateOrNew('en')->fill(['alt_text' => 'Reviewed English alt', 'caption' => 'Reviewed English caption']);
        $media->translateOrNew('el')->fill(['alt_text' => 'Εγκεκριμένο εναλλακτικό', 'caption' => 'Εγκεκριμένη λεζάντα']);
        $media->save();

        return $media;
    }

    private function publishWithMedia(Media $media, string $role = 'cover'): RecipeRevision
    {
        $draft = $this->service()->createDraft($this->author, $this->recipe);
        $payload = $draft->payload;
        $payload['media'] = [['media_id' => $media->id, 'role' => $role, 'sort_order' => 0]];
        $revision = $this->service()->submit($this->author, $this->service()->saveDraft($this->author, $draft, $payload));

        return $this->service()->approve(User::factory()->admin()->create(), $revision);
    }

    private function presented(): Media
    {
        $fresh = Recipe::findOrFail($this->recipe->id);

        return app(RecipeMediaMetadata::class)->present($fresh, $fresh->media()->with('translations')->get())->firstOrFail();
    }

    public function test_published_revision_keeps_english_and_greek_text_when_shared_media_is_edited(): void
    {
        $media = $this->media();
        $this->publishWithMedia($media);

        $media->translate('en')->update(['alt_text' => 'Vandalised', 'caption' => 'Vandalised caption']);
        $media->translate('el')->update(['alt_text' => 'Αλλαγμένο']);
        $media->update(['credit' => 'Someone else']);

        $shown = $this->presented();

        $this->assertSame('Reviewed English alt', $shown->translate('en')->alt_text);
        $this->assertSame('Reviewed English caption', $shown->translate('en')->caption);
        $this->assertSame('Εγκεκριμένο εναλλακτικό', $shown->translate('el')->alt_text);
        $this->assertSame('Εγκεκριμένη λεζάντα', $shown->translate('el')->caption);
        $this->assertNull($shown->credit);
        $this->assertSame('Vandalised', $media->fresh()->translate('en')->alt_text);
    }

    public function test_media_added_to_the_live_recipe_after_publication_is_not_shown(): void
    {
        $this->publishWithMedia($this->media());
        $stray = $this->media();
        DB::table('recipe_media')->insert(['recipe_id' => $this->recipe->id, 'media_id' => $stray->id, 'role' => 'gallery', 'sort_order' => 9]);

        $fresh = Recipe::findOrFail($this->recipe->id);
        $ids = app(RecipeMediaMetadata::class)->present($fresh, $fresh->media()->with('translations')->get())->pluck('id');

        $this->assertNotContains($stray->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_the_file_identity_of_retained_media_cannot_be_replaced(): void
    {
        $media = $this->media();
        $this->publishWithMedia($media);

        $this->expectException(QueryException::class);
        $media->update(['url' => 'https://example.test/other.jpg']);
    }

    public function test_retained_media_cannot_be_deleted(): void
    {
        $media = $this->media();
        $this->publishWithMedia($media);

        $this->expectException(QueryException::class);
        DB::table('media')->where('id', $media->id)->delete();
    }

    public function test_the_snapshot_of_a_submitted_revision_is_frozen(): void
    {
        $media = $this->media();
        $draft = $this->service()->createDraft($this->author, $this->recipe);
        $payload = $draft->payload;
        $payload['media'] = [['media_id' => $media->id, 'role' => 'cover', 'sort_order' => 0]];
        $revision = $this->service()->submit($this->author, $this->service()->saveDraft($this->author, $draft, $payload));

        $this->assertNotEmpty($revision->media_snapshot);
        $this->expectException(QueryException::class);
        DB::table('recipe_revisions')->where('id', $revision->id)->update(['media_snapshot' => '[]']);
    }

    public function test_draft_media_edits_after_submission_do_not_change_what_is_published(): void
    {
        $media = $this->media();
        $draft = $this->service()->createDraft($this->author, $this->recipe);
        $payload = $draft->payload;
        $payload['media'] = [['media_id' => $media->id, 'role' => 'cover', 'sort_order' => 0]];
        $revision = $this->service()->submit($this->author, $this->service()->saveDraft($this->author, $draft, $payload));

        $media->translate('en')->update(['alt_text' => 'Changed during review']);
        $this->service()->approve(User::factory()->admin()->create(), $revision);

        // The text reviewed when the revision was frozen is what publishes.
        $this->assertSame('Reviewed English alt', $this->presented()->translate('en')->alt_text);
    }

    public function test_publication_rolls_back_when_the_snapshot_hash_does_not_match(): void
    {
        $media = $this->media();
        $draft = $this->service()->createDraft($this->author, $this->recipe);
        $payload = $draft->payload;
        $payload['media'] = [['media_id' => $media->id, 'role' => 'cover', 'sort_order' => 0]];
        $revision = $this->service()->submit($this->author, $this->service()->saveDraft($this->author, $draft, $payload));

        // The trigger refuses this tampering; lift it to prove publication independently detects a mismatch.
        DB::statement('ALTER TABLE recipe_revisions DISABLE TRIGGER recipe_revisions_immutability');
        DB::table('recipe_revisions')->where('id', $revision->id)->update(['media_snapshot_hash' => str_repeat('0', 64)]);
        DB::statement('ALTER TABLE recipe_revisions ENABLE TRIGGER recipe_revisions_immutability');

        try {
            $this->service()->approve(User::factory()->admin()->create(), $revision->fresh());
            $this->fail('Publication should have been refused.');
        } catch (RuntimeException) {
            $this->assertNotSame($revision->id, $this->recipe->fresh()->current_revision_id);
            $this->assertDatabaseMissing('recipe_media', ['recipe_id' => $this->recipe->id, 'media_id' => $media->id]);
        }
    }

    public function test_rollback_restores_the_media_metadata_of_the_target_revision(): void
    {
        $media = $this->media();
        $withMedia = $this->publishWithMedia($media);

        $draft = $this->service()->createDraft($this->author, $this->recipe->fresh());
        $payload = $draft->payload;
        $other = $this->media();
        $payload['media'] = [['media_id' => $other->id, 'role' => 'cover', 'sort_order' => 0]];
        $this->service()->approve(User::factory()->admin()->create(), $this->service()->submit($this->author, $this->service()->saveDraft($this->author, $draft, $payload)));

        $restored = $this->service()->rollback(User::factory()->admin()->create(), $withMedia->fresh(), 'Restore photo');

        $this->assertSame($withMedia->media_snapshot, $restored->fresh()->media_snapshot);
        $this->assertSame('Reviewed English alt', $this->presented()->translate('en')->alt_text);
    }

    public function test_private_recipes_use_live_metadata_and_expose_nothing_publicly(): void
    {
        $media = $this->media();
        $this->publishWithMedia($media);
        DB::table('recipes')->where('id', $this->recipe->id)->update(['visibility' => 'private']);
        $media->translate('en')->update(['alt_text' => 'Owner edit']);

        $this->assertSame('Owner edit', $this->presented()->translate('en')->alt_text);
        auth()->logout();
        $this->get(route('recipes.show', ['locale' => 'en', 'slug' => $this->recipe->slug]))->assertNotFound();
    }

    public function test_step_images_show_the_reviewed_text_not_the_shared_text(): void
    {
        $media = $this->media();
        $draft = $this->service()->createDraft($this->author, $this->recipe);
        $payload = $draft->payload;
        $payload['steps'][0]['media'] = [['media_id' => $media->id, 'kind' => 'image', 'sort_order' => 0]];
        $revision = $this->service()->submit($this->author, $this->service()->saveDraft($this->author, $draft, $payload));
        $this->service()->approve(User::factory()->admin()->create(), $revision);

        $media->translate('en')->update(['alt_text' => 'Vandalised']);

        $fresh = Recipe::findOrFail($this->recipe->id);
        $step = app(RecipeContentPresenter::class)->steps($fresh, 'en')[0];

        $this->assertSame('Reviewed English alt', $step['images'][0]['alt_text']);
    }
}
