<div class="flex flex-col gap-8" wire:poll.30s>
    <header class="flex flex-col gap-2">
        <a class="text-link inline-flex min-h-11 items-center" href="{{ route('households.index', ['locale' => $locale], false) }}">&larr; {{ __('households.back') }}</a>
        <h1 class="text-3xl leading-tight break-words">{{ $household->name }}</h1>
        <p>{{ __('households.your_role', ['role' => $role?->label()]) }}</p>
        <a class="button button-dark min-h-11" href="{{ route('households.planner', ['locale' => $locale, 'household' => $household->id], false) }}">{{ __('planner.nav') }}</a>
    </header>

    @if (session('status'))
        <p class="status-message" role="status">{{ session('status') }}</p>
    @endif

    @can('update', $household)
        <form class="flex flex-col gap-3 sm:flex-row sm:items-end" wire:submit="rename">
            <div class="flex grow flex-col gap-1">
                <label class="font-semibold" for="rename-household">{{ __('households.name_label') }}</label>
                <input id="rename-household" class="min-h-11 rounded border border-current/30 px-3" type="text" wire:model="name" maxlength="100" required>
                @error('name')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <button class="button min-h-11" type="submit" wire:loading.attr="disabled">{{ __('households.rename') }}</button>
        </form>
    @endcan

    <section class="flex flex-col gap-3" aria-labelledby="members-heading">
        <h2 id="members-heading" class="text-xl">{{ __('households.members') }}</h2>
        @error('member')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        @error('role')<p class="field-error" role="alert">{{ $message }}</p>@enderror

        <ul class="flex flex-col gap-3">
            @foreach ($members as $member)
                <li class="flex flex-col gap-2 rounded border border-current/20 p-4" wire:key="member-{{ $member->user_id }}">
                    <div class="min-w-0">
                        <p class="font-semibold break-words">{{ $member->user->name }}</p>
                        <p class="text-sm break-all">{{ $member->user->email }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($role && $role->canManage($member->role) && $member->user_id !== auth()->id())
                            <label class="sr-only" for="role-{{ $member->user_id }}">{{ __('households.role_for', ['name' => $member->user->name]) }}</label>
                            <select id="role-{{ $member->user_id }}" class="min-h-11 rounded border border-current/30 px-2"
                                    wire:change="changeRole({{ $member->user_id }}, $event.target.value)">
                                @foreach ($grantable as $option)
                                    <option value="{{ $option->value }}" @selected($member->role === $option)>{{ $option->label() }}</option>
                                @endforeach
                            </select>
                            <button class="button min-h-11" type="button" wire:click="removeMember({{ $member->user_id }})"
                                    wire:confirm="{{ __('households.confirm_remove') }}">{{ __('households.remove') }}</button>
                        @else
                            <span class="text-sm">{{ $member->role->label() }}</span>
                        @endif

                        @if ($role === \App\Enums\HouseholdRole::Owner && $member->user_id !== auth()->id())
                            <button class="button min-h-11" type="button" wire:click="transferOwnership({{ $member->user_id }})"
                                    wire:confirm="{{ __('households.confirm_transfer') }}">{{ __('households.transfer') }}</button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    @can('manageInvitations', $household)
        <section class="flex flex-col gap-3" aria-labelledby="invite-heading">
            <h2 id="invite-heading" class="text-xl">{{ __('households.invite_heading') }}</h2>
            <form class="flex flex-col gap-3" wire:submit="invite">
                <label class="font-semibold" for="invite-email">{{ __('households.email_label') }}</label>
                <input id="invite-email" class="min-h-11 rounded border border-current/30 px-3" type="email" wire:model="email" maxlength="254" autocomplete="off" required>
                @error('email')<p class="field-error">{{ $message }}</p>@enderror

                <label class="font-semibold" for="invite-role">{{ __('households.role_label') }}</label>
                <select id="invite-role" class="min-h-11 rounded border border-current/30 px-2" wire:model="inviteRole">
                    @foreach ($grantable as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
                @error('inviteRole')<p class="field-error">{{ $message }}</p>@enderror

                <button class="button button-dark min-h-11" type="submit" wire:loading.attr="disabled">{{ __('households.send_invitation') }}</button>
            </form>

            <h3 class="font-semibold">{{ __('households.pending') }}</h3>
            <ul class="flex flex-col gap-2">
                @forelse ($invitations as $invitation)
                    <li class="flex flex-wrap items-center justify-between gap-2" wire:key="invitation-{{ $invitation->id }}">
                        <span class="min-w-0 break-all">{{ $invitation->email }} &middot; {{ $invitation->role->label() }}</span>
                        <button class="text-link min-h-11" type="button" wire:click="revokeInvitation({{ $invitation->id }})">{{ __('households.revoke') }}</button>
                    </li>
                @empty
                    <li>{{ __('households.no_pending') }}</li>
                @endforelse
            </ul>
        </section>
    @endcan

    <section class="flex flex-col gap-3 border-t border-current/20 pt-6">
        @can('leave', $household)
            <button class="button min-h-11" type="button" wire:click="leave" wire:confirm="{{ __('households.confirm_leave') }}">{{ __('households.leave') }}</button>
        @else
            <p class="text-sm">{{ __('households.owner_cannot_leave') }}</p>
        @endcan

        @can('delete', $household)
            <button class="button min-h-11" type="button" wire:click="deleteHousehold" wire:confirm="{{ __('households.confirm_delete') }}">{{ __('households.delete') }}</button>
        @endcan
    </section>
</div>
