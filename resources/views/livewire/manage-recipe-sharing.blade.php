<div class="auth-panel contributor-panel">
    <span class="eyebrow"><span class="eyebrow-dot"></span> {{ __('sharing.eyebrow') }}</span>
    <h1>{!! __('sharing.heading') !!}</h1>
    <p class="auth-intro">{{ __('sharing.intro') }}</p>
    <p class="auth-intro"><strong>{{ __('sharing.recipe', ['title' => $title]) }}</strong></p>

    @if (session('status'))
        <p class="status-message" role="status">{{ session('status') }}</p>
    @endif

    @if (! $isShared)
        <p role="status">{{ __('sharing.not_shared') }}</p>
        <button class="button button-dark auth-submit" type="button" wire:click="enableSharing" wire:loading.attr="disabled">{{ __('sharing.enable') }}</button>
    @else
        <form class="auth-form" wire:submit="share">
            <label for="share-email">{{ __('sharing.email_label') }}</label>
            <input id="share-email" type="email" wire:model="email" maxlength="254" autocomplete="off" required>
            @error('email')<p class="field-error">{{ $message }}</p>@enderror
            <button class="button button-dark auth-submit" type="submit" wire:loading.attr="disabled">{{ __('sharing.invite') }} <span aria-hidden="true">&#8594;</span></button>
        </form>

        <h2>{{ __('sharing.people') }}</h2>
        @forelse ($shares as $share)
            <p class="auth-intro" wire:key="share-{{ $share->id }}">
                {{ $share->user->email }} &middot; {{ __('sharing.since', ['date' => $share->created_at->translatedFormat('j F Y')]) }}
                <button class="text-link" type="button" wire:click="revoke({{ $share->id }})" wire:loading.attr="disabled">{{ __('sharing.revoke') }}</button>
            </p>
        @empty
            <p class="auth-intro">{{ __('sharing.nobody') }}</p>
        @endforelse

        <button class="button auth-submit" type="button" wire:click="stopSharing" wire:loading.attr="disabled">{{ __('sharing.stop') }}</button>
    @endif

    @if ($recipeUrl)
        <p class="auth-intro"><a class="text-link" href="{{ $recipeUrl }}">{{ __('sharing.back') }}</a></p>
    @endif
</div>
