<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionSnapshotter;
use App\Services\Recipes\RecipeForkService;
use App\Services\Recipes\RecipeVariationContent;
use App\Services\Recipes\RecipeVariationText;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesVariationSource;
use Tests\TestCase;

class RecipeForkTest extends TestCase
{
    use CreatesVariationSource;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['recipe-variations.enabled' => true]);
    }

    private function fork(User $actor, Recipe $source, ?string $key = null): Recipe
    {
        return app(RecipeForkService::class)->fork($actor, $source, ['idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    public function test_member_forks_an_independent_private_recipe_without_inherited_relations(): void
    {
        $source = $this->source();
        $member = User::factory()->create();
        $before = app(RecipeRevisionSnapshotter::class)->serialize($source->id);
        $fork = $this->fork($member, $source);
        $this->assertSame($member->id, $fork->author_id);
        $this->assertSame('draft', $fork->status);
        $this->assertSame('private', $fork->visibility->value);
        $this->assertNull($fork->published_at);
        $this->assertNull($fork->current_revision_id);
        $this->assertSame($source->current_revision_id, $fork->derivation->source_recipe_revision_id);
        $this->assertNotSame($source->id, $fork->id);
        $this->assertSame(2, $fork->translations()->count());
        $this->assertNotSame($source->recipeIngredients()->first()->id, $fork->recipeIngredients()->first()->id);
        $this->assertNotSame($source->steps()->first()->id, $fork->steps()->first()->id);
        foreach (['media', 'ratings', 'shares', 'favoritedBy', 'personalNotes', 'personalIngredientChanges', 'revisions'] as $relation) {
            $this->assertSame(0, $fork->$relation()->count());
        }
        $this->assertSame(0, DB::table('recipe_step_media')->whereIn('recipe_step_id', $fork->steps()->pluck('id'))->count());
        $this->assertSame($before, app(RecipeRevisionSnapshotter::class)->serialize($source->id));
        $this->assertTrue(Gate::forUser($member)->allows('editVariation', $fork));
        $this->assertFalse(Gate::forUser($member)->allows('create', Recipe::class));
    }

    public function test_fork_uses_approved_payload_even_when_live_tables_drift_in_monitor_mode(): void
    {
        $source = $this->source();
        $approved = $source->steps()->first()->translate('en')->instruction;
        DB::table('recipe_step_translations')->where('recipe_step_id', $source->steps()->first()->id)->where('locale', 'en')->update(['instruction' => 'Unreviewed live text']);
        $fork = $this->fork(User::factory()->create(), $source);
        $this->assertSame($approved, $fork->steps()->first()->translate('en')->instruction);
    }

    public function test_latest_restriction_suspension_and_noncommercial_conditions_block_even_admins(): void
    {
        $source = $this->source();
        $admin = User::factory()->admin()->create();
        $permission = $source->reusePermissions()->first();
        $restricted = $permission->replicate();
        $restricted->forceFill(['supersedes_id' => $permission->id, 'mode' => 'none'])->save();
        $this->assertFalse(Gate::forUser($admin)->allows('fork', $source));
        $commercial = $permission->replicate();
        $commercial->forceFill(['supersedes_id' => $restricted->id, 'allows_commercial_use' => false])->save();
        $this->assertFalse(Gate::forUser($admin)->allows('fork', $source));
        $commercial->forceFill(['suspended_at' => now(), 'suspended_by' => $admin->id, 'suspension_reason' => 'Dispute'])->save();
        $this->expectException(AuthorizationException::class);
        $this->fork($admin, $source);
    }

    public function test_private_shared_guest_and_unverified_sources_or_actors_are_denied(): void
    {
        $source = $this->source();
        $member = User::factory()->create();
        $this->assertFalse(Gate::forUser(null)->allows('fork', $source));
        $this->assertFalse(Gate::forUser(User::factory()->unverified()->create())->allows('fork', $source));
        $this->assertFalse(Gate::forUser($member)->allows('fork', Recipe::factory()->shared()->create()));
        $this->assertFalse(Gate::forUser($member)->allows('fork', Recipe::factory()->create(['author_id' => $member->id])));
        $this->assertFalse(Gate::forUser($member)->allows('fork', $this->fork($member, $source)));
    }

    public function test_idempotent_retry_succeeds_at_quota_and_mismatched_request_key_is_rejected(): void
    {
        $source = $this->source();
        $member = User::factory()->create();
        $key = (string) Str::uuid();
        $fork = $this->fork($member, $source, $key);
        $member->entitlement()->update(['personal_recipe_limit_override' => 1]);
        $this->assertSame($fork->id, $this->fork($member, $source, $key)->id);
        $this->expectException(AuthorizationException::class);
        $this->fork($member, $source);
    }

    public function test_invalid_source_payload_rolls_back_recipe_and_quota(): void
    {
        $source = $this->source();
        $member = User::factory()->create();
        $this->mock(RecipeVariationContent::class)->shouldReceive('write')->once()->andThrow(ValidationException::withMessages(['content' => 'Invalid content']));
        try {
            $this->fork($member, $source);
            $this->fail('Invalid fork succeeded.');
        } catch (ValidationException) {
            $this->assertSame(0, Recipe::query()->where('author_id', $member->id)->count());
            $this->assertSame(0, DB::table('recipe_derivations')->where('created_by', $member->id)->count());
        }
    }

    public function test_media_markup_and_addresses_are_removed_from_copied_text(): void
    {
        $clean = app(RecipeVariationText::class)->clean('<p>Cook gently.</p><figure><img src="/private.jpg"><figcaption>Original caption</figcaption></figure><iframe src="https://video.example"></iframe><script>alert(1)</script> https://original.example/video');
        $this->assertSame('Cook gently.', $clean);
    }

    public function test_groups_and_ordinary_tags_are_copied_without_dietary_tags_or_embedded_media(): void
    {
        $ordinary = Tag::factory()->create(['classification' => 'descriptive']);
        $dietary = Tag::factory()->create(['classification' => 'diet_style']);
        $source = $this->source(function ($recipe) use ($ordinary, $dietary): void {
            $recipe->tags()->sync([$ordinary->id, $dietary->id]);
            $group = $recipe->ingredientGroups()->create(['en' => ['name' => 'Sauce']]);
            $recipe->recipeIngredients()->first()->update(['ingredient_group_id' => $group->id]);
            $recipe->translate('en')->update(['introduction' => '<p>Cook slowly.</p><img src="/original.jpg"><iframe src="https://video.example"></iframe>']);
        });
        $fork = $this->fork(User::factory()->create(), $source);
        $this->assertSame([$ordinary->id], $fork->tags()->pluck('tags.id')->all());
        $this->assertSame(0, DB::table('recipe_dietary_claims')->where('recipe_id', $fork->id)->count());
        $this->assertNotSame($source->ingredientGroups()->first()->id, $fork->ingredientGroups()->first()->id);
        $this->assertSame('Sauce', $fork->recipeIngredients()->first()->ingredientGroup->translate('en')->name);
        $this->assertStringNotContainsString('original.jpg', $fork->translate('en')->introduction);
        $this->assertStringNotContainsString('iframe', $fork->translate('en')->introduction);
    }

    public function test_reusing_a_key_for_a_different_source_cannot_create_another_recipe(): void
    {
        $actor = User::factory()->create();
        $source = $this->source();
        $key = (string) Str::uuid();
        $first = $this->fork($actor, $source, $key);
        try {
            $this->fork($actor, $this->source(), $key);
            $this->fail('An idempotency key was reused for a different source.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame(1, Recipe::query()->where('author_id', $actor->id)->count());
    }

    public function test_fork_preserves_long_cooking_and_resting_times_supported_by_the_existing_editor(): void
    {
        $source = $this->source(function ($recipe): void {
            $recipe->update(['rest_minutes' => 2880]);
            $recipe->steps()->first()->update(['duration_minutes' => 2000]);
        });
        $fork = $this->fork(User::factory()->create(), $source);
        $this->assertSame(2880, $fork->rest_minutes);
        $this->assertSame(2000, $fork->steps()->first()->duration_minutes);
    }

    public function test_variations_cannot_use_legacy_publication_or_sharing_paths(): void
    {
        $actor = User::factory()->admin()->create();
        $fork = $this->fork($actor, $this->source());
        $this->assertFalse(Gate::forUser($actor)->allows('publish', $fork));
        $this->assertFalse(Gate::forUser($actor)->allows('submitForReview', $fork));
        $this->assertFalse(Gate::forUser($actor)->allows('share', $fork));
        $this->expectException(ValidationException::class);
        app(RecipePublishingService::class)->publish($fork);
    }
}
