<aside class="variation-row" aria-live="polite">
    @if ($recipe->status === 'pending')
        <p>{{ __('variations.pending_review') }}</p>
        @can('withdraw', $recipe)<button type="button" class="button" wire:click="withdraw" wire:confirm="{{ __('variations.withdraw_confirm') }}" wire:loading.attr="disabled">{{ __('variations.withdraw') }}</button>@endcan
    @elseif ($recipe->rejection_reason)
        <p>{{ __('variations.review_feedback') }} {{ $recipe->rejection_reason }}</p>
    @endif
</aside>
