<?php

namespace Tests\Feature;

use App\Enums\RecipeVisibility;
use App\Livewire\ManageRecipeSharing;
use App\Models\Recipe;
use App\Models\RecipeShare;
use App\Models\User;
use App\Notifications\RecipeSharedWithYou;
use App\Services\RecipeShareService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeSharingTest extends TestCase
{
    use RefreshDatabase;

    private function recipe(User $owner, string $visibility = 'shared', string $slug = 'secret-dish'): Recipe
    {
        $recipe = Recipe::factory()->create(['author_id' => $owner->id, 'visibility' => $visibility]);
        $recipe->fill(['en' => ['title' => 'Secret dish', 'slug' => $slug, 'summary' => 'Only for friends.']])->save();

        return $recipe;
    }

    private function service(): RecipeShareService
    {
        return app(RecipeShareService::class);
    }

    public function test_owner_shares_with_a_verified_user_who_is_notified_and_can_read_it(): void
    {
        Notification::fake();
        $owner = User::factory()->contributor()->create();
        $friend = User::factory()->create(['email' => 'friend@example.com']);
        $recipe = $this->recipe($owner);

        $share = $this->service()->share($owner, $recipe, ' Friend@Example.com ', 'en');

        $this->assertSame('view', $share->permission);
        Notification::assertSentTo($friend, RecipeSharedWithYou::class);
        $this->assertTrue($recipe->sharedUsers()->whereKey($friend->id)->exists());
        $this->assertTrue($friend->sharedRecipes()->whereKey($recipe->id)->exists());
        $this->assertTrue($owner->recipesSharedByMe()->whereKey($share->id)->exists());
        $this->actingAs($friend)->get('/en/recipes/secret-dish')->assertOk()->assertSee('Secret dish');
        $this->actingAs($friend)->get('/en/shared-with-me')->assertOk()->assertSee('Secret dish');
    }

    public function test_shared_recipe_is_never_public_or_listed(): void
    {
        $owner = User::factory()->contributor()->create();
        $recipe = $this->recipe($owner);
        $this->service()->share($owner, $recipe, User::factory()->create()->email, 'en');

        $this->get('/en/recipes/secret-dish')->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/en/recipes/secret-dish')->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee('secret-dish');
        $this->get('/en')->assertDontSee('Secret dish');
    }

    public function test_only_the_owner_can_share_and_others_are_rejected_in_the_service_and_livewire(): void
    {
        $owner = User::factory()->contributor()->create();
        $recipe = $this->recipe($owner);
        $other = User::factory()->contributor()->create();
        $target = User::factory()->create();

        try {
            $this->service()->share($other, $recipe, $target->email, 'en');
            $this->fail('A non-owner was allowed to share.');
        } catch (AuthorizationException) {
            $this->assertSame(0, RecipeShare::count());
        }

        $this->get("/en/recipes/{$recipe->id}/sharing")->assertRedirect('/login');
        $this->actingAs($other)->get("/en/recipes/{$recipe->id}/sharing")->assertNotFound();
        $this->actingAs(User::factory()->editor()->create())->get("/en/recipes/{$recipe->id}/sharing")->assertNotFound();

        Livewire::actingAs($other)->test(ManageRecipeSharing::class, ['locale' => 'en', 'recipeId' => $recipe->id])->assertNotFound();
    }

    public function test_members_without_a_contributor_role_cannot_share(): void
    {
        $member = User::factory()->create();
        $recipe = $this->recipe($member);

        $this->assertFalse($member->can('share', $recipe));
        $this->expectException(AuthorizationException::class);
        $this->service()->share($member, $recipe, User::factory()->create()->email, 'en');
    }

    public function test_unknown_unverified_and_owner_recipients_get_the_same_generic_error(): void
    {
        $owner = User::factory()->contributor()->create();
        $recipe = $this->recipe($owner);
        User::factory()->unverified()->create(['email' => 'new@example.com']);

        $messages = [];

        foreach (['nobody@example.com', 'new@example.com', $owner->email] as $email) {
            try {
                $this->service()->share($owner, $recipe, $email, 'en');
                $this->fail("Shared with {$email}");
            } catch (ValidationException $e) {
                $messages[] = $e->errors()['email'][0];
            }
        }

        $this->assertCount(1, array_unique($messages));
        $this->assertSame(0, RecipeShare::count());
    }

    public function test_sharing_requires_the_recipe_to_be_shared_and_not_public(): void
    {
        $owner = User::factory()->contributor()->create();
        $target = User::factory()->create();
        $private = $this->recipe($owner, 'private', 'private-dish');

        try {
            $this->service()->share($owner, $private, $target->email, 'en');
            $this->fail('Shared a private recipe.');
        } catch (ValidationException) {
        }

        $this->service()->enableSharing($owner, $private);
        $this->assertSame(RecipeVisibility::Shared, $private->fresh()->visibility);
        $this->service()->share($owner, $private->fresh(), $target->email, 'en');

        $public = Recipe::factory()->published()->create(['author_id' => $owner->id]);
        $this->expectException(ValidationException::class);
        $this->service()->enableSharing($owner, $public);
    }

    public function test_duplicate_active_shares_are_idempotent(): void
    {
        Notification::fake();
        $owner = User::factory()->contributor()->create();
        $friend = User::factory()->create();
        $recipe = $this->recipe($owner);

        $first = $this->service()->share($owner, $recipe, $friend->email, 'en');
        $second = $this->service()->share($owner, $recipe, $friend->email, 'en');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, RecipeShare::count());
        Notification::assertSentToTimes($friend, RecipeSharedWithYou::class, 1);
    }

    public function test_database_rejects_a_second_active_share_and_unknown_permissions(): void
    {
        $owner = User::factory()->contributor()->create();
        $friend = User::factory()->create();
        $recipe = $this->recipe($owner);
        $attributes = ['recipe_id' => $recipe->id, 'user_id' => $friend->id, 'shared_by' => $owner->id];

        RecipeShare::create($attributes);

        try {
            RecipeShare::create($attributes);
            $this->fail('Duplicate active share accepted.');
        } catch (QueryException) {
        }

        $this->expectException(QueryException::class);
        DB::table('recipe_shares')->where('recipe_id', $recipe->id)->update(['permission' => 'edit']);
    }

    public function test_revocation_removes_access_immediately_and_resharing_reactivates_the_same_grant(): void
    {
        Notification::fake();
        $owner = User::factory()->contributor()->create();
        $friend = User::factory()->create();
        $recipe = $this->recipe($owner);
        $share = $this->service()->share($owner, $recipe, $friend->email, 'en');

        $this->service()->revoke($owner, $share);

        $this->assertNotNull($share->fresh()->revoked_at);
        $this->actingAs($friend)->get('/en/recipes/secret-dish')->assertNotFound();
        $this->actingAs($friend)->get('/en/shared-with-me')->assertOk()->assertDontSee('Secret dish');

        $again = $this->service()->share($owner, $recipe, $friend->email, 'en');

        $this->assertSame($share->id, $again->id);
        $this->assertNull($again->revoked_at);
        $this->actingAs($friend)->get('/en/recipes/secret-dish')->assertOk();
        Notification::assertSentToTimes($friend, RecipeSharedWithYou::class, 2);
    }

    public function test_only_the_owner_can_revoke_and_a_recipient_cannot_revoke_their_own_or_others_grants(): void
    {
        $owner = User::factory()->contributor()->create();
        $friend = User::factory()->create();
        $recipe = $this->recipe($owner);
        $share = $this->service()->share($owner, $recipe, $friend->email, 'en');

        foreach ([$friend, User::factory()->contributor()->create()] as $intruder) {
            try {
                $this->service()->revoke($intruder, $share);
                $this->fail('Revoked by a non-owner.');
            } catch (AuthorizationException) {
            }
        }

        $this->assertNull($share->fresh()->revoked_at);
    }

    public function test_livewire_cannot_revoke_a_share_from_another_recipe(): void
    {
        $owner = User::factory()->contributor()->create();
        $mine = $this->recipe($owner, 'shared', 'mine');
        $theirs = $this->recipe(User::factory()->contributor()->create(), 'shared', 'theirs');
        $friend = User::factory()->create();
        $foreign = RecipeShare::create(['recipe_id' => $theirs->id, 'user_id' => $friend->id, 'shared_by' => $theirs->author_id]);

        Livewire::actingAs($owner)
            ->test(ManageRecipeSharing::class, ['locale' => 'en', 'recipeId' => $mine->id])
            ->call('revoke', $foreign->id)
            ->assertNotFound();

        $this->assertNull($foreign->fresh()->revoked_at);
    }

    public function test_making_a_recipe_private_suspends_grants_and_resharing_restores_them(): void
    {
        $owner = User::factory()->contributor()->create();
        $friend = User::factory()->create();
        $recipe = $this->recipe($owner);
        $this->service()->share($owner, $recipe, $friend->email, 'en');

        $this->service()->stopSharing($owner, $recipe);

        $this->assertSame(RecipeVisibility::Private, $recipe->fresh()->visibility);
        $this->assertSame(1, RecipeShare::active()->count());
        $this->assertSame(0, $friend->sharedRecipes()->count());
        $this->actingAs($friend)->get('/en/recipes/secret-dish')->assertNotFound();

        $this->service()->enableSharing($owner, $recipe->fresh());

        $this->actingAs($friend)->get('/en/recipes/secret-dish')->assertOk();
    }

    public function test_owner_manages_sharing_through_the_page(): void
    {
        Notification::fake();
        $owner = User::factory()->contributor()->create();
        $friend = User::factory()->create(['email' => 'friend@example.com']);
        $recipe = $this->recipe($owner, 'private');

        $this->actingAs($owner)->get("/el/recipes/{$recipe->id}/sharing")->assertOk()->assertSee('noindex', false);

        Livewire::actingAs($owner)
            ->test(ManageRecipeSharing::class, ['locale' => 'en', 'recipeId' => $recipe->id])
            ->call('enableSharing')
            ->set('email', 'friend@example.com')
            ->call('share')
            ->assertSee('friend@example.com')
            ->set('email', 'ghost@example.com')
            ->call('share')
            ->assertHasErrors('email')
            ->call('revoke', RecipeShare::first()->id)
            ->assertDontSee('friend@example.com');

        Notification::assertSentTo($friend, RecipeSharedWithYou::class);
    }

    public function test_invitations_are_rate_limited(): void
    {
        $owner = User::factory()->contributor()->create();
        $recipe = $this->recipe($owner);
        RateLimiter::increment('recipe-share:'.$owner->id, 3600, 20);

        $this->expectException(ValidationException::class);
        $this->service()->share($owner, $recipe, User::factory()->create()->email, 'en');
    }

    public function test_one_language_shared_recipe_is_readable_from_the_other_locale(): void
    {
        $owner = User::factory()->contributor()->create();
        $friend = User::factory()->create();
        $recipe = $this->recipe($owner);
        $this->service()->share($owner, $recipe, $friend->email, 'en');

        $this->actingAs($friend)->get('/el/shared-with-me')->assertOk()->assertSee('/en/recipes/secret-dish', false);
    }

    public function test_deleting_a_recipient_removes_their_grants_and_deleting_the_sharer_keeps_history(): void
    {
        $owner = User::factory()->contributor()->create();
        $friend = User::factory()->create();
        $recipe = $this->recipe($owner);
        $share = $this->service()->share($owner, $recipe, $friend->email, 'en');

        $friend->delete();

        $this->assertNull(RecipeShare::find($share->id));
    }
}
