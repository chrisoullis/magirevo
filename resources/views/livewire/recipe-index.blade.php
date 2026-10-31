<div>
    <section class="hero-section" aria-labelledby="hero-title">
        <div class="hero-image" style="background-image: linear-gradient(90deg, rgba(17, 29, 24, .78) 0%, rgba(17, 29, 24, .44) 48%, rgba(17, 29, 24, .08) 100%), url('{{ $featured?->image_url ?? 'https://images.unsplash.com/photo-1498837167922-ddd27525d352?auto=format&fit=crop&w=2000&q=85' }}')"></div>
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
            <div class="category-list" role="group" aria-label="Filter recipes by category">
                <button class="category-button {{ $category === '' ? 'is-active' : '' }}" type="button" wire:click="$set('category', '')" aria-pressed="{{ $category === '' ? 'true' : 'false' }}">All recipes</button>
                @foreach ($categories as $recipeCategory)
                    <button class="category-button {{ $category === $recipeCategory['code'] ? 'is-active' : '' }}" type="button" wire:click="$set('category', @js($recipeCategory['code']))" aria-pressed="{{ $category === $recipeCategory['code'] ? 'true' : 'false' }}">{{ $recipeCategory['name'] }}</button>
                @endforeach
            </div>
            <label class="search-field">
                <span class="sr-only">Search recipes</span>
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none"><circle cx="10.8" cy="10.8" r="6.8" stroke="currentColor" stroke-width="1.7"/><path d="m16 16 4.2 4.2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                <input type="search" placeholder="Find a recipe" wire:model.live.debounce.250ms="search">
            </label>
        </div>

        <div class="recipe-grid" wire:loading.class="is-loading" wire:target="search, category">
            @forelse ($recipes as $recipe)
                <article class="recipe-card" wire:key="recipe-{{ $recipe->id }}">
                    <a class="recipe-card-image" href="{{ route('recipes.show', ['locale' => $locale, 'slug' => $recipe->slug], false) }}" aria-label="View {{ $recipe->title }} recipe">
                        <img src="{{ $recipe->image_url }}" alt="{{ $recipe->title }}" loading="lazy" width="720" height="540">
                        <span class="image-category">{{ $recipe->category }}</span>
                    </a>
                    <div class="recipe-card-copy">
                        <div class="recipe-meta"><span>{{ $recipe->prep_minutes + $recipe->cook_minutes }} min</span><span class="meta-separator"></span><span>{{ $recipe->difficulty }}</span></div>
                        <h3><a href="{{ route('recipes.show', ['locale' => $locale, 'slug' => $recipe->slug], false) }}">{{ $recipe->title }}</a></h3>
                        <p>{{ $recipe->summary }}</p>
                        <a class="text-link" href="{{ route('recipes.show', ['locale' => $locale, 'slug' => $recipe->slug], false) }}">Get the recipe <span aria-hidden="true">&#8594;</span></a>
                    </div>
                </article>
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