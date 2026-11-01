<?php

namespace Tests\Feature;

use App\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Models\Recipe;
use App\Models\User;
use App\Notifications\RecipeApproved;
use App\Notifications\RecipeRejected;
use App\Notifications\RecipeSubmittedForReview;
use App\Services\RecipePublishingService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function service(): RecipePublishingService
    {
        return app(RecipePublishingService::class);
    }

    public function test_a_complete_recipe_can_be_submitted_and_reviewers_are_notified(): void
    {
        Notification::fake();
        $author = User::factory()->contributor()->create();
        $editor = User::factory()->editor()->create();
        $recipe = Recipe::factory()->complete()->create(['author_id' => $author->id]);

        $this->service()->submit($author, $recipe);

        $this->assertSame('pending', $recipe->fresh()->status);
        Notification::assertSentTo($editor, RecipeSubmittedForReview::class);
        Notification::assertNotSentTo($author, RecipeSubmittedForReview::class);
    }

    public function test_an_incomplete_or_single_language_recipe_cannot_be_submitted(): void
    {
        $author = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->create(['author_id' => $author->id]);

        try {
            $this->service()->submit($author, $recipe);
            $this->fail('Expected a validation error.');
        } catch (ValidationException) {
            $this->assertSame('draft', $recipe->fresh()->status);
        }

        $recipe->fill(['en' => ['title' => 'Only English', 'slug' => 'only-english', 'summary' => 'x']])->save();
        $this->expectException(ValidationException::class);
        $this->service()->submit($author, $recipe->fresh());
    }

    public function test_only_the_owner_can_submit(): void
    {
        $recipe = Recipe::factory()->complete()->create();

        $this->expectException(AuthorizationException::class);
        $this->service()->submit(User::factory()->contributor()->create(), $recipe);
    }

    public function test_submissions_are_capped_by_pending_count_and_per_day(): void
    {
        $author = User::factory()->contributor()->create();

        foreach (range(1, RecipePublishingService::MAX_PENDING_SUBMISSIONS) as $ignored) {
            $this->service()->submit($author, Recipe::factory()->complete()->create(['author_id' => $author->id]));
        }

        try {
            $this->service()->submit($author, Recipe::factory()->complete()->create(['author_id' => $author->id]));
            $this->fail('Expected the pending cap to apply.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('submission', $exception->errors());
        }

        RateLimiter::clear('recipe-submission:'.$author->id);
    }

    public function test_approval_publishes_notifies_once_and_is_idempotent(): void
    {
        Notification::fake();
        $author = User::factory()->contributor()->create();
        $editor = User::factory()->editor()->create();
        $recipe = Recipe::factory()->complete()->pending()->create(['author_id' => $author->id]);

        $this->service()->approve($recipe, $editor);
        $this->service()->approve($recipe->fresh(), $editor);

        $fresh = $recipe->fresh();
        $this->assertSame('published', $fresh->status);
        $this->assertSame('public', $fresh->visibility->value);
        $this->assertSame($editor->id, $fresh->reviewed_by);
        Notification::assertSentToTimes($author, RecipeApproved::class, 1);
    }

    public function test_incomplete_content_cannot_be_approved(): void
    {
        $recipe = Recipe::factory()->pending()->create();

        $this->expectException(ValidationException::class);
        $this->service()->approve($recipe, User::factory()->editor()->create());
    }

    public function test_contributors_and_authors_cannot_approve(): void
    {
        $author = User::factory()->editor()->create();
        $own = Recipe::factory()->complete()->pending()->create(['author_id' => $author->id]);

        try {
            $this->service()->approve($own, $author);
            $this->fail('An author must not approve their own recipe.');
        } catch (AuthorizationException) {
            $this->assertSame('pending', $own->fresh()->status);
        }

        $this->expectException(AuthorizationException::class);
        $this->service()->approve(Recipe::factory()->complete()->pending()->create(), User::factory()->contributor()->create());
    }

    public function test_rejection_requires_a_reason_notifies_once_and_is_idempotent(): void
    {
        Notification::fake();
        $author = User::factory()->contributor()->create();
        $editor = User::factory()->editor()->create();
        $recipe = Recipe::factory()->complete()->pending()->create(['author_id' => $author->id]);

        try {
            $this->service()->reject($recipe, $editor, '  ');
            $this->fail('A reason is required.');
        } catch (ValidationException) {
            $this->assertSame('pending', $recipe->fresh()->status);
        }

        $this->service()->reject($recipe, $editor, 'Needs a better photo');
        $this->service()->reject($recipe->fresh(), $editor, 'Needs a better photo');

        $fresh = $recipe->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertSame('Needs a better photo', $fresh->rejection_reason);
        Notification::assertSentToTimes($author, RecipeRejected::class, 1);
    }

    public function test_the_owner_cannot_edit_while_pending_but_can_after_withdrawing(): void
    {
        $author = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->complete()->pending()->create(['author_id' => $author->id]);

        $this->assertFalse($author->can('update', $recipe));

        $this->service()->withdraw($author, $recipe);

        $this->assertSame('draft', $recipe->fresh()->status);
        $this->assertTrue($author->can('update', $recipe->fresh()));
    }

    public function test_withdrawing_a_public_recipe_takes_it_offline_until_reapproved(): void
    {
        $author = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->published()->create(['author_id' => $author->id]);
        $this->assertFalse($author->can('update', $recipe));

        $this->service()->withdraw($author, $recipe);

        $fresh = $recipe->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertSame('private', $fresh->visibility->value);
        $this->assertFalse(Recipe::published()->whereKey($recipe->id)->exists());
    }

    public function test_pending_recipes_are_not_public(): void
    {
        $recipe = Recipe::factory()->complete()->pending()->create();

        $this->assertFalse(Recipe::published()->whereKey($recipe->id)->exists());
    }

    public function test_an_editor_can_approve_and_reject_from_the_edit_page(): void
    {
        Filament::setCurrentPanel('admin');
        $editor = User::factory()->editor()->create();
        $this->actingAs($editor);

        $approved = Recipe::factory()->complete()->pending()->create();
        Livewire::test(EditRecipe::class, ['record' => $approved->getRouteKey()])->callAction('approve');
        $this->assertSame('published', $approved->fresh()->status);

        $rejected = Recipe::factory()->complete()->pending()->create();
        Livewire::test(EditRecipe::class, ['record' => $rejected->getRouteKey()])
            ->callAction('reject', ['rejection_reason' => 'Please add detail']);
        $this->assertSame('draft', $rejected->fresh()->status);
        $this->assertSame('Please add detail', $rejected->fresh()->rejection_reason);
    }
}
