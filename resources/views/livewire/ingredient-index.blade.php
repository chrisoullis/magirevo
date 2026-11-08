<div class="encyclopedia-catalogue">
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
                @foreach ($groups as $option)<option value="{{ $option->code }}" @selected($group === $option->code) wire:key="ingredient-group-{{ $option->id }}">{{ $option->getTranslation($locale, false)->name }}</option>@endforeach
            </select>
        </div>
        <div><label for="ingredient-sort">{{ __('site.sort_label') }}</label><select id="ingredient-sort" name="sort" wire:model.live="sort"><option value="az" @selected($sort === 'az')>{{ __('ingredients.sort_az') }}</option><option value="za" @selected($sort === 'za')>{{ __('ingredients.sort_za') }}</option></select></div>
        <input type="hidden" name="letter" value="{{ $letter }}">
        <button class="kitchen-button kitchen-button-primary" type="submit" wire:loading.attr="disabled">{{ __('ingredients.submit') }}</button>
    </form>
    <nav class="ingredient-alphabet" aria-label="{{ __('ingredients.alphabet') }}">
        @foreach (['' => __('ingredients.all_letters'), ...array_combine($alphabet, $alphabet)] as $value => $label)
            <a href="{{ route('ingredients.index', ['locale' => $locale, 'search' => $search, 'group' => $group, 'sort' => $sort, 'letter' => $value], false) }}" wire:click.prevent="$set('letter', @js($value))" @if($letter === $value) aria-current="true" @endif wire:key="letter-{{ $value ?: 'all' }}">{{ $label }}</a>
        @endforeach
    </nav>
    <div class="ingredient-results-heading"><p class="ingredient-count" role="status" aria-live="polite">{{ trans_choice('ingredients.results', $ingredients->total(), ['count' => $ingredients->total()]) }}</p>@if ($search !== '' || $group !== '' || $letter !== '' || $sort !== 'az')<a href="{{ route('ingredients.index', ['locale' => $locale], false) }}" wire:click.prevent="clearFilters">{{ __('site.clear_filters') }}</a>@endif</div>
    @foreach (['group', 'letter', 'sort'] as $field) @error($field)<p class="field-error" role="alert">{{ $message }}</p>@enderror @endforeach
    <div class="ingredient-grid" wire:loading.class="is-loading" wire:loading.attr="aria-busy">
        @forelse ($ingredients as $ingredient)
            <article wire:key="ingredient-{{ $ingredient->id }}">
                <a class="ingredient-card" href="{{ $ingredient->encyclopediaUrl($locale) }}">
                    <x-ingredient-image :ingredient="$ingredient" />
                    <div><span class="eyebrow">{{ $ingredient->catalogGroup->getTranslation($locale, false)->name }}</span><h2>{{ $ingredient->getTranslation($locale, false)->name }}</h2>
                        @php($otherLocale = $locale === 'en' ? 'el' : 'en')
                        @if ($ingredient->hasPublicTranslation($otherLocale))<p class="ingredient-other-name" lang="{{ $otherLocale }}">{{ $ingredient->getTranslation($otherLocale, false)->name }}</p>@endif
                        @if (filled($ingredient->getTranslation($locale, false)->description))<p class="ingredient-card-description">{{ \Illuminate\Support\Str::limit(strip_tags($ingredient->getTranslation($locale, false)->description), 130) }}</p>@endif
                        <span class="ingredient-card-action">{{ __('ingredients.learn_more') }}<x-ui.icon name="arrow" /></span>
                    </div>
                </a>
            </article>
        @empty
            <div class="ingredient-empty"><x-ui.icon name="leaf" /><h2>{{ __('ingredients.no_matches') }}</h2><p>{{ __('ingredients.empty') }}</p><a href="{{ route('ingredients.index', ['locale' => $locale], false) }}" wire:click.prevent="clearFilters">{{ __('site.clear_filters') }}</a></div>
        @endforelse
    </div>
    {{ $ingredients->links('components.ingredient-pagination', ['livewire' => true]) }}
</div>
