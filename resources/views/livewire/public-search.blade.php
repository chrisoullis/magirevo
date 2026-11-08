<div class="home-search" x-data="{ open: false, active: -1,
    options() { return [...(this.$refs.results?.querySelectorAll('[role=option]') ?? [])] },
    move(direction) { const items = this.options(); if (!items.length) return; this.active = this.active < 0 ? (direction > 0 ? 0 : items.length - 1) : (this.active + direction + items.length) % items.length; items[this.active].scrollIntoView({ block: 'nearest' }) },
    choose(event) { const item = this.options()[this.active]; if (this.open && item) { event.preventDefault(); item.click() } }
}" @click.outside="open = false" @keydown.escape.prevent="open = false; active = -1">
    <form action="{{ route('home.localized', ['locale' => $locale], false) }}#recipes" method="get" role="search">
        <label class="sr-only" for="home-search-input">{{ __('homepage.search_label') }}</label>
        <x-ui.icon name="search" />
        <input id="home-search-input" type="search" name="q" maxlength="100" autocomplete="off" placeholder="{{ __('homepage.search_placeholder') }}"
            wire:model.live.debounce.250ms="search" role="combobox" aria-autocomplete="list" aria-controls="home-search-results" :aria-expanded="open && {{ count($suggestions) }} > 0"
            :aria-activedescendant="open && active >= 0 && options()[active] ? options()[active].id : null"
            @input="open = true; active = -1" @focus="open = true" @keydown.arrow-down.prevent="open = true; move(1)" @keydown.arrow-up.prevent="open = true; move(-1)" @keydown.enter="choose($event)" @keydown.tab="open = false">
        <button type="submit" class="kitchen-button kitchen-button-primary">{{ __('homepage.search_button') }}<x-ui.icon name="arrow" /></button>
    </form>
    <div @class(['home-search-results', 'has-content' => count($suggestions) > 0 || mb_strlen(trim($search)) >= 2]) x-cloak x-show="open" x-ref="results">
        <ul id="home-search-results" role="listbox" aria-label="{{ __('homepage.suggestions') }}">
            @foreach ($suggestions as $index => $suggestion)
                <li wire:key="suggestion-{{ $suggestion['kind'] }}-{{ md5($suggestion['url']) }}" role="presentation">
                    <a id="home-search-option-{{ $index }}" href="{{ $suggestion['url'] }}" role="option" :aria-selected="active === {{ $index }}" @mouseenter="active = {{ $index }}" :class="{ 'is-active': active === {{ $index }} }">
                        <span>{{ $suggestion['title'] }}</span><small>{{ __('homepage.kind_'.$suggestion['kind']) }}</small>
                    </a>
                </li>
            @endforeach
        </ul>
        @if (mb_strlen(trim($search)) >= 2 && count($suggestions) === 0)<p x-ref="empty">{{ __('homepage.no_suggestions') }}</p>@endif
    </div>
    <span class="sr-only" role="status" aria-live="polite">{{ trans_choice('homepage.suggestion_count', count($suggestions), ['count' => count($suggestions)]) }}</span>
    <span class="home-search-loading" wire:loading wire:target="search">{{ __('homepage.searching') }}</span>
    @error('q')<p class="field-error" role="alert">{{ $message }}</p>@enderror
    @error('search')<p class="field-error" role="alert">{{ $message }}</p>@enderror
</div>
