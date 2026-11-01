<div class="flex flex-col gap-6">
    <header class="flex flex-col gap-2">
        <span class="eyebrow"><span class="eyebrow-dot"></span> {{ __('households.eyebrow') }}</span>
        <h1 class="text-3xl leading-tight">{{ __('households.heading') }}</h1>
        <p>{{ __('households.intro') }}</p>
    </header>

    @if (session('status'))
        <p class="status-message" role="status">{{ session('status') }}</p>
    @endif

    <ul class="flex flex-col gap-3" aria-label="{{ __('households.list_label') }}">
        @forelse ($memberships as $household)
            <li wire:key="household-{{ $household->id }}">
                <a class="flex min-h-11 flex-wrap items-center justify-between gap-2 rounded border border-current/20 p-4 hover:bg-black/5"
                   href="{{ route('households.show', ['locale' => $locale, 'household' => $household->id], false) }}">
                    <span class="font-semibold">{{ $household->name }}</span>
                    <span class="text-sm">
                        {{ $household->pivot->role->label() }} &middot; {{ trans_choice('households.members_count', $household->members_count) }}
                    </span>
                </a>
            </li>
        @empty
            <li>{{ __('households.none') }}</li>
        @endforelse
    </ul>

    @can('create', \App\Models\Household::class)
        <form class="flex flex-col gap-3" wire:submit="create">
            <label class="font-semibold" for="household-name">{{ __('households.name_label') }}</label>
            <input id="household-name" class="min-h-11 rounded border border-current/30 px-3" type="text" wire:model="name" maxlength="100" required>
            @error('name')<p class="field-error">{{ $message }}</p>@enderror
            <button class="button button-dark min-h-11" type="submit" wire:loading.attr="disabled">{{ __('households.create') }}</button>
        </form>
    @endcan
</div>
