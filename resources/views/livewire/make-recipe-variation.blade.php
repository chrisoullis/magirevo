<div>
    @if ($available)
        <details class="variation-confirmation">
            <summary class="button button-dark">{{ __('variations.make_own') }}</summary>
            <p>{{ __('variations.explanation') }}</p>
            <form wire:submit="create">
                <label class="variation-check"><input type="checkbox" wire:model="confirmed" required> {{ __('variations.confirm') }}</label>
                @foreach ($errors->all() as $error)<p class="field-error" role="alert">{{ $error }}</p>@endforeach
                <button class="button button-dark" type="submit" wire:loading.attr="disabled">{{ __('variations.create') }}</button>
            </form>
        </details>
    @endif
</div>
