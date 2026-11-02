<?php

namespace Tests\Feature;

use App\Livewire\RecipeRatings;
use App\Models\Recipe;
use App\Models\RecipeRating;
use App\Models\User;
use App\Services\RecipeRatingModerationService;
use App\Support\AppPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeRatingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_can_rate_public_recipes_but_not_private_or_their_own(): void
    {
        $author = User::factory()->contributor()->create();
        $member = User::factory()->create();
        $publicRecipe = $this->recipe($author);
        $privateRecipe = $this->recipe($author, 'private');

        $this->assertTrue($member->can(AppPermissions::RECIPES_RATE));
        $this->assertTrue($member->can('create', [RecipeRating::class, $publicRecipe]));
        $this->assertFalse($author->can('create', [RecipeRating::class, $publicRecipe]));
        $this->assertFalse($member->can('create', [RecipeRating::class, $privateRecipe]));

        Livewire::actingAs($member)->test(RecipeRatings::class, ['recipeId' => $privateRecipe->id, 'locale' => 'en'])
            ->assertNotFound();

        $this->assertDatabaseCount('recipe_ratings', 0);
    }

    public function test_repeated_submissions_update_a_member_rating_instead_of_creating_another(): void
    {
        $author = User::factory()->contributor()->create();
        $member = User::factory()->create();
        $recipe = $this->recipe($author);

        Livewire::actingAs($member)->test(RecipeRatings::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('rating', 5)
            ->set('reviewText', 'Bright and simple.')
            ->call('save')
            ->assertHasNoErrors()
            ->set('rating', 4)
            ->set('reviewText', 'Still lovely, but add less salt.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, RecipeRating::query()->where('recipe_id', $recipe->id)->where('user_id', $member->id)->count());
        $this->assertDatabaseHas('recipe_ratings', [
            'recipe_id' => $recipe->id,
            'user_id' => $member->id,
            'rating' => 4,
            'review_text' => 'Still lovely, but add less salt.',
            'review_status' => 'pending',
            'rating_status' => 'active',
        ]);
    }

    public function test_moderating_a_written_review_does_not_change_its_valid_star_rating(): void
    {
        $author = User::factory()->contributor()->create();
        $reviewer = User::factory()->create();
        $moderator = User::factory()->editor()->create();
        $recipe = $this->recipe($author);
        $rating = $this->rating($recipe, $reviewer, 1, 'The recipe took longer than expected.', 'pending');

        auth()->login($moderator);
        app(RecipeRatingModerationService::class)->moderateReview($moderator, $rating, 'rejected');

        $this->assertDatabaseHas('recipe_ratings', [
            'id' => $rating->id,
            'rating' => 1,
            'rating_status' => 'active',
            'review_status' => 'rejected',
        ]);
        $this->assertSame(1, $recipe->ratings()->active()->count());
        $this->assertSame(1.0, (float) $recipe->ratings()->active()->avg('rating'));
    }

    public function test_aggregates_and_recipe_structured_data_use_all_active_genuine_ratings(): void
    {
        $author = User::factory()->contributor()->create();
        $recipe = $this->recipe($author);
        $this->rating($recipe, User::factory()->create(), 5, 'Pending review text.', 'pending');
        $this->rating($recipe, User::factory()->create(), 3, 'Rejected review text.', 'rejected');
        $this->rating($recipe, User::factory()->create(), 1, 'Removed review text.', 'published', 'removed', 'Confirmed abuse');

        $this->get('/en/recipes/'.$recipe->getTranslation('en')->slug)
            ->assertOk()
            ->assertSee('"@type":"AggregateRating"', false)
            ->assertSee('"ratingValue":4', false)
            ->assertSee('"ratingCount":2', false)
            ->assertDontSee('Pending review text.')
            ->assertDontSee('Rejected review text.')
            ->assertDontSee('Removed review text.');
    }

    public function test_users_can_report_abuse_and_reviewers_can_remove_only_reported_ratings_with_a_reason(): void
    {
        $author = User::factory()->contributor()->create();
        $rater = User::factory()->create();
        $reporter = User::factory()->create();
        $moderator = User::factory()->editor()->create();
        $recipe = $this->recipe($author);
        $rating = $this->rating($recipe, $rater, 2, 'Spam and harassment.', 'published');

        Livewire::actingAs($reporter)->test(RecipeRatings::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->assertSee('Spam and harassment.')
            ->call('prepareReport', $rating->id)
            ->set('reportReason', 'This contains spam links.')
            ->call('reportAbuse')
            ->assertHasNoErrors()
            ->assertSee(__('site.rating_reported'));

        $this->assertDatabaseHas('recipe_rating_reports', [
            'recipe_rating_id' => $rating->id,
            'reporter_id' => $reporter->id,
            'reason' => 'This contains spam links.',
            'status' => 'pending',
        ]);

        auth()->login($moderator);
        app(RecipeRatingModerationService::class)->removeRating($moderator, $rating, 'Confirmed spam after report review.');

        $this->assertDatabaseHas('recipe_ratings', [
            'id' => $rating->id,
            'rating_status' => 'removed',
            'moderation_reason' => 'Confirmed spam after report review.',
        ]);
        $this->assertDatabaseHas('recipe_rating_reports', [
            'recipe_rating_id' => $rating->id,
            'status' => 'upheld',
        ]);
        $this->assertSame(0, $recipe->ratings()->active()->count());
    }

    public function test_greek_recipe_page_uses_greek_rating_interface_strings(): void
    {
        $recipe = $this->recipe(User::factory()->contributor()->create());

        $this->get('/el/recipes/'.$recipe->getTranslation('el')->slug)
            ->assertOk()
            ->assertSee('Βαθμολογίες και κριτικές')
            ->assertSee('Γίνετε ο πρώτος που θα βαθμολογήσει αυτή τη συνταγή.');
    }

    private function recipe(User $author, string $visibility = 'public'): Recipe
    {
        $recipe = Recipe::factory()->create([
            'author_id' => $author->id,
            'visibility' => 'private',
            'status' => 'draft',
        ]);
        $recipe->fill([
            'en' => ['title' => 'Recipe rating test', 'slug' => 'recipe-rating-test-'.$recipe->id],
            'el' => ['title' => 'Δοκιμή βαθμολογίας', 'slug' => 'dokimi-vathmologias-'.$recipe->id],
        ])->save();

        if ($visibility === 'public') {
            $recipe->forceFill(['status' => 'published', 'visibility' => 'public', 'published_at' => now()])->saveQuietly();
        }

        return $recipe->fresh();
    }

    private function rating(
        Recipe $recipe,
        User $user,
        int $value,
        ?string $reviewText,
        string $reviewStatus,
        string $ratingStatus = 'active',
        ?string $moderationReason = null,
    ): RecipeRating {
        $rating = new RecipeRating;
        $rating->forceFill([
            'rating' => $value,
            'review_text' => $reviewText,
            'review_status' => $reviewStatus,
            'rating_status' => $ratingStatus,
            'moderation_reason' => $moderationReason,
        ]);
        $rating->user()->associate($user);
        $recipe->ratings()->save($rating);

        return $rating;
    }
}
