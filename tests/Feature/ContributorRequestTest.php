<?php

namespace Tests\Feature;

use App\Enums\ContributorRequestStatus;
use App\Filament\Resources\ContributorRequests\Pages\ListContributorRequests;
use App\Livewire\ContributorApplication;
use App\Models\ContributorRequest;
use App\Models\Recipe;
use App\Models\User;
use App\Notifications\ContributorRequestApproved;
use App\Notifications\ContributorRequestRejected;
use App\Notifications\ContributorRequestSubmitted;
use App\Services\ContributorRequestService;
use App\Support\AppPermissions;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ContributorRequestTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ContributorRequestService
    {
        return app(ContributorRequestService::class);
    }

    public function test_members_cannot_create_recipes_until_approved(): void
    {
        $member = User::factory()->create();

        $this->assertFalse($member->can('create', Recipe::class));
        $this->assertTrue($member->can('create', ContributorRequest::class));
    }

    public function test_member_can_apply_and_reviewers_are_notified(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $editor = User::factory()->editor()->create();
        $member = User::factory()->create();

        $request = $this->service()->submit($member, '  I bake bread.  ', 'el');

        $this->assertSame(ContributorRequestStatus::Pending, $request->status);
        $this->assertSame('I bake bread.', $request->message);
        $this->assertSame('el', $request->locale);
        Notification::assertSentTo($admin, ContributorRequestSubmitted::class);
        Notification::assertNotSentTo($editor, ContributorRequestSubmitted::class);
        Notification::assertNotSentTo($member, ContributorRequestSubmitted::class);
    }

    public function test_only_one_pending_application_per_user(): void
    {
        $member = User::factory()->create();
        $this->service()->submit($member, null, 'en');

        try {
            $this->service()->submit($member, null, 'en');
            $this->fail('A second pending application was accepted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::transaction(fn () => ContributorRequest::create(['user_id' => $member->id]));
            $this->fail('The database allowed two pending applications.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, ContributorRequest::count());
    }

    public function test_contributors_and_unverified_users_cannot_apply(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->submit(User::factory()->contributor()->create(), null, 'en');
    }

    public function test_unverified_member_cannot_apply(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service()->submit(User::factory()->unverified()->create(), null, 'en');
    }

    public function test_applications_are_rate_limited(): void
    {
        $member = User::factory()->create();

        foreach (range(1, 3) as $attempt) {
            $request = $this->service()->submit($member, null, 'en');
            $this->service()->cancel($member, $request);
        }

        $this->expectException(ValidationException::class);
        $this->service()->submit($member, null, 'en');
    }

    public function test_approval_assigns_contributor_role_notifies_and_is_audited(): void
    {
        Notification::fake();
        Log::spy();
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $request = $this->service()->submit($member, null, 'el');

        $approved = $this->service()->approve($admin, $request);

        $this->assertSame(ContributorRequestStatus::Approved, $approved->status);
        $this->assertSame($admin->id, $approved->reviewed_by);
        $this->assertNotNull($approved->reviewed_at);
        $this->assertTrue($member->fresh()->hasRole(AppPermissions::CONTRIBUTOR));
        $this->assertTrue($member->fresh()->can('create', Recipe::class));
        Notification::assertSentTo($member, ContributorRequestApproved::class, fn ($notification) => $notification->locale === 'el');
        Log::shouldHaveReceived('info')->withArgs(fn (string $message) => $message === 'audit.contributor_request_approved')->once();
    }

    public function test_approval_is_idempotent(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $request = $this->service()->submit(User::factory()->create(), null, 'en');

        $this->service()->approve($admin, $request);
        $this->service()->approve($admin, $request->fresh());

        Notification::assertSentToTimes($request->user, ContributorRequestApproved::class, 1);
        $this->assertSame(1, $request->user->fresh()->roles()->where('name', AppPermissions::CONTRIBUTOR)->count());
    }

    public function test_rejection_records_reason_keeps_the_user_a_member_and_allows_reapplying(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $request = $this->service()->submit($member, null, 'en');

        $rejected = $this->service()->reject($admin, $request, 'Not enough detail');

        $this->assertSame(ContributorRequestStatus::Rejected, $rejected->status);
        $this->assertSame('Not enough detail', $rejected->rejection_reason);
        $this->assertFalse($member->fresh()->hasRole(AppPermissions::CONTRIBUTOR));
        Notification::assertSentTo($member, ContributorRequestRejected::class);

        $this->service()->submit($member, 'Second try', 'en');
        $this->assertSame(2, ContributorRequest::where('user_id', $member->id)->count());
    }

    public function test_rejection_requires_a_reason_and_a_decided_application_cannot_be_flipped(): void
    {
        $admin = User::factory()->admin()->create();
        $request = $this->service()->submit(User::factory()->create(), null, 'en');

        try {
            $this->service()->reject($admin, $request, '  ');
            $this->fail('Rejected without a reason.');
        } catch (ValidationException) {
            $this->assertTrue($request->fresh()->isPending());
        }

        $this->service()->approve($admin, $request);

        $this->expectException(ValidationException::class);
        $this->service()->reject($admin, $request->fresh(), 'Changed my mind');
    }

    public function test_only_reviewers_can_decide_and_never_on_their_own_application(): void
    {
        $request = $this->service()->submit(User::factory()->create(), null, 'en');

        foreach ([User::factory()->editor()->create(), User::factory()->contributor()->create(), User::factory()->create()] as $user) {
            try {
                $this->service()->approve($user, $request);
                $this->fail('A non-reviewer approved an application.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $admin = User::factory()->admin()->create();
        $own = ContributorRequest::create(['user_id' => $admin->id]);

        $this->assertFalse($admin->can('approve', $own));
        $this->assertFalse($admin->can('reject', $own));
        $this->assertTrue($request->fresh()->isPending());
    }

    public function test_applicant_can_cancel_only_their_own_pending_application(): void
    {
        $member = User::factory()->create();
        $request = $this->service()->submit($member, null, 'en');

        try {
            $this->service()->cancel(User::factory()->create(), $request);
            $this->fail('Someone else cancelled the application.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(ContributorRequestStatus::Cancelled, $this->service()->cancel($member, $request)->status);
        $this->assertFalse($member->can('cancel', $request->fresh()));
    }

    public function test_revoking_contributor_access_keeps_existing_recipes_readable(): void
    {
        Log::spy();
        $admin = User::factory()->admin()->create();
        $contributor = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->create(['author_id' => $contributor->id]);
        $recipe->fill(['en' => ['title' => 'Mine', 'slug' => 'mine']])->save();

        $this->service()->revokeContributor($admin, $contributor);

        $contributor = $contributor->fresh();
        $this->assertFalse($contributor->hasRole(AppPermissions::CONTRIBUTOR));
        $this->assertFalse($contributor->can('create', Recipe::class));
        $this->assertTrue($contributor->can('view', $recipe));
        $this->assertDatabaseHas('recipes', ['id' => $recipe->id, 'deleted_at' => null]);
        $this->actingAs($contributor)->get('/en/recipes/mine')->assertOk();
        Log::shouldHaveReceived('info')->withArgs(fn (string $message) => $message === 'audit.contributor_revoked')->once();
    }

    public function test_revocation_requires_a_reviewer_and_not_self(): void
    {
        $contributor = User::factory()->contributor()->create();

        foreach ([[User::factory()->editor()->create(), $contributor], [$contributor, $contributor]] as [$actor, $target]) {
            try {
                $this->service()->revokeContributor($actor, $target);
                $this->fail('Revocation was allowed.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertTrue($contributor->fresh()->hasRole(AppPermissions::CONTRIBUTOR));
    }

    public function test_member_application_page_flow(): void
    {
        Notification::fake();
        $member = User::factory()->create();
        User::factory()->admin()->create();

        $this->get('/en/contributor')->assertRedirect('/login');
        $this->actingAs(User::factory()->unverified()->create())->get('/en/contributor')->assertRedirect('/email/verify');

        $this->actingAs($member)->get('/en/contributor')->assertOk()->assertSee('Apply to contribute');
        $this->actingAs($member)->get('/el/contributor')->assertOk()->assertSee('Αίτηση συνεργασίας');

        Livewire::actingAs($member)->test(ContributorApplication::class, ['locale' => 'en'])
            ->set('message', 'I love cooking')
            ->call('submit')
            ->assertSee('waiting for review')
            ->call('cancel')
            ->assertSee('Apply to contribute');

        $this->assertSame(ContributorRequestStatus::Cancelled, ContributorRequest::first()->status);
    }

    public function test_rejected_applicant_sees_the_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $this->service()->reject($admin, $this->service()->submit($member, null, 'en'), 'Please add detail');

        $this->actingAs($member)->get('/en/contributor')->assertSee('Please add detail');
    }

    public function test_application_page_hides_the_form_from_contributors(): void
    {
        $this->actingAs(User::factory()->contributor()->create())->get('/en/contributor')
            ->assertOk()
            ->assertSee('You are a contributor')
            ->assertDontSee('Apply to contribute');
    }

    public function test_members_do_not_get_the_admin_panel_and_reviewers_manage_applications_there(): void
    {
        if (! extension_loaded('intl')) {
            $this->markTestSkipped('Filament table rendering requires the PHP intl extension.');
        }

        Filament::setCurrentPanel('admin');
        $admin = User::factory()->admin()->create();
        $request = $this->service()->submit(User::factory()->create(), null, 'en');

        $this->actingAs(User::factory()->create())->get('/admin/contributor-requests')->assertForbidden();
        $this->actingAs(User::factory()->editor()->create())->get('/admin/contributor-requests')->assertForbidden();

        Livewire::actingAs($admin)->test(ListContributorRequests::class)
            ->assertCanSeeTableRecords([$request])
            ->callTableAction('approve', $request);

        $this->assertTrue($request->user->fresh()->hasRole(AppPermissions::CONTRIBUTOR));

        Livewire::actingAs($admin)->test(ListContributorRequests::class)
            ->filterTable('status', 'approved')
            ->callTableAction('revoke', $request->fresh());

        $this->assertFalse($request->user->fresh()->hasRole(AppPermissions::CONTRIBUTOR));
    }

    public function test_reject_action_requires_a_reason(): void
    {
        if (! extension_loaded('intl')) {
            $this->markTestSkipped('Filament table rendering requires the PHP intl extension.');
        }

        Filament::setCurrentPanel('admin');
        $admin = User::factory()->admin()->create();
        $request = $this->service()->submit(User::factory()->create(), null, 'en');

        Livewire::actingAs($admin)->test(ListContributorRequests::class)
            ->callTableAction('reject', $request, ['rejection_reason' => ''])
            ->assertHasTableActionErrors(['rejection_reason' => 'required']);

        Livewire::actingAs($admin)->test(ListContributorRequests::class)
            ->callTableAction('reject', $request, ['rejection_reason' => 'Not yet']);

        $this->assertSame(ContributorRequestStatus::Rejected, $request->fresh()->status);
    }

    public function test_database_rejects_unknown_statuses(): void
    {
        $request = ContributorRequest::create(['user_id' => User::factory()->create()->id]);

        $this->expectException(QueryException::class);
        DB::table('contributor_requests')->where('id', $request->id)->update(['status' => 'bogus']);
    }
}
