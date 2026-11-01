<?php

namespace Tests\Feature;

use App\Enums\HouseholdAbility;
use App\Enums\HouseholdRole;
use App\Livewire\HouseholdIndex;
use App\Livewire\HouseholdInvitationAccept;
use App\Livewire\HouseholdManager;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\Recipe;
use App\Models\User;
use App\Notifications\HouseholdInvitationNotification;
use App\Services\HouseholdInvitationService;
use App\Services\HouseholdService;
use App\Services\RecipeAccessService;
use App\Support\AppPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class HouseholdTest extends TestCase
{
    use RefreshDatabase;

    private function household(User $owner, array $members = []): Household
    {
        $household = Household::factory()->ownedBy($owner)->create();

        foreach ($members as [$user, $role]) {
            $household->members()->create(['user_id' => $user->id, 'role' => $role]);
        }

        return $household;
    }

    private function invitation(Household $household, string $email, string $token = 'plain-token', array $overrides = []): HouseholdInvitation
    {
        return HouseholdInvitation::factory()->create([
            'household_id' => $household->id,
            'email' => $email,
            'token_hash' => HouseholdInvitation::hashToken($token),
            ...$overrides,
        ]);
    }

    public function test_role_ability_matrix_matches_the_agreed_roles(): void
    {
        $this->assertSame(HouseholdAbility::cases(), HouseholdRole::Owner->abilities());
        $this->assertFalse(HouseholdRole::Admin->can(HouseholdAbility::Delete));
        $this->assertFalse(HouseholdRole::Admin->can(HouseholdAbility::TransferOwnership));
        $this->assertTrue(HouseholdRole::Admin->can(HouseholdAbility::ManageMembers));
        $this->assertTrue(HouseholdRole::Member->can(HouseholdAbility::EditPlans));
        $this->assertTrue(HouseholdRole::Member->can(HouseholdAbility::EditShopping));
        $this->assertTrue(HouseholdRole::Member->can(HouseholdAbility::ConsumeBatches));
        $this->assertFalse(HouseholdRole::Member->can(HouseholdAbility::ManageMembers));
        $this->assertSame([HouseholdAbility::View], HouseholdRole::Viewer->abilities());
    }

    public function test_admin_manages_only_members_and_viewers_while_owner_manages_everyone_else(): void
    {
        $this->assertTrue(HouseholdRole::Owner->canManage(HouseholdRole::Admin));
        $this->assertFalse(HouseholdRole::Owner->canManage(HouseholdRole::Owner));
        $this->assertTrue(HouseholdRole::Admin->canManage(HouseholdRole::Member));
        $this->assertFalse(HouseholdRole::Admin->canManage(HouseholdRole::Admin));
        $this->assertFalse(HouseholdRole::Admin->canManage(HouseholdRole::Owner));
        $this->assertFalse(HouseholdRole::Member->canManage(HouseholdRole::Viewer));
    }

    public function test_every_role_including_ordinary_members_may_use_the_planner_and_create_households(): void
    {
        foreach (['member' => User::factory(), 'contributor' => User::factory()->contributor(), 'editor' => User::factory()->editor(), 'admin' => User::factory()->admin()] as $factory) {
            $user = $factory->create();

            $this->assertTrue($user->can(AppPermissions::PLANNER_USE));
            $this->assertTrue($user->can('create', Household::class));
        }
    }

    public function test_a_role_stripped_of_household_creation_can_still_use_the_planner(): void
    {
        $user = User::factory()->create();
        $user->roles()->first()->revokePermissionTo(AppPermissions::HOUSEHOLDS_CREATE);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($user->fresh()->can(AppPermissions::PLANNER_USE));
        $this->assertFalse($user->fresh()->can('create', Household::class));
    }

    public function test_user_without_planner_permission_or_verified_email_is_locked_out_of_their_own_household(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $user->roles()->first()->revokePermissionTo(AppPermissions::PLANNER_USE);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($user->fresh()->can('view', $household));

        $unverified = User::factory()->unverified()->create();
        $this->assertFalse($unverified->can('view', $this->household($unverified)));
    }

    public function test_global_administrator_gets_no_access_to_someone_elses_household(): void
    {
        $admin = User::factory()->admin()->create();
        $household = $this->household(User::factory()->create());

        $this->assertFalse($admin->can('view', $household));
        $this->assertFalse($admin->can('delete', $household));
        $this->actingAs($admin)->get(route('households.show', ['locale' => 'en', 'household' => $household->id]))->assertNotFound();
    }

    public function test_non_member_gets_the_same_response_for_missing_and_existing_households(): void
    {
        $household = $this->household(User::factory()->create());
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('households.show', ['locale' => 'en', 'household' => $household->id]))->assertNotFound();
        $this->actingAs($stranger)->get(route('households.show', ['locale' => 'en', 'household' => 999999]))->assertNotFound();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('households.index', ['locale' => 'en']))->assertRedirect('/login');
    }

    public function test_creating_a_household_makes_the_creator_its_only_owner_and_respects_the_limit(): void
    {
        config(['planner.max_households_per_user' => 1]);
        $user = User::factory()->create();

        $household = app(HouseholdService::class)->create($user, '  Home  ');

        $this->assertSame('Home', $household->name);
        $this->assertSame(HouseholdRole::Owner, $household->members()->firstOrFail()->role);

        $this->expectException(ValidationException::class);
        app(HouseholdService::class)->create($user, 'Second');
    }

    public function test_database_allows_only_one_owner_per_household(): void
    {
        $household = $this->household(User::factory()->create());

        $this->expectException(QueryException::class);
        $household->members()->create(['user_id' => User::factory()->create()->id, 'role' => HouseholdRole::Owner]);
    }

    public function test_database_rejects_unknown_roles_and_owner_invitations(): void
    {
        $household = $this->household(User::factory()->create());

        $this->expectException(QueryException::class);
        DB::table('household_invitations')->insert([
            'household_id' => $household->id, 'email' => 'a@example.com', 'role' => 'owner',
            'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_viewer_and_member_cannot_manage_members_or_invitations(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($owner, [[$viewer, HouseholdRole::Viewer], [$member, HouseholdRole::Member]]);

        foreach ([$viewer, $member] as $actor) {
            $this->assertTrue($actor->can('view', $household));
            $this->assertFalse($actor->can('manageMembers', $household));
            $this->assertFalse($actor->can('manageInvitations', $household));
            $this->assertFalse($actor->can('update', $household));
        }
    }

    public function test_admin_cannot_change_or_remove_peers_or_the_owner_but_can_manage_members(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $otherAdmin = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($owner, [[$admin, HouseholdRole::Admin], [$otherAdmin, HouseholdRole::Admin], [$member, HouseholdRole::Member]]);
        $service = app(HouseholdService::class);

        foreach ([$owner, $otherAdmin] as $target) {
            try {
                $service->removeMember($admin, $household, $target);
                $this->fail('Admin removed a protected member.');
            } catch (ValidationException) {
                $this->assertDatabaseHas('household_members', ['household_id' => $household->id, 'user_id' => $target->id]);
            }
        }

        $service->changeRole($admin, $household, $member, HouseholdRole::Viewer);
        $this->assertSame(HouseholdRole::Viewer, $household->members()->where('user_id', $member->id)->firstOrFail()->role);

        $this->expectException(ValidationException::class);
        $service->changeRole($admin, $household, $member, HouseholdRole::Admin);
    }

    public function test_admin_cannot_delete_the_household_or_transfer_ownership(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $household = $this->household($owner, [[$admin, HouseholdRole::Admin]]);

        try {
            app(HouseholdService::class)->transferOwnership($admin, $household, $admin);
            $this->fail('Admin transferred ownership.');
        } catch (AuthorizationException) {
        }

        $this->expectException(AuthorizationException::class);
        app(HouseholdService::class)->delete($admin, $household);
    }

    public function test_owner_cannot_leave_but_other_members_can(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($owner, [[$member, HouseholdRole::Member]]);

        app(HouseholdService::class)->leave($member, $household);
        $this->assertDatabaseMissing('household_members', ['user_id' => $member->id]);

        $this->expectException(AuthorizationException::class);
        app(HouseholdService::class)->leave($owner, $household);
    }

    public function test_transferring_ownership_leaves_exactly_one_owner_and_demotes_the_previous_one(): void
    {
        $owner = User::factory()->create();
        $heir = User::factory()->create();
        $household = $this->household($owner, [[$heir, HouseholdRole::Member]]);

        app(HouseholdService::class)->transferOwnership($owner, $household, $heir);

        $this->assertSame(1, $household->members()->where('role', 'owner')->count());
        $this->assertSame(HouseholdRole::Owner, $household->members()->where('user_id', $heir->id)->firstOrFail()->role);
        $this->assertSame(HouseholdRole::Admin, $household->members()->where('user_id', $owner->id)->firstOrFail()->role);

        // A stale second attempt by the former owner is refused rather than creating a second owner.
        $this->expectException(AuthorizationException::class);
        app(HouseholdService::class)->transferOwnership($owner, $household, $heir);
    }

    public function test_cannot_transfer_ownership_to_a_non_member(): void
    {
        $owner = User::factory()->create();
        $household = $this->household($owner);

        $this->expectException(ValidationException::class);
        app(HouseholdService::class)->transferOwnership($owner, $household, User::factory()->create());
    }

    public function test_owner_deletes_household_and_its_members_and_invitations_go_with_it(): void
    {
        $owner = User::factory()->create();
        $household = $this->household($owner);
        $this->invitation($household, 'x@example.com');

        app(HouseholdService::class)->delete($owner, $household);

        $this->assertDatabaseMissing('households', ['id' => $household->id]);
        $this->assertSame(0, DB::table('household_members')->count());
        $this->assertSame(0, DB::table('household_invitations')->count());
    }

    public function test_invitation_is_emailed_in_the_inviters_locale_and_only_the_token_hash_is_stored(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $household = $this->household($owner);

        $invitation = app(HouseholdInvitationService::class)->invite($owner, $household, '  New.Person@Example.com ', HouseholdRole::Member, 'el');

        $this->assertSame('new.person@example.com', $invitation->email);
        Notification::assertSentOnDemand(HouseholdInvitationNotification::class, function (HouseholdInvitationNotification $notification, array $channels, $notifiable) use ($invitation): bool {
            $this->assertSame('el', $notification->locale);
            $this->assertSame(['mail'], $channels);
            $this->assertSame(hash('sha256', $notification->token), $invitation->token_hash);
            $this->assertNotSame($notification->token, $invitation->token_hash);

            return $notifiable->routes['mail'] === 'new.person@example.com';
        });
    }

    public function test_reinviting_revokes_the_previous_link(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $household = $this->household($owner);
        $service = app(HouseholdInvitationService::class);

        $first = $service->invite($owner, $household, 'a@example.com', HouseholdRole::Member, 'en');
        $second = $service->invite($owner, $household, 'A@example.com', HouseholdRole::Viewer, 'en');

        $this->assertNotNull($first->fresh()->revoked_at);
        $this->assertNull($second->fresh()->revoked_at);
        $this->assertSame(1, HouseholdInvitation::query()->pending()->count());
    }

    public function test_invitation_roles_are_limited_by_the_inviters_role(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $household = $this->household($owner, [[$admin, HouseholdRole::Admin]]);

        $this->expectException(ValidationException::class);
        app(HouseholdInvitationService::class)->invite($admin, $household, 'a@example.com', HouseholdRole::Admin, 'en');
    }

    public function test_member_and_viewer_cannot_send_invitations(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($owner, [[$member, HouseholdRole::Member]]);

        $this->expectException(AuthorizationException::class);
        app(HouseholdInvitationService::class)->invite($member, $household, 'a@example.com', HouseholdRole::Viewer, 'en');
    }

    public function test_invitation_attempts_are_rate_limited_per_inviter(): void
    {
        Notification::fake();
        config(['planner.invitations_per_hour' => 2]);
        RateLimiter::clear('household-invite:1');
        $owner = User::factory()->create();
        $household = $this->household($owner);
        $service = app(HouseholdInvitationService::class);

        $service->invite($owner, $household, 'a@example.com', HouseholdRole::Member, 'en');
        $service->invite($owner, $household, 'b@example.com', HouseholdRole::Member, 'en');

        $this->expectException(ValidationException::class);
        $service->invite($owner, $household, 'c@example.com', HouseholdRole::Member, 'en');
    }

    public function test_member_limit_counts_pending_invitations(): void
    {
        Notification::fake();
        config(['planner.max_members_per_household' => 2]);
        $owner = User::factory()->create();
        $household = $this->household($owner);
        $service = app(HouseholdInvitationService::class);

        $service->invite($owner, $household, 'a@example.com', HouseholdRole::Member, 'en');

        $this->expectException(ValidationException::class);
        $service->invite($owner, $household, 'b@example.com', HouseholdRole::Member, 'en');
    }

    public function test_inviting_an_existing_member_is_refused(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['email' => 'Member@Example.com']);
        $household = $this->household($owner, [[$member, HouseholdRole::Member]]);

        $this->expectException(ValidationException::class);
        app(HouseholdInvitationService::class)->invite($owner, $household, 'member@example.com', HouseholdRole::Viewer, 'en');
    }

    public function test_invited_user_accepts_once_and_gets_the_invited_role(): void
    {
        $household = $this->household(User::factory()->create());
        $guest = User::factory()->create(['email' => 'guest@example.com']);
        $invitation = $this->invitation($household, 'guest@example.com', 'good-token', ['role' => HouseholdRole::Viewer]);
        $service = app(HouseholdInvitationService::class);

        $service->accept($guest, 'good-token');

        $this->assertSame(HouseholdRole::Viewer, $household->members()->where('user_id', $guest->id)->firstOrFail()->role);
        $this->assertNotNull($invitation->fresh()->accepted_at);

        $this->expectException(ValidationException::class);
        $service->accept($guest, 'good-token');
    }

    public function test_every_invalid_invitation_fails_with_the_same_message(): void
    {
        $household = $this->household(User::factory()->create());
        $guest = User::factory()->create(['email' => 'guest@example.com']);
        $this->invitation($household, 'guest@example.com', 'expired', ['expires_at' => now()->subMinute()]);
        $this->invitation($household, 'guest@example.com', 'revoked', ['revoked_at' => now()]);
        $this->invitation($household, 'someone-else@example.com', 'wrong-email');
        $messages = [];

        foreach (['expired', 'revoked', 'wrong-email', 'unknown'] as $token) {
            try {
                app(HouseholdInvitationService::class)->accept($guest, $token);
                $this->fail("Accepted {$token}.");
            } catch (ValidationException $exception) {
                $messages[] = $exception->errors()['token'][0];
            }
        }

        $this->assertCount(1, array_unique($messages));
        $this->assertSame(0, $household->members()->where('user_id', $guest->id)->count());
    }

    public function test_unverified_user_cannot_accept_an_invitation(): void
    {
        $household = $this->household(User::factory()->create());
        $guest = User::factory()->unverified()->create(['email' => 'guest@example.com']);
        $this->invitation($household, 'guest@example.com', 'good-token');

        $this->expectException(ValidationException::class);
        app(HouseholdInvitationService::class)->accept($guest, 'good-token');
    }

    public function test_acceptance_respects_the_member_limit(): void
    {
        config(['planner.max_members_per_household' => 1]);
        $household = $this->household(User::factory()->create());
        $guest = User::factory()->create(['email' => 'guest@example.com']);
        $this->invitation($household, 'guest@example.com', 'good-token');

        $this->expectException(ValidationException::class);
        app(HouseholdInvitationService::class)->accept($guest, 'good-token');
    }

    public function test_joining_a_household_grants_no_access_to_private_recipes(): void
    {
        $owner = User::factory()->contributor()->create();
        $guest = User::factory()->create();
        $household = $this->household($owner, [[$guest, HouseholdRole::Admin]]);
        $recipe = Recipe::factory()->create(['author_id' => $owner->id, 'visibility' => 'private']);

        $this->assertTrue($guest->can('view', $household));
        $this->assertFalse(app(RecipeAccessService::class)->canView($guest, $recipe));
    }

    public function test_invitation_page_shows_the_invitation_or_a_generic_failure(): void
    {
        $household = $this->household(User::factory()->create());
        $guest = User::factory()->create(['email' => 'guest@example.com']);
        $household->update(['name' => 'Papadakis Home']);
        $this->invitation($household, 'guest@example.com', 'good-token');

        $this->actingAs($guest)->get(route('households.invitations.show', ['locale' => 'en', 'token' => 'good-token']))
            ->assertOk()->assertSee('Papadakis Home');
        $this->actingAs($guest)->get(route('households.invitations.show', ['locale' => 'en', 'token' => 'nope']))
            ->assertOk()->assertSee('invalid or has expired')->assertDontSee('Papadakis Home');
    }

    public function test_accepting_through_the_page_redirects_into_the_household(): void
    {
        $household = $this->household(User::factory()->create());
        $guest = User::factory()->create(['email' => 'guest@example.com']);
        $this->invitation($household, 'guest@example.com', 'good-token');

        Livewire::actingAs($guest)->test(HouseholdInvitationAccept::class, ['locale' => 'en', 'token' => 'good-token'])
            ->call('accept')
            ->assertRedirect(route('households.show', ['locale' => 'en', 'household' => $household->id]));
    }

    public function test_creating_a_household_from_the_index_page(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(HouseholdIndex::class, ['locale' => 'en'])
            ->set('name', 'Our kitchen')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame('Our kitchen', $user->households()->firstOrFail()->name);
    }

    public function test_index_page_requires_a_name(): void
    {
        Livewire::actingAs(User::factory()->create())->test(HouseholdIndex::class, ['locale' => 'en'])
            ->set('name', '')->call('create')->assertHasErrors(['name' => 'required']);
    }

    public function test_manager_changes_a_role_and_hides_controls_from_a_viewer(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $viewer = User::factory()->create();
        $household = $this->household($owner, [[$member, HouseholdRole::Member], [$viewer, HouseholdRole::Viewer]]);
        $params = ['locale' => 'en', 'householdId' => $household->id];

        Livewire::actingAs($owner)->test(HouseholdManager::class, $params)
            ->call('changeRole', $member->id, 'viewer');
        $this->assertSame(HouseholdRole::Viewer, $household->members()->where('user_id', $member->id)->firstOrFail()->role);

        Livewire::actingAs($viewer)->test(HouseholdManager::class, $params)
            ->assertDontSee('Send invitation')->assertDontSee('Delete household')
            ->call('removeMember', $owner->id)->assertForbidden();
    }

    public function test_manager_refuses_users_outside_the_household(): void
    {
        $household = $this->household(User::factory()->create());

        Livewire::actingAs(User::factory()->create())->test(HouseholdManager::class, ['locale' => 'en', 'householdId' => $household->id])
            ->assertNotFound();
    }

    public function test_greek_interface_renders_translated_strings_and_roles(): void
    {
        $owner = User::factory()->create();
        $household = $this->household($owner);

        $this->actingAs($owner)->get(route('households.index', ['locale' => 'el']))
            ->assertOk()->assertSee('Τα νοικοκυριά σας')->assertSee('Ιδιοκτήτης');
        $this->actingAs($owner)->get(route('households.show', ['locale' => 'el', 'household' => $household->id]))
            ->assertOk()->assertSee('Αποστολή πρόσκλησης')->assertSee('Μέλη');
    }

    public function test_every_english_household_key_has_a_greek_translation(): void
    {
        $flatten = fn (array $lines): array => array_keys(Arr::dot($lines));

        $this->assertSame($flatten(require lang_path('en/households.php')), $flatten(require lang_path('el/households.php')));
    }

    public function test_navigation_shows_the_households_link_to_verified_users(): void
    {
        $this->actingAs(User::factory()->create())->get(route('home.localized', ['locale' => 'en']))
            ->assertSee(route('households.index', ['locale' => 'en'], false));
    }
}
