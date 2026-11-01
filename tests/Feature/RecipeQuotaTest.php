<?php

namespace Tests\Feature;

use App\Enums\RecipeVisibility;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Recipes\Pages\ListRecipes;
use App\Filament\Resources\UserEntitlements\Pages\EditUserEntitlement;
use App\Models\Plan;
use App\Models\Recipe;
use App\Models\RecipeShare;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Services\RecipePublishingService;
use App\Services\RecipeQuotaService;
use App\Services\RecipeShareService;
use App\Support\QuotaUsageNotice;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function quota(): RecipeQuotaService
    {
        return app(RecipeQuotaService::class);
    }

    private function create(User $user): Recipe
    {
        return $this->quota()->withinPersonalQuota($user, fn (): Recipe => Recipe::factory()->create(['author_id' => $user->id]));
    }

    private function fill(User $user, int $count): void
    {
        Recipe::factory()->count($count)->create(['author_id' => $user->id]);
    }

    public function test_every_user_defaults_to_the_free_plan(): void
    {
        $user = User::factory()->create();
        $free = Plan::where('code', 'free')->firstOrFail();

        $this->assertSame($free->id, $user->entitlement->plan_id);
        $this->assertSame(20, $free->max_personal_recipes);
        $this->assertSame(5, $free->max_active_shares_per_recipe);
        $this->assertNull($free->max_storage_mb);
        $this->assertSame(20, $this->quota()->personalRecipeLimit($user));
    }

    public function test_a_user_without_an_entitlement_row_still_gets_the_free_plan(): void
    {
        $user = User::factory()->create();
        $user->entitlement->delete();

        $this->assertSame(20, $this->quota()->personalRecipeLimit($user->fresh()));
        $this->assertSame(1, UserEntitlement::where('user_id', $user->id)->count());
    }

    public function test_contributor_can_create_twenty_counted_recipes_and_the_twenty_first_is_blocked(): void
    {
        $contributor = User::factory()->contributor()->create();

        for ($i = 0; $i < 20; $i++) {
            $this->create($contributor);
        }

        $this->assertSame(20, $this->quota()->usage($contributor));

        try {
            $this->create($contributor);
            $this->fail('The 21st recipe was created.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quota', $e->errors());
        }

        $this->assertSame(20, Recipe::where('author_id', $contributor->id)->count());
    }

    public function test_drafts_private_shared_pending_and_archived_all_count_but_public_does_not(): void
    {
        $user = User::factory()->contributor()->create();
        Recipe::factory()->create(['author_id' => $user->id]);
        Recipe::factory()->shared()->create(['author_id' => $user->id]);
        Recipe::factory()->pending()->create(['author_id' => $user->id]);
        Recipe::factory()->create(['author_id' => $user->id, 'status' => 'archived']);
        Recipe::factory()->published()->count(5)->create(['author_id' => $user->id]);
        Recipe::factory()->create(['author_id' => $user->id])->delete();

        $this->assertSame(4, $this->quota()->usage($user));
    }

    public function test_a_published_but_private_recipe_still_counts(): void
    {
        $user = User::factory()->contributor()->create();
        Recipe::factory()->published()->create(['author_id' => $user->id, 'visibility' => 'private']);

        $this->assertSame(1, $this->quota()->usage($user));
    }

    public function test_plan_and_user_overrides_change_the_limit(): void
    {
        $user = User::factory()->contributor()->create();
        $this->fill($user, 3);

        $user->entitlement->update(['personal_recipe_limit_override' => 3]);
        $this->assertTrue($this->quota()->hasReachedLimit($user->fresh()));

        $user->entitlement->update(['personal_recipe_limit_override' => 4]);
        $this->assertFalse($this->quota()->hasReachedLimit($user->fresh()));

        $user->entitlement->update(['personal_recipe_limit_override' => null]);
        Plan::where('code', 'free')->update(['max_personal_recipes' => 3]);
        $this->assertTrue($this->quota()->hasReachedLimit($user->fresh()));

        Plan::where('code', 'free')->update(['max_personal_recipes' => null]);
        $this->assertNull($this->quota()->personalRecipeLimit($user->fresh()));
        $this->assertFalse($this->quota()->hasReachedLimit($user->fresh()));
    }

    public function test_zero_means_not_allowed_rather_than_unlimited(): void
    {
        $user = User::factory()->contributor()->create();
        $user->entitlement->update(['personal_recipe_limit_override' => 0]);

        $this->expectException(ValidationException::class);
        $this->create($user->fresh());
    }

    public function test_a_future_paid_plan_needs_no_schema_change(): void
    {
        $plan = Plan::create(['code' => 'pro', 'max_personal_recipes' => 200, 'max_active_shares_per_recipe' => null]);
        $user = User::factory()->contributor()->create();
        $user->entitlement->update(['plan_id' => $plan->id]);

        $this->assertSame(200, $this->quota()->personalRecipeLimit($user->fresh()));
        $this->assertNull($this->quota()->sharingLimit($user->fresh()));
    }

    public function test_reducing_a_limit_or_downgrading_deletes_nothing_and_keeps_existing_recipes_usable(): void
    {
        $user = User::factory()->contributor()->create(['email_verified_at' => now()]);
        $this->fill($user, 5);
        $recipe = $user->fresh()->load('entitlement')->entitlement->update(['personal_recipe_limit_override' => 2]);
        $owned = Recipe::where('author_id', $user->id)->first();
        $owned->fill(['en' => ['title' => 'Mine', 'slug' => 'mine', 'summary' => 's']])->save();

        $this->assertSame(5, Recipe::where('author_id', $user->id)->count());
        $this->assertTrue($this->quota()->hasReachedLimit($user->fresh()));
        $this->assertTrue($user->fresh()->can('view', $owned));
        $this->assertTrue($user->fresh()->can('update', $owned));
        $this->actingAs($user)->get('/en/recipes/mine')->assertOk();

        $this->expectException(ValidationException::class);
        $this->create($user->fresh());
    }

    public function test_leaving_public_is_checked_when_the_owner_does_it_so_public_cannot_bypass_the_limit(): void
    {
        $owner = User::factory()->contributor()->create();
        $owner->entitlement->update(['personal_recipe_limit_override' => 1]);
        $this->fill($owner, 1);
        $public = Recipe::factory()->complete()->create(['author_id' => $owner->id]);
        app(RecipePublishingService::class)->publish($public);

        $this->actingAs($owner);

        try {
            app(RecipePublishingService::class)->unpublish($public->fresh());
            $this->fail('Owner unpublished beyond their limit.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quota', $e->errors());
        }

        $this->assertSame('public', $public->fresh()->visibility->value);

        try {
            app(RecipePublishingService::class)->changeVisibility($public->fresh(), RecipeVisibility::Shared);
            $this->fail('Owner made a public recipe shared beyond their limit.');
        } catch (ValidationException) {
        }

        $this->assertSame('public', $public->fresh()->visibility->value);
    }

    public function test_an_editor_can_always_take_down_a_recipe_even_if_the_author_is_at_their_limit(): void
    {
        $author = User::factory()->contributor()->create();
        $author->entitlement->update(['personal_recipe_limit_override' => 1]);
        $this->fill($author, 1);
        $public = Recipe::factory()->complete()->create(['author_id' => $author->id]);
        app(RecipePublishingService::class)->publish($public);

        $this->actingAs(User::factory()->editor()->create());
        app(RecipePublishingService::class)->unpublish($public->fresh());

        $this->assertSame('private', $public->fresh()->visibility->value);
        $this->assertSame(2, $this->quota()->usage($author));
    }

    public function test_restoring_a_deleted_recipe_is_blocked_when_it_would_exceed_the_limit(): void
    {
        $owner = User::factory()->contributor()->create();
        $owner->entitlement->update(['personal_recipe_limit_override' => 1]);
        $deleted = Recipe::factory()->create(['author_id' => $owner->id]);
        $deleted->delete();
        $this->fill($owner, 1);

        $this->actingAs($owner);

        $this->expectException(ValidationException::class);
        $deleted->restore();
    }

    public function test_approval_by_a_reviewer_is_not_blocked_by_the_authors_quota(): void
    {
        $author = User::factory()->contributor()->create();
        $author->entitlement->update(['personal_recipe_limit_override' => 1]);
        $pending = Recipe::factory()->complete()->pending()->create(['author_id' => $author->id]);

        $this->actingAs(User::factory()->admin()->create());
        app(RecipePublishingService::class)->approve($pending, auth()->user());

        $this->assertSame(0, $this->quota()->usage($author));
    }

    public function test_editorial_staff_are_not_limited_by_personal_quota(): void
    {
        $editor = User::factory()->editor()->create();

        $this->assertNull($this->quota()->personalRecipeLimit($editor));
        $this->assertNull($this->quota()->usageLabel($editor));
    }

    public function test_the_creation_check_locks_the_owner_row_inside_a_transaction(): void
    {
        $user = User::factory()->contributor()->create();
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = [strtolower($query->sql), DB::transactionLevel()];
        });

        $this->create($user);

        $locks = array_filter($statements, fn (array $s): bool => str_contains($s[0], 'for update') && str_contains($s[0], '"users"'));
        $this->assertNotEmpty($locks);

        $lockIndex = array_key_first($locks);
        $countIndex = array_key_first(array_filter($statements, fn (array $s): bool => str_contains($s[0], 'count(*)') && str_contains($s[0], '"recipes"')));
        $insertIndex = array_key_first(array_filter($statements, fn (array $s): bool => str_contains($s[0], 'insert into "recipes"')));

        $this->assertLessThan($countIndex, $lockIndex);
        $this->assertLessThan($insertIndex, $countIndex);
    }

    public function test_failed_creation_rolls_back_so_the_slot_is_not_consumed(): void
    {
        $user = User::factory()->contributor()->create();
        $user->entitlement->update(['personal_recipe_limit_override' => 1]);

        try {
            $this->quota()->withinPersonalQuota($user->fresh(), function (): never {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, $this->quota()->usage($user));
        $this->create($user->fresh());
        $this->assertSame(1, $this->quota()->usage($user));
    }

    public function test_sharing_is_limited_per_recipe_and_revoking_frees_a_slot(): void
    {
        $owner = User::factory()->contributor()->create();
        $owner->entitlement->update(['sharing_limit_override' => 2]);
        $recipe = Recipe::factory()->shared()->create(['author_id' => $owner->id]);
        $service = app(RecipeShareService::class);
        $people = User::factory()->count(3)->create();

        $first = $service->share($owner->fresh(), $recipe, $people[0]->email, 'en');
        $service->share($owner->fresh(), $recipe, $people[1]->email, 'en');

        try {
            $service->share($owner->fresh(), $recipe, $people[2]->email, 'en');
            $this->fail('Exceeded the sharing limit.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('2', $e->errors()['email'][0]);
        }

        $this->assertSame(2, RecipeShare::active()->count());
        $service->share($owner->fresh(), $recipe, $people[0]->email, 'en');

        $service->revoke($owner->fresh(), $first);
        $service->share($owner->fresh(), $recipe, $people[2]->email, 'en');

        $this->assertSame(2, RecipeShare::active()->count());
    }

    public function test_free_plan_limits_sharing_to_five_people_per_recipe(): void
    {
        $owner = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->shared()->create(['author_id' => $owner->id]);
        $service = app(RecipeShareService::class);

        foreach (User::factory()->count(5)->create() as $person) {
            $service->share($owner, $recipe, $person->email, 'en');
        }

        $this->expectException(ValidationException::class);
        $service->share($owner, $recipe, User::factory()->create()->email, 'en');
    }

    public function test_database_rejects_negative_limits(): void
    {
        $this->expectException(QueryException::class);
        DB::table('plans')->where('code', 'free')->update(['max_personal_recipes' => -1]);
    }

    public function test_usage_is_shown_with_an_upgrade_placeholder_at_the_limit_in_both_languages(): void
    {
        $user = User::factory()->contributor()->create();
        $this->fill($user, 8);

        $this->assertSame('8 of 20 personal recipes used.', $this->quota()->usageLabel($user));

        app()->setLocale('el');
        $this->assertSame('8 από 20 προσωπικές συνταγές σε χρήση.', $this->quota()->usageLabel($user));
        app()->setLocale('en');

        $user->entitlement->update(['personal_recipe_limit_override' => 8]);
        $notice = app(QuotaUsageNotice::class)->for($user->fresh());
        $this->assertStringContainsString('8 of 8 personal recipes used.', $notice);
        $this->assertStringContainsString('Paid plans may become available', $notice);

        Filament::setCurrentPanel('admin');
        Livewire::actingAs($user->fresh())->test(ListRecipes::class)->assertSee('8 of 8 personal recipes used.');
    }

    public function test_only_admins_can_manage_plans_and_overrides(): void
    {
        Filament::setCurrentPanel('admin');
        $member = User::factory()->contributor()->create();
        $entitlement = $member->entitlement;
        $plan = Plan::where('code', 'free')->firstOrFail();

        foreach ([$member, User::factory()->editor()->create()] as $user) {
            $this->assertFalse($user->can('update', $entitlement));
            $this->assertFalse($user->can('update', $plan));
            $this->actingAs($user)->get('/admin/plans')->assertForbidden();
            $this->actingAs($user)->get('/admin/user-entitlements')->assertForbidden();

            try {
                $this->quota()->updateEntitlement($user, $entitlement, $plan->id, 99, null);
                $this->fail('Non-admin changed an entitlement.');
            } catch (AuthorizationException) {
            }
        }

        $this->assertNull($entitlement->fresh()->personal_recipe_limit_override);
        $this->assertFalse(User::factory()->admin()->create()->can('delete', $plan));
    }

    public function test_admin_sets_overrides_and_edits_plans_but_cannot_change_a_plan_code(): void
    {
        Filament::setCurrentPanel('admin');
        $admin = User::factory()->admin()->create();
        $member = User::factory()->contributor()->create();
        $plan = Plan::where('code', 'free')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(EditUserEntitlement::class, ['record' => $member->entitlement->getRouteKey()])
            ->fillForm(['plan_id' => $plan->id, 'personal_recipe_limit_override' => 50, 'sharing_limit_override' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(50, $member->entitlement->fresh()->personal_recipe_limit_override);
        $this->assertSame($admin->id, $member->entitlement->fresh()->granted_by);

        Livewire::actingAs($admin)
            ->test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->fillForm(['max_personal_recipes' => 25, 'code' => 'hacked'])
            ->call('save');

        $plan->refresh();
        $this->assertSame(25, $plan->max_personal_recipes);
        $this->assertSame('free', $plan->code);
    }
}
