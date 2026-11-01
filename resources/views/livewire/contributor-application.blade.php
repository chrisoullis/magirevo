<div class="auth-panel contributor-panel">
    <span class="eyebrow"><span class="eyebrow-dot"></span> {{ __('contributor.eyebrow') }}</span>
    <h1>{!! __('contributor.heading') !!}</h1>
    <p class="auth-intro">{{ __('contributor.intro') }}</p>

    @if (session('status'))
        <p class="status-message" role="status">{{ session('status') }}</p>
    @endif

    @if ($isContributor)
        <p role="status">{{ __('contributor.approved') }}</p>
    @elseif ($pending)
        <p role="status">{{ __('contributor.pending') }}</p>
        <p class="auth-intro">{{ __('contributor.submitted_on', ['date' => $pending->created_at->translatedFormat('j F Y')]) }}</p>
        <button class="button button-dark auth-submit" type="button" wire:click="cancel" wire:loading.attr="disabled">{{ __('contributor.cancel') }}</button>
    @elseif ($canApply)
        @if ($lastRejected)
            <p role="status">{{ __('contributor.rejected') }}</p>
            @if (filled($lastRejected->rejection_reason))
                <p class="auth-intro">{{ __('contributor.rejection_reason', ['reason' => $lastRejected->rejection_reason]) }}</p>
            @endif
            <p class="auth-intro">{{ __('contributor.reapply') }}</p>
        @endif
        <form class="auth-form" wire:submit="submit">
            <label for="contributor-message">{{ __('contributor.message_label') }}</label>
            <textarea id="contributor-message" wire:model="message" rows="5" maxlength="1000"></textarea>
            @error('message')<p class="field-error">{{ $message }}</p>@enderror
            <button class="button button-dark auth-submit" type="submit" wire:loading.attr="disabled">{{ __('contributor.submit') }} <span aria-hidden="true">&#8594;</span></button>
        </form>
    @else
        <p role="status">{{ __('contributor.not_allowed') }}</p>
    @endif
</div>
