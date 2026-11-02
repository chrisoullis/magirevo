<div>
    <section class="personal-notes" aria-labelledby="personal-notes-title">
        <span class="eyebrow"><span class="eyebrow-dot"></span> {{ __('site.notes_eyebrow') }}</span>
        <h2 id="personal-notes-title">{{ __('site.notes_title') }}</h2>
        <p>{{ __('site.notes_private') }}</p>

        @if (session()->has('recipe-note-status'))
            <p class="personal-notes-status" role="status">{{ session('recipe-note-status') }}</p>
        @endif

        <form class="personal-notes-form" wire:submit="save">
            <label for="recipe-note-body">{{ __('site.notes_body') }}</label>
            <textarea id="recipe-note-body" wire:model="body" rows="5" maxlength="20000" required></textarea>
            @error('body')<p class="field-error">{{ $message }}</p>@enderror

            <label for="recipe-note-last-cooked">{{ __('site.notes_last_cooked') }}</label>
            <input id="recipe-note-last-cooked" type="date" wire:model="lastCookedOn" max="{{ now()->toDateString() }}">
            @error('lastCookedOn')<p class="field-error">{{ $message }}</p>@enderror

            <div class="personal-notes-actions">
                <button class="button button-dark" type="submit" wire:loading.attr="disabled">{{ $hasNote ? __('site.notes_update') : __('site.notes_save') }}</button>
                @if ($hasNote)
                    <button class="button" type="button" wire:click="delete" wire:confirm="{{ __('site.notes_delete_confirm') }}" wire:loading.attr="disabled">{{ __('site.notes_delete') }}</button>
                @endif
            </div>
        </form>
    </section>
</div>
