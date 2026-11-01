<?php

namespace App\Livewire;

use App\Enums\HouseholdAbility;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use App\Services\HouseholdAccess;
use App\Services\HouseholdInvitationService;
use App\Services\HouseholdService;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class HouseholdManager extends Component
{
    #[Locked]
    public string $locale = 'en';

    #[Locked]
    public int $householdId = 0;

    public string $name = '';

    public string $email = '';

    public string $inviteRole = 'member';

    public function mount(string $locale, int $householdId): void
    {
        $this->locale = $locale;
        $this->householdId = $householdId;
        $this->name = $this->household()->name;
    }

    public function rename(HouseholdService $service): void
    {
        $this->validate(['name' => ['required', 'string', 'max:100']]);
        $service->rename(auth()->user(), $this->household(), $this->name);
        session()->flash('status', __('households.flash.renamed'));
    }

    public function invite(HouseholdInvitationService $service): void
    {
        $this->resetErrorBag();
        $this->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'inviteRole' => ['required', 'in:'.implode(',', array_map(fn (HouseholdRole $role): string => $role->value, HouseholdRole::assignable()))],
        ]);

        $service->invite(auth()->user(), $this->household(), $this->email, HouseholdRole::from($this->inviteRole), $this->locale);

        $this->email = '';
        session()->flash('status', __('households.flash.invited'));
    }

    public function revokeInvitation(HouseholdInvitationService $service, int $invitationId): void
    {
        $invitation = HouseholdInvitation::query()->where('household_id', $this->householdId)->findOrFail($invitationId);
        $service->revoke(auth()->user(), $invitation);
        session()->flash('status', __('households.flash.invitation_revoked'));
    }

    public function changeRole(HouseholdService $service, int $userId, string $role): void
    {
        $service->changeRole(auth()->user(), $this->household(), $this->memberUser($userId), HouseholdRole::tryFrom($role) ?? abort(422));
        session()->flash('status', __('households.flash.role_changed'));
    }

    public function removeMember(HouseholdService $service, int $userId): void
    {
        $service->removeMember(auth()->user(), $this->household(), $this->memberUser($userId));
        session()->flash('status', __('households.flash.member_removed'));
    }

    public function transferOwnership(HouseholdService $service, int $userId): void
    {
        $service->transferOwnership(auth()->user(), $this->household(), $this->memberUser($userId));
        session()->flash('status', __('households.flash.ownership_transferred'));
    }

    public function leave(HouseholdService $service): void
    {
        $service->leave(auth()->user(), $this->household());
        session()->flash('status', __('households.flash.left'));

        $this->redirectRoute('households.index', ['locale' => $this->locale]);
    }

    public function deleteHousehold(HouseholdService $service): void
    {
        $service->delete(auth()->user(), $this->household());
        session()->flash('status', __('households.flash.deleted'));

        $this->redirectRoute('households.index', ['locale' => $this->locale]);
    }

    public function render(HouseholdAccess $access): View
    {
        app()->setLocale($this->locale);

        $household = $this->household();
        $user = auth()->user();
        $role = $access->roleOf($user, $household);

        return view('livewire.household-manager', [
            'household' => $household,
            'role' => $role,
            'members' => $household->members()->with('user:id,name,email')->orderBy('joined_at')->get(),
            'invitations' => $role?->can(HouseholdAbility::ManageInvitations)
                ? $household->invitations()->usable()->orderBy('created_at')->get()
                : collect(),
            'grantable' => $role?->grantableRoles() ?? [],
        ]);
    }

    // A missing household and a household the user cannot see give the same response.
    private function household(): Household
    {
        $household = Household::query()->find($this->householdId);

        abort_unless($household !== null && auth()->user()?->can('view', $household), 404);

        return $household;
    }

    private function memberUser(int $userId): User
    {
        return User::query()
            ->whereKey($userId)
            ->whereIn('id', $this->household()->members()->select('user_id'))
            ->firstOrFail();
    }
}
