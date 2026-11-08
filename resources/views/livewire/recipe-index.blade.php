<div>
    <section class="recipe-section home-catalogue" id="recipes" aria-labelledby="recipes-title">
        <div class="home-section-heading"><div><span class="home-eyebrow">{{ __('site.nav_recipes') }}</span><h2 id="recipes-title">{{ __('homepage.catalogue_heading') }}</h2></div><p>{{ __('homepage.catalogue_intro') }}</p></div>
        <div class="recipe-tools">
            @if ($categoryCode === null)
                @php($activeRoot = collect($categories)->first(fn ($root) => $category === $root['code'] || collect($root['children'])->contains('code', $category)))
                <div class="category-filter">
                    <div class="category-list" role="group" aria-label="{{ __('site.browse_categories') }}">
                        <button class="category-button {{ $category === '' ? 'is-active' : '' }}" type="button" wire:click="$set('category', '')" aria-pressed="{{ $category === '' ? 'true' : 'false' }}">{{ __('site.all_categories') }}</button>
                        @foreach ($categories as $recipeCategory)
                            <button class="category-button {{ $activeRoot && $activeRoot['code'] === $recipeCategory['code'] ? 'is-active' : '' }}" type="button" wire:click="$set('category', @js($recipeCategory['code']))" aria-pressed="{{ $category === $recipeCategory['code'] ? 'true' : 'false' }}" wire:key="category-{{ $recipeCategory['code'] }}">{{ $recipeCategory['name'] }} <span class="category-count">({{ $recipeCategory['count'] }})</span></button>
                        @endforeach
                    </div>
                    @if ($activeRoot && count($activeRoot['children']) > 0)
                        <div class="category-list category-sublist" role="group" aria-label="{{ $activeRoot['name'] }}">
                            @foreach ($activeRoot['children'] as $child)
                                <button class="category-button {{ $category === $child['code'] ? 'is-active' : '' }}" type="button" wire:click="$set('category', @js($child['code']))" aria-pressed="{{ $category === $child['code'] ? 'true' : 'false' }}" wire:key="category-{{ $child['code'] }}">{{ $child['name'] }} <span class="category-count">({{ $child['count'] }})</span></button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
            <label class="search-field"><span class="sr-only">{{ __('site.search_label') }}</span><x-ui.icon name="search" /><input type="search" maxlength="100" placeholder="{{ __('site.search_placeholder') }}" wire:model.live.debounce.250ms="search"></label>
        </div>
        <details class="discovery-filter-panel" open>
        <summary>{{ __('discovery.refine') }}<x-ui.icon name="chevron" /></summary>
        <div class="home-catalogue-filters">
            @foreach (['cuisine' => 'cuisine', 'method' => 'cooking_method'] as $field => $groupCode)
                @if ($tags->where('group_code', $groupCode)->where('is_editorial', true)->isNotEmpty())
                    <label><span>{{ __('discovery.'.$field) }}</span><select wire:model.live="{{ $field }}"><option value="">{{ __('discovery.any_'.$field) }}</option>@foreach ($tags->where('group_code', $groupCode)->where('is_editorial', true) as $option)<option value="{{ $option['code'] }}">{{ $option['name'] }}</option>@endforeach</select></label>
                @endif
            @endforeach
            @if ($tags->where('is_editorial', false)->isNotEmpty())<label><span>{{ __('discovery.dietary') }}</span><select wire:model.live="dietary" aria-describedby="discovery-dietary-hint"><option value="">{{ __('discovery.any_dietary') }}</option>@foreach ($tags->where('is_editorial', false) as $option)<option value="{{ $option['code'] }}">{{ $option['name'] }}</option>@endforeach</select><small id="discovery-dietary-hint">{{ __('discovery.dietary_hint') }}</small></label>@endif
            <label><span>{{ __('discovery.rating') }}</span><select wire:model.live="rating"><option value="">{{ __('discovery.any_rating') }}</option>@foreach ([3, 4, 5] as $stars)<option value="{{ $stars }}">{{ __('discovery.stars', ['count' => $stars]) }}</option>@endforeach</select></label>
            @if ($tags->where('is_editorial', true)->isNotEmpty())
                <label><span>{{ __('site.filter_tag') }}</span><select wire:model.live="tag"><option value="">{{ __('site.all_tags') }}</option>
                    @foreach ($tags->where('is_editorial', true)->groupBy('group') as $group => $groupTags)<optgroup label="{{ $group }}">@foreach ($groupTags as $option)<option value="{{ $option['code'] }}">{{ $option['name'] }}</option>@endforeach</optgroup>@endforeach
                </select></label>
            @endif
            <label><span>{{ __('site.filter_time') }}</span><select wire:model.live="time"><option value="">{{ __('site.any_time') }}</option>@foreach ($timeLimits as $minutes)<option value="{{ $minutes }}">{{ __('site.under_minutes', ['minutes' => $minutes]) }}</option>@endforeach</select></label>
            <label><span>{{ __('site.filter_difficulty') }}</span><select wire:model.live="difficulty"><option value="">{{ __('site.any_difficulty') }}</option>@foreach ($difficulties as $code)<option value="{{ $code }}">{{ $difficultyLabels[$code] ?? $code }}</option>@endforeach</select></label>
            <label><span>{{ __('site.sort_label') }}</span><select wire:model.live="sort">@foreach (['featured', 'newest', 'quickest', 'rating'] as $order)<option value="{{ $order }}">{{ $order === 'rating' ? __('discovery.sort_rating') : __('site.sort_'.$order) }}</option>@endforeach</select></label>
            @if ($hasFilters)<button class="kitchen-button kitchen-button-secondary" type="button" wire:click="clearFilters">{{ __('site.clear_filters') }}</button>@endif
        </div>
        <div class="discovery-ingredient-filter">
            <label for="discovery-ingredient-search">{{ __('discovery.ingredients') }}</label>
            <p>{{ __('discovery.ingredient_match') }}</p>
            <div class="discovery-ingredient-chips">@foreach ($selectedIngredients as $selected)<span wire:key="selected-ingredient-{{ $selected->code }}"><x-ingredient-link :name="$selected->getTranslation($locale, false)->name" :url="$selected->encyclopediaUrl($locale)" /><button type="button" wire:click="removeIngredient(@js($selected->code))" aria-label="{{ __('discovery.remove_ingredient', ['name' => $selected->getTranslation($locale, false)->name]) }}">×</button></span>@endforeach</div>
            @if (count($ingredients) < 5)<input id="discovery-ingredient-search" type="search" maxlength="100" wire:model.live.debounce.250ms="ingredientSearch" placeholder="{{ __('discovery.ingredient_hint') }}" aria-controls="discovery-ingredient-options"><ul id="discovery-ingredient-options" aria-label="{{ __('discovery.ingredient_results') }}">@foreach ($ingredientResults as $option)<li wire:key="ingredient-option-{{ $option->id }}"><button type="button" wire:click="selectIngredient(@js($option->code))">{{ $option->getTranslation($locale, false)->name }}<x-ui.icon name="plus" /></button></li>@endforeach</ul>@if (mb_strlen(trim($ingredientSearch)) >= 2 && $ingredientResults->isEmpty())<p>{{ __('discovery.no_ingredients') }}</p>@endif @else<p>{{ __('discovery.ingredient_limit') }}</p>@endif
        </div>
        </details>
        @foreach (['cuisine', 'method', 'dietary', 'rating', 'ingredients', 'ingredientSearch', 'time', 'sort', 'tag', 'collection', 'category', 'difficulty'] as $field) @error($field)<p class="field-error" role="alert">{{ $message }}</p>@enderror @endforeach
        @foreach ($errors->get('ingredients.*') as $messages) @foreach ($messages as $message)<p class="field-error" role="alert">{{ $message }}</p>@endforeach @endforeach
        @if (in_array($collection, ['local', 'world', 'seasonal'], true))<p class="discovery-collection">{{ __('discovery.collection', ['name' => __('homepage.'.match ($collection) { 'local' => 'local_recipes', 'world' => 'world_cuisines', default => 'seasonal' })]) }}</p>@endif
        @error('search')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        <p class="home-result-count" role="status">{{ trans_choice('site.recipe_count', $total, ['count' => $total]) }}</p>
        <div class="recipe-grid" wire:loading.class="is-loading">
            @forelse ($recipes as $recipe)
                <x-recipe-card :recipe="$recipe" :locale="$locale" :difficulty-label="$difficultyLabels[$recipe->difficulty] ?? ''" :show-save="true" :saved="$savedIds->contains($recipe->id)" :claims="$recipeClaims[$recipe->id] ?? []" wire:key="recipe-{{ $recipe->id }}" />
            @empty
                <div class="empty-state"><x-ui.icon name="search" /><h3>{{ __('site.empty_title') }}</h3><p>{{ __('site.empty_text') }}</p><button class="kitchen-button kitchen-button-secondary" type="button" wire:click="clearFilters">{{ __('site.clear_filters') }}</button></div>
            @endforelse
        </div>
        <div class="home-load-more">{{ $recipes->links() }}</div>
    </section>
</div>
