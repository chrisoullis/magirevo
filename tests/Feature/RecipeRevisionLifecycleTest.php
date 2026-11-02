<?php

namespace Tests\Feature;

use App\Enums\RecipeRevisionStatus;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientSubstitution;
use App\Models\MealPlanEntry;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeRevision;
use App\Models\User;
use App\Models\UserRecipeIngredientChange;
use App\Services\RecipeContentGuard;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionApplier;
use App\Services\RecipeRevisionService;
use App\Services\RecipeRevisionSnapshotter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class RecipeRevisionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private Recipe $recipe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->contributor()->create();
        $this->recipe = Recipe::factory()->complete()->create(['author_id' => $this->author->id]);
        $this->publishAsStaff($this->recipe);
        $this->recipe->refresh();
    }

    private function publishAsStaff(Recipe $recipe): void
    {
        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->publish($recipe);
    }

    private function service(): RecipeRevisionService
    {
        return app(RecipeRevisionService::class);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function draft(callable $change, ?User $actor = null): RecipeRevision
    {
        $actor ??= $this->author;
        $draft = $this->service()->createDraft($actor, $this->recipe);

        return $this->service()->saveDraft($actor, $draft, $change($draft->payload));
    }

    private function liveHash(): string
    {
        $snapshotter = app(RecipeRevisionSnapshotter::class);

        return $snapshotter->hash($snapshotter->serialize($this->recipe->id));
    }

    private function titleChange(string $title = 'Edited title'): callable
    {
        return function (array $payload) use ($title): array {
            $payload['translations']['en']['title'] = $title;
            $payload['translations']['el']['title'] = 'Επεξεργασμένος '.$title;

            return $payload;
        };
    }

    private function submitted(?callable $change = null): RecipeRevision
    {
        return $this->service()->submit($this->author, $this->draft($change ?? $this->titleChange()));
    }

    public function test_contributor_edits_a_draft_without_touching_the_published_recipe(): void
    {
        $before = $this->liveHash();
        $titleBefore = $this->recipe->fresh()->translate('en')->title;

        $draft = $this->draft($this->titleChange());

        $this->assertSame(RecipeRevisionStatus::Draft, $draft->status);
        $this->assertSame($before, $this->liveHash());
        $this->assertSame($titleBefore, $this->recipe->fresh()->translate('en')->title);
        $this->assertSame('Edited title', $draft->payload['translations']['en']['title']);
    }

    public function test_only_one_open_revision_can_exist(): void
    {
        $this->service()->createDraft($this->author, $this->recipe);

        $this->expectException(ValidationException::class);
        $this->service()->createDraft($this->admin(), $this->recipe);
    }

    public function test_drafts_require_a_public_recipe_and_the_right_user(): void
    {
        try {
            $this->service()->createDraft(User::factory()->contributor()->create(), $this->recipe);
            $this->fail('A stranger created a draft.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $private = Recipe::factory()->complete()->create(['author_id' => $this->author->id]);

        $this->expectException(AuthorizationException::class);
        $this->service()->createDraft($this->author, $private);
    }

    public function test_submission_freezes_the_revision_and_validates_it(): void
    {
        $revision = $this->submitted();

        $this->assertSame(RecipeRevisionStatus::InReview, $revision->status);
        $this->assertNotNull($revision->submitted_at);

        try {
            $this->service()->saveDraft($this->author, $revision, $this->titleChange('Sneaky')($revision->payload));
            $this->fail('A frozen revision was edited.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        DB::table('recipe_revisions')->where('id', $revision->id)->update(['payload' => json_encode(['x' => 1])]);
    }

    public function test_incomplete_or_unchanged_drafts_cannot_be_submitted(): void
    {
        $unchanged = $this->service()->createDraft($this->author, $this->recipe);

        try {
            $this->service()->submit($this->author, $unchanged);
            $this->fail('An unchanged revision was submitted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('revision', $exception->errors());
        }

        $this->service()->saveDraft($this->author, $unchanged, (function (array $payload): array {
            $payload['steps'][0]['translations']['el']['instruction'] = '';

            return $payload;
        })($unchanged->payload));

        try {
            $this->service()->submit($this->author, $unchanged->refresh());
            $this->fail('A revision without Greek instructions was submitted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('steps', $exception->errors());
        }
    }

    public function test_approval_publishes_the_exact_reviewed_content_and_keeps_history(): void
    {
        $original = $this->recipe->current_revision_id;
        $revision = $this->submitted();

        $approved = $this->service()->approve($this->admin(), $revision, 'Looks good');

        $this->assertSame(RecipeRevisionStatus::Published, $approved->status);
        $this->assertSame($approved->content_hash, $this->liveHash());
        $this->assertSame($approved->id, $this->recipe->fresh()->current_revision_id);
        $this->assertSame(RecipeRevisionStatus::Superseded, RecipeRevision::find($original)->status);
        $this->assertSame('Edited title', $this->recipe->fresh()->translate('en')->title);
        $this->assertSame('Επεξεργασμένος Edited title', $this->recipe->fresh()->translate('el')->title);
        $this->assertDatabaseHas('recipe_revision_audits', ['recipe_revision_id' => $approved->id, 'action' => 'approved']);
        $this->assertDatabaseHas('recipe_revision_audits', ['recipe_revision_id' => $original, 'action' => 'superseded']);
        $this->assertNotNull($approved->resolved_ids['ingredients'] ?? null);
    }

    public function test_nobody_involved_in_a_revision_can_approve_it(): void
    {
        $editor = $this->admin();
        $draft = $this->service()->createDraft($editor, $this->recipe);
        $draft = $this->service()->saveDraft($editor, $draft, $this->titleChange()($draft->payload));
        $revision = $this->service()->submit($editor, $draft);

        try {
            $this->service()->approve($editor, $revision);
            $this->fail('The submitter approved their own revision.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->assertSame(RecipeRevisionStatus::InReview, $revision->fresh()->status);
        $this->assertSame(RecipeRevisionStatus::Published, $this->service()->approve($this->admin(), $revision)->status);
    }

    public function test_contributors_and_the_author_cannot_approve(): void
    {
        $revision = $this->submitted();

        $this->expectException(AuthorizationException::class);
        $this->service()->approve($this->author, $revision);
    }

    public function test_rejection_preserves_the_revision_and_allows_continuing_from_it(): void
    {
        $revision = $this->submitted();
        $before = $this->liveHash();

        $this->service()->reject($this->admin(), $revision, 'Needs more detail');

        $this->assertSame(RecipeRevisionStatus::Rejected, $revision->fresh()->status);
        $this->assertSame($before, $this->liveHash());

        $next = $this->service()->createDraft($this->author, $this->recipe, $revision->fresh());

        $this->assertSame(RecipeRevisionStatus::Draft, $next->status);
        $this->assertSame('Edited title', $next->payload['translations']['en']['title']);
        $this->assertSame(RecipeRevisionStatus::Rejected, $revision->fresh()->status);
    }

    public function test_unchanged_lines_keep_ids_and_personal_changes_survive_publication(): void
    {
        $line = RecipeIngredient::where('recipe_id', $this->recipe->id)->firstOrFail();
        $change = UserRecipeIngredientChange::factory()->create(['recipe_ingredient_id' => $line->id, 'note' => 'mine']);

        $revision = $this->submitted(function (array $payload): array {
            $payload['ingredients'][0]['quantity'] = '7';

            return $payload;
        });
        $this->service()->approve($this->admin(), $revision);

        $this->assertDatabaseHas('recipe_ingredients', ['id' => $line->id, 'recipe_id' => $this->recipe->id]);
        $this->assertSame('7', rtrim(rtrim((string) $line->fresh()->quantity, '0'), '.'));
        $this->assertDatabaseHas('user_recipe_ingredient_changes', ['id' => $change->id, 'note' => 'mine']);
        $this->assertSame(0, DB::table('archived_personal_ingredient_changes')->count());
    }

    public function test_removed_and_replaced_lines_archive_personal_changes(): void
    {
        $extra = Ingredient::factory()->create();
        $extra->fill(['en' => ['name' => 'Extra '.uniqid()], 'el' => ['name' => 'Έξτρα '.uniqid()]])->save();
        $replacement = Ingredient::factory()->create();
        $replacement->fill(['en' => ['name' => 'Repl '.uniqid()], 'el' => ['name' => 'Αντ '.uniqid()]])->save();

        $first = RecipeIngredient::where('recipe_id', $this->recipe->id)->firstOrFail();
        $second = RecipeIngredient::factory()->create(['recipe_id' => $this->recipe->id, 'ingredient_id' => $extra->id, 'sort_order' => 1]);
        foreach (['en', 'el'] as $locale) {
            DB::table('recipe_ingredient_translations')->insert(['recipe_ingredient_id' => $second->id, 'locale' => $locale, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->service()->syncPublishedFromLive($this->recipe->id);

        $replacedChange = UserRecipeIngredientChange::factory()->create(['recipe_ingredient_id' => $first->id, 'note' => 'on first']);
        $removedChange = UserRecipeIngredientChange::factory()->create(['recipe_ingredient_id' => $second->id, 'note' => 'on second']);

        $revision = $this->submitted(function (array $payload) use ($replacement): array {
            $payload['ingredients'][0]['ingredient_id'] = $replacement->id;
            array_pop($payload['ingredients']);

            return $payload;
        });
        $this->service()->approve($this->admin(), $revision);

        $this->assertDatabaseHas('recipe_ingredients', ['id' => $first->id, 'ingredient_id' => $replacement->id]);
        $this->assertDatabaseMissing('recipe_ingredients', ['id' => $second->id]);
        $this->assertDatabaseMissing('user_recipe_ingredient_changes', ['id' => $replacedChange->id]);
        $this->assertDatabaseHas('archived_personal_ingredient_changes', ['original_id' => $replacedChange->id, 'reason' => 'line_replaced', 'note' => 'on first']);
        $this->assertDatabaseHas('archived_personal_ingredient_changes', ['original_id' => $removedChange->id, 'reason' => 'line_removed', 'note' => 'on second']);
    }

    public function test_hash_mismatch_rolls_back_publication_completely(): void
    {
        $revision = $this->submitted();
        $before = $this->liveHash();
        $currentId = $this->recipe->current_revision_id;

        $this->app->bind(RecipeRevisionApplier::class, fn () => new class extends RecipeRevisionApplier
        {
            public function apply(int $recipeId, array $payload, int $revisionId): array
            {
                $result = parent::apply($recipeId, $payload, $revisionId);
                DB::table('recipe_ingredients')->where('recipe_id', $recipeId)->update(['quantity' => 99]);

                return $result;
            }
        });

        try {
            app(RecipeRevisionService::class)->approve($this->admin(), $revision);
            $this->fail('A mismatching publication was accepted.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $this->assertSame($before, $this->liveHash());
        $this->assertSame(RecipeRevisionStatus::InReview, $revision->fresh()->status);
        $this->assertSame($currentId, $this->recipe->fresh()->current_revision_id);
        $this->assertSame(RecipeRevisionStatus::Published, RecipeRevision::find($currentId)->status);
    }

    public function test_publication_works_under_an_enforced_guard_and_direct_edits_are_refused(): void
    {
        app(RecipeContentGuard::class)->setMode('enforce');
        $revision = $this->submitted();

        $this->service()->approve($this->admin(), $revision);

        $this->assertSame('Edited title', $this->recipe->fresh()->translate('en')->title);
        $this->expectException(QueryException::class);
        DB::table('recipe_translations')->where('recipe_id', $this->recipe->id)->update(['title' => 'Direct']);
    }

    public function test_a_revision_started_before_a_rollback_cannot_overwrite_it(): void
    {
        $first = $this->service()->approve($this->admin(), $this->submitted());
        $old = RecipeRevision::query()->where('recipe_id', $this->recipe->id)->orderBy('revision_number')->firstOrFail();

        $open = $this->service()->submit($this->author, $this->draft($this->titleChange('Third')));
        $this->service()->rollback($this->admin(), $old, 'Reverting');

        try {
            $this->service()->approve($this->admin(), $open);
            $this->fail('A stale revision was published.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('revision', $exception->errors());
        }

        $this->assertNotNull($first);
    }

    public function test_rollback_creates_a_new_revision_and_leaves_history_untouched(): void
    {
        $old = RecipeRevision::query()->where('recipe_id', $this->recipe->id)->firstOrFail();
        $snapshotter = app(RecipeRevisionSnapshotter::class);
        $oldHash = $snapshotter->hash($snapshotter->normalize($old->payload));
        $published = $this->service()->approve($this->admin(), $this->submitted());

        $rollback = $this->service()->rollback($this->admin(), $old->fresh(), 'Wrong title');

        $this->assertSame(3, RecipeRevision::where('recipe_id', $this->recipe->id)->count());
        $this->assertSame($old->id, $rollback->restored_from_revision_id);
        $this->assertSame(RecipeRevisionStatus::Published, $rollback->status);
        $this->assertSame($oldHash, $this->liveHash());
        $this->assertSame($old->content_hash, $old->fresh()->content_hash);
        $this->assertSame(RecipeRevisionStatus::Superseded, $old->fresh()->status);
        $this->assertSame(RecipeRevisionStatus::Superseded, $published->fresh()->status);
        $this->assertSame($rollback->id, $this->recipe->fresh()->current_revision_id);
        $this->assertDatabaseHas('recipe_revision_audits', ['recipe_revision_id' => $rollback->id, 'action' => 'rolled_back', 'note' => 'Wrong title']);
    }

    public function test_rollback_requires_the_rollback_permission(): void
    {
        $old = RecipeRevision::query()->where('recipe_id', $this->recipe->id)->firstOrFail();
        $this->service()->approve($this->admin(), $this->submitted());

        $this->expectException(AuthorizationException::class);
        $this->service()->rollback(User::factory()->editor()->create(), $old->fresh(), 'Nope');
    }

    public function test_a_recipe_taken_offline_cannot_receive_a_publication(): void
    {
        $revision = $this->submitted();
        DB::table('recipes')->where('id', $this->recipe->id)->update(['visibility' => 'private']);

        $this->expectException(ValidationException::class);
        $this->service()->approve($this->admin(), $revision);
    }

    public function test_submitted_revisions_pin_their_media_and_new_categories_apply(): void
    {
        $media = Media::factory()->remote()->create();
        $category = Category::factory()->create();

        $revision = $this->submitted(function (array $payload) use ($media, $category): array {
            $payload['media'][] = ['media_id' => $media->id, 'role' => 'gallery', 'sort_order' => 5];
            $payload['category_ids'][] = $category->id;

            return $payload;
        });

        $this->assertTrue($media->fresh()->isInUse());
        $this->assertDatabaseMissing('recipe_media', ['recipe_id' => $this->recipe->id, 'media_id' => $media->id]);

        $this->service()->approve($this->admin(), $revision);

        $this->assertDatabaseHas('recipe_media', ['recipe_id' => $this->recipe->id, 'media_id' => $media->id, 'role' => 'gallery']);
        $this->assertDatabaseHas('recipe_category', ['recipe_id' => $this->recipe->id, 'category_id' => $category->id]);
    }

    public function test_planned_substitutions_on_replaced_lines_are_archived_not_lost_and_kept_on_unchanged_lines(): void
    {
        $line = RecipeIngredient::where('recipe_id', $this->recipe->id)->firstOrFail();
        $entry = MealPlanEntry::factory()->create(['recipe_id' => $this->recipe->id]);
        $substitution = IngredientSubstitution::factory()->approved()->create(['ingredient_id' => $line->ingredient_id]);
        $substitutionId = DB::table('meal_plan_entry_substitutions')->insertGetId([
            'meal_plan_entry_id' => $entry->id, 'recipe_id' => $this->recipe->id, 'recipe_ingredient_id' => $line->id,
            'ingredient_substitution_id' => $substitution->id, 'replacement_ingredient_id' => $substitution->replacement_ingredient_id,
            'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service()->approve($this->admin(), $this->submitted(function (array $payload): array {
            $payload['ingredients'][0]['quantity'] = '3';

            return $payload;
        }));
        $this->assertDatabaseHas('meal_plan_entry_substitutions', ['id' => $substitutionId]);

        $other = Ingredient::factory()->create();
        $other->fill(['en' => ['name' => 'Other '.uniqid()], 'el' => ['name' => 'Άλλο '.uniqid()]])->save();
        $this->service()->approve($this->admin(), $this->submitted(function (array $payload) use ($other): array {
            $payload['ingredients'][0]['ingredient_id'] = $other->id;

            return $payload;
        }));

        $this->assertDatabaseMissing('meal_plan_entry_substitutions', ['id' => $substitutionId]);
        $this->assertDatabaseHas('archived_meal_plan_entry_substitutions', ['original_id' => $substitutionId, 'reason' => 'line_replaced', 'meal_plan_entry_id' => $entry->id]);
        $this->assertDatabaseHas('meal_plan_entries', ['id' => $entry->id]);
    }

    public function test_an_approved_revision_cannot_be_approved_twice(): void
    {
        $revision = $this->submitted();
        $this->service()->approve($this->admin(), $revision);

        $this->expectException(AuthorizationException::class);
        $this->service()->approve($this->admin(), $revision->fresh());
    }
}
