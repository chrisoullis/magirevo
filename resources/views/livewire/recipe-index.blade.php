<div>
    <section class="hero-section" aria-labelledby="hero-title">
        <div class="hero-image" style="background-image: linear-gradient(90deg, rgba(17, 29, 24, .78) 0%, rgba(17, 29, 24, .44) 48%, rgba(17, 29, 24, .08) 100%), url('{{ $featured?->coverUrl() ?? asset('images/recipe-placeholder.svg') }}')"></div>
        <div class="hero-copy">
            <span class="eyebrow eyebrow-light"><span class="eyebrow-dot"></span> The everyday cookbook</span>
            <h1 id="hero-title">Good food.<br><em>Good company.</em></h1>
            <p>Seasonal, unfussy recipes for the meals that bring us back to the table.</p>
            <a class="button button-light" href="#recipes">Find something to cook <span aria-hidden="true">&#8595;</span></a>
        </div>
        <div class="hero-note"><span>ON THE TABLE</span><span>{{ $featured?->title ?? 'Something good is cooking' }}</span></div>
    </section>

    <section class="recipe-section" id="recipes" aria-labelledby="recipes-title">
        <div class="section-heading">
            <div>
                <span class="eyebrow"><span class="eyebrow-dot"></span> From our kitchen</span>
                <h2 id="recipes-title">A little inspiration<br class="mobile-break"> goes a long way.</h2>
            </div>
            <p class="section-intro">Tested, tasted and made to be cooked again. Pick a mood, then make it yours.</p>
        </div>

        <div class="recipe-tools">
            @if ($categoryCode === null)
                @php
                    $activeRoot = collect($categories)->first(fn ($root) => $category === $root['code'] || collect($root['children'])->contains('code', $category));
                @endphp
                <div class="category-filter">
                    <div class="category-list" role="group" aria-label="Filter recipes by category">
                        <button class="category-button {{ $category === '' ? 'is-active' : '' }}" type="button" wire:click="$set('category', '')" aria-pressed="{{ $category === '' ? 'true' : 'false' }}">All recipes</button>
                        @foreach ($categories as $recipeCategory)
                            <button class="category-button {{ $activeRoot && $activeRoot['code'] === $recipeCategory['code'] ? 'is-active' : '' }}" type="button" wire:click="$set('category', @js($recipeCategory['code']))" aria-pressed="{{ $category === $recipeCategory['code'] ? 'true' : 'false' }}">{{ $recipeCategory['name'] }} <span class="category-count">({{ $recipeCategory['count'] }})</span></button>
                        @endforeach
                    </div>
                    @if ($activeRoot && count($activeRoot['children']) > 0)
                        <div class="category-list category-sublist" role="group" aria-label="{{ $activeRoot['name'] }}">
                            @foreach ($activeRoot['children'] as $child)
                                <button class="category-button {{ $category === $child['code'] ? 'is-active' : '' }}" type="button" wire:click="$set('category', @js($child['code']))" aria-pressed="{{ $category === $child['code'] ? 'true' : 'false' }}">{{ $child['name'] }} <span class="category-count">({{ $child['count'] }})</span></button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
            <label class="search-field">
                <span class="sr-only">Search recipes</span>
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none"><circle cx="10.8" cy="10.8" r="6.8" stroke="currentColor" stroke-width="1.7"/><path d="m16 16 4.2 4.2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                <input type="search" placeholder="Find a recipe" wire:model.live.debounce.250ms="search">
            </label>
        </div>

        <div class="recipe-grid" wire:loading.class="is-loading" wire:target="search, category">
            @forelse ($recipes as $recipe)
                <x-recipe-card :recipe="$recipe" :locale="$locale" wire:key="recipe-{{ $recipe->id }}" />
            @empty
                <div class="empty-state">
                    <span class="empty-mark" aria-hidden="true">&#10038;</span>
                    <h3>Nothing on the menu just yet.</h3>
                    <p>Try another search or choose a different category.</p>
                    <button class="text-link" type="button" wire:click="$set('search', '')">Clear search <span aria-hidden="true">&#8594;</span></button>
                </div>
            @endforelse
        </div>
    </section>

    <section class="table-note" aria-label="A note from our kitchen">
        <div class="table-note-image" role="img" aria-label="Fresh ingredients arranged on a kitchen table"></div>
        <div class="table-note-copy">
            <span class="eyebrow eyebrow-light"><span class="eyebrow-dot"></span> Around our table</span>
            <h2>Make room<br>for one more.</h2>
            <p>Save the recipes you love and keep your next good meal close at hand.</p>
            @auth
                @if (auth()->user()->hasVerifiedEmail())
                    <a class="button button-light" href="{{ route('recipes.saved', ['locale' => $locale], false) }}">Open your recipe box <span aria-hidden="true">&#8594;</span></a>
                @else
                    <a class="button button-light" href="{{ route('verification.notice', [], false) }}">Verify your email <span aria-hidden="true">&#8594;</span></a>
                @endif
            @else
                <a class="button button-light" href="{{ route('register', [], false) }}">Save your seat <span aria-hidden="true">&#8599;</span></a>
            @endauth
        </div>
    </section>
</div>