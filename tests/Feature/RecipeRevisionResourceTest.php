<?php

namespace Tests\Feature;

use App\Enums\RecipeRevisionStatus;
use App\Filament\Resources\RecipeRevisions\Pages\EditRecipeRevision;
use App\Filament\Resources\RecipeRevisions\Pages\ListRecipeRevisions;
use App\Filament\Resources\RecipeRevisions\Pages\ViewRecipeRevision;
use App\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\User;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionComparer;
use App\Services\RecipeRevisionService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeRevisionResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private Recipe $recipe;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->author = User::factory()->contributor()->create();
        $this->recipe = Recipe::factory()->complete()->create(['author_id' => $this->author->id]);
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($this->recipe);
        $this->recipe->refresh();
    }

    private function inReview(): RecipeRevision
    {
        $service = app(RecipeRevisionService::class);
        $draft = $service->createDraft($this->author, $this->recipe);
        $payload = $draft->payload;
        $payload['translations']['en']['title'] = 'Changed title';
        $draft = $service->saveDraft($this->author, $draft, $payload);

        return $service->submit($this->author, $draft);
    }

    public function test_comparer_reports_text_changes_and_ignores_unchanged_content(): void
    {
        $payload = $this->recipe->currentRevision->payload;
        $changed = $payload;
        $changed['translations']['en']['title'] = 'Another';

        $comparer = app(RecipeRevisionComparer::class);

        $this->assertSame([], $comparer->compare($payload, $payload));
        $this->assertNotEmpty($comparer->compare($payload, $changed));
    }

    public function test_admin_can_approve_in_review_revision_from_the_view_page(): void
    {
        $revision = $this->inReview();

        Livewire::test(ViewRecipeRevision::class, ['record' => $revision->getKey()])
            ->callAction('approve', ['note' => 'ok'])
            ->assertNotified();

        $this->assertSame(RecipeRevisionStatus::Published, $revision->fresh()->status);
        $this->assertSame($revision->getKey(), $this->recipe->fresh()->current_revision_id);
    }

    public function test_submitter_cannot_see_or_run_approve(): void
    {
        $revision = $this->inReview();
        $this->actingAs($this->author);

        Livewire::test(ViewRecipeRevision::class, ['record' => $revision->getKey()])
            ->assertActionHidden('approve');
    }

    public function test_editor_can_review_revisions_but_not_roll_back(): void
    {
        $revision = $this->inReview();
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(ViewRecipeRevision::class, ['record' => $revision->getKey()])
            ->assertSuccessful()
            ->assertActionVisible('approve')
            ->assertActionHidden('rollback');
    }

    public function test_admin_can_reject_with_reason(): void
    {
        $revision = $this->inReview();

        Livewire::test(ViewRecipeRevision::class, ['record' => $revision->getKey()])
            ->callAction('reject', ['note' => 'Needs work']);

        $this->assertSame(RecipeRevisionStatus::Rejected, $revision->fresh()->status);
        $this->assertDatabaseHas('recipe_revision_audits', ['recipe_revision_id' => $revision->getKey(), 'action' => 'rejected']);
    }

    public function test_admin_rollback_creates_a_new_revision(): void
    {
        $original = $this->recipe->currentRevision;
        $revision = $this->inReview();
        app(RecipeRevisionService::class)->approve(User::factory()->admin()->create(), $revision);
        $before = RecipeRevision::query()->where('recipe_id', $this->recipe->id)->count();

        Livewire::test(ViewRecipeRevision::class, ['record' => $original->getKey()])
            ->callAction('rollback', ['note' => 'Bad change']);

        $this->assertSame($before + 1, RecipeRevision::query()->where('recipe_id', $this->recipe->id)->count());
        $this->assertSame(RecipeRevisionStatus::Superseded, $revision->fresh()->status);
    }

    public function test_contributor_list_shows_only_own_recipes_revisions(): void
    {
        $other = Recipe::factory()->complete()->create();
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($other);
        $otherRevision = $other->fresh()->currentRevision;
        $own = $this->recipe->currentRevision;
        $this->actingAs($this->author);

        Livewire::test(ListRecipeRevisions::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$otherRevision]);
    }

    public function test_draft_edit_saves_payload_without_touching_live_content(): void
    {
        $draft = app(RecipeRevisionService::class)->createDraft($this->author, $this->recipe);
        $title = $this->recipe->translate('en')->title;
        $this->actingAs($this->author);

        Livewire::test(EditRecipeRevision::class, ['record' => $draft->getKey()])
            ->fillForm(['en.title' => 'Draft only title'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Draft only title', $draft->fresh()->payload['translations']['en']['title']);
        $this->assertSame($title, $this->recipe->fresh()->translate('en')->title);
    }

    public function test_in_review_revision_cannot_be_edited(): void
    {
        $revision = $this->inReview();
        $this->actingAs($this->author);

        Livewire::test(EditRecipeRevision::class, ['record' => $revision->getKey()])->assertForbidden();
    }

    public function test_direct_edit_of_a_live_recipe_is_blocked(): void
    {
        $title = $this->recipe->translate('en')->title;

        Livewire::test(EditRecipe::class, ['record' => $this->recipe->getKey()])
            ->fillForm(['en.title' => 'Sneaky live edit'])
            ->call('save')
            ->assertNotified('This recipe is public');

        $this->assertSame($title, $this->recipe->fresh()->translate('en')->title);
    }

    public function test_start_revision_action_creates_a_draft_without_touching_live_content(): void
    {
        Livewire::test(EditRecipe::class, ['record' => $this->recipe->getKey()])
            ->callAction('startRevision');

        $this->assertDatabaseHas('recipe_revisions', ['recipe_id' => $this->recipe->id, 'status' => 'draft', 'created_by' => auth()->id()]);
        $this->assertSame('published', $this->recipe->fresh()->status);
    }

    public function test_contributor_starts_a_revision_from_the_list_for_their_own_live_recipe_only(): void
    {
        $other = Recipe::factory()->complete()->create();
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($other);
        $this->actingAs($this->author);

        Livewire::test(ListRecipeRevisions::class)
            ->callAction('startRevision', ['recipe_id' => $this->recipe->id])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('recipe_revisions', ['recipe_id' => $this->recipe->id, 'status' => 'draft', 'created_by' => $this->author->id]);

        Livewire::test(ListRecipeRevisions::class)
            ->callAction('startRevision', ['recipe_id' => $other->id]);

        $this->assertDatabaseMissing('recipe_revisions', ['recipe_id' => $other->id, 'status' => 'draft']);
    }
}
