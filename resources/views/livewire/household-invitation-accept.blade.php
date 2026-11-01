<div class="flex flex-col gap-4">
    <h1 class="text-3xl leading-tight">{{ __('households.invitation.title') }}</h1>

    @if ($invitation)
        <p>{{ __('households.invitation.body', ['household' => $invitation->household->name, 'role' => $invitation->role->label()]) }}</p>
        <button class="button button-dark min-h-11" type="button" wire:click="accept" wire:loading.attr="disabled">{{ __('households.invitation.accept') }}</button>
    @else
        <p role="alert">{{ __('households.errors.invalid_invitation') }}</p>
    @endif

    @error('token')<p class="field-error" role="alert">{{ $message }}</p>@enderror
    <a class="text-link inline-flex min-h-11 items-center" href="{{ route('households.index', ['locale' => $locale], false) }}">{{ __('households.back') }}</a>
</div>
