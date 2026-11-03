<div>
    <form class="ingredient-filters" method="get" action="{{ route('ingredients.index', ['locale' => $locale], false) }}" wire:submit="searchIngredients">
        <div>
            <label for="ingredient-search">{{ __('ingredients.search') }}</label>
            <input id="ingredient-search" type="search" name="search" maxlength="100" placeholder="{{ __('ingredients.search_hint') }}" wire:model.live.debounce.300ms="search" value="{{ $search }}" aria-describedby="ingredient-search-error">
            <span id="ingredient-search-error">@error('search') {{ $message }} @enderror</span>
        </div>
        <div>
            <label for="ingredient-group">{{ __('ingredients.group') }}</label>
            <select id="ingredient-group" name="group" wire:model.live="group">
                <option value="">{{ __('ingredients.all_groups') }}</option>
                @foreach ($groups as $option)
                    <option value="{{ $option->code }}" @selected($group === $option->code) wire:key="ingredient-group-{{ $option->id }}">{{ $option->getTranslation($locale, false)->name }}</option>
                @endforeach
            </select>
        </div>
        <button class="button button-dark" type="submit" wire:loading.attr="disabled">{{ __('ingredients.submit') }}</button>
    </form>
    <p class="ingredient-count" role="status" aria-live="polite">{{ trans_choice('ingredients.results', $ingredients->total(), ['count' => $ingredients->total()]) }}</p>
    <div class="ingredient-grid" wire:loading.attr="aria-busy" wire:target="search,group,nextPage,previousPage">
        @forelse ($ingredients as $ingredient)
            <article wire:key="ingredient-{{ $ingredient->id }}">
                <a class="ingredient-card" href="{{ route('ingredients.show', ['locale' => $locale, 'slug' => $ingredient->getTranslation($locale, false)->slug], false) }}">
                    <img src="{{ $ingredient->encyclopediaImageUrl(true) }}" alt="{{ $ingredient->encyclopediaImageAlt($locale) }}" loading="lazy" decoding="async" width="480" height="320" onerror="this.onerror=null;this.src='{{ asset('images/ingredient-placeholder.svg') }}'">
                    <div><span class="eyebrow">{{ $ingredient->catalogGroup->getTranslation($locale, false)->name }}</span><h2>{{ $ingredient->getTranslation($locale, false)->name }}</h2></div>
                </a>
            </article>
        @empty
            <p class="ingredient-empty">{{ __('ingredients.empty') }}</p>
        @endforelse
    </div>
    {{ $ingredients->links('components.ingredient-pagination', ['livewire' => true]) }}
</div>
