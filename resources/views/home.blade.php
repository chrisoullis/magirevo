@extends('layouts.app')

@php
    $alternateUrls = collect(config('translatable.locales'))->mapWithKeys(fn ($alternate) => [$alternate => route('home.localized', ['locale' => $alternate])])->all();
    $websiteStructuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => 'My Kitchen',
        'url' => route('home.localized', ['locale' => app()->getLocale()]),
        'inLanguage' => app()->getLocale(),
    ];
@endphp

@section('title', __('site.home_title'))
@section('meta_description', __('site.home_description'))
@section('canonical', route('home.localized', ['locale' => app()->getLocale()]))

@push('head')
    <script type="application/ld+json">@json($websiteStructuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
@endpush

@section('content')
    <section class="home-hero" aria-labelledby="home-hero-title">
        <img class="home-hero-photo" src="{{ $hero?->coverUrl() ?? asset('images/recipe-placeholder.svg') }}" alt="" onerror="this.onerror=null;this.src='{{ asset('images/recipe-placeholder.svg') }}'" fetchpriority="high" loading="eager" width="1600" height="900">
        <div class="home-hero-shade"></div>
        <div class="home-container home-hero-copy">
            <span class="home-eyebrow">{{ __('homepage.hero_eyebrow') }}</span>
            <h1 id="home-hero-title">{{ __('homepage.hero_title') }}</h1>
            <p>{{ __('homepage.hero_text') }}</p>
            <livewire:public-search :locale="app()->getLocale()" />
            <span class="home-hero-hint"><x-ui.icon name="leaf" />{{ __('homepage.hero_hint') }}</span>
        </div>
        @if ($hero)<a class="home-hero-credit" href="{{ route('recipes.show', ['locale' => app()->getLocale(), 'slug' => $hero->translate()->slug], false) }}">{{ __('site.on_the_table') }}<span>{{ $hero->translate()->title }} ↗</span></a>@endif
    </section>

    <nav class="home-categories home-container" aria-label="{{ __('homepage.quick_categories') }}">
        @foreach ($categories as $item)
            <a href="{{ $item['url'] }}"><span class="home-category-circle">@if ($item['image'])<img src="{{ $item['image'] }}" alt="" width="112" height="112" loading="lazy">@else<x-ui.icon :name="$item['icon']" />@endif</span><span>{{ $item['label'] }}</span></a>
        @endforeach
    </nav>

    <section class="home-featured home-container" aria-labelledby="home-featured-title">
        <div class="home-section-heading"><div><span class="home-eyebrow">{{ __('homepage.from_our_kitchen') }}</span><h2 id="home-featured-title">{{ __('homepage.featured') }}</h2></div><a class="home-section-link" href="#recipes">{{ __('homepage.browse_all') }}<x-ui.icon name="arrow" /></a></div>
        <div class="recipe-grid home-recipe-grid">
            @forelse ($featured as $recipe)
                <x-recipe-card :recipe="$recipe" :locale="app()->getLocale()" :difficulty-label="$difficultyLabels[$recipe->difficulty] ?? ''" :show-save="true" :saved="$savedIds->contains($recipe->id)" />
            @empty
                <div class="home-empty"><x-ui.icon name="book" /><p>{{ __('homepage.no_recipes') }}</p><a href="{{ route('ingredients.index', ['locale' => app()->getLocale()], false) }}">{{ __('homepage.browse_ingredients') }}</a></div>
            @endforelse
        </div>
    </section>

    @if (auth()->user()?->hasVerifiedEmail())
        <livewire:kitchen-preview :locale="app()->getLocale()" lazy />
    @else
        <section class="home-guest-kitchen home-container" aria-labelledby="guest-kitchen-title">
            <div><span class="home-eyebrow">{{ __('navigation.my_kitchen') }}</span><h2 id="guest-kitchen-title">{{ __('homepage.guest_heading') }}</h2><p>{{ __('homepage.guest_text') }}</p></div>
            <x-ui.button :href="auth()->check() ? route('verification.notice', [], false) : route('register', [], false)">{{ auth()->check() ? __('site.verify_email') : __('homepage.start_kitchen') }}<x-ui.icon name="arrow" /></x-ui.button>
        </section>
    @endif

    @php
        $links = app(\App\Support\PublicNavigation::class)->links(auth()->user(), app()->getLocale());
        $contributorUrl = $links['createUrl'] ?? (auth()->check() ? (auth()->user()->hasVerifiedEmail() ? route('contributor.show', ['locale' => app()->getLocale()], false) : route('verification.notice', [], false)) : route('register', [], false));
    @endphp
    <section class="home-actions home-container" aria-label="{{ __('homepage.make_cooking_easier') }}">
        @foreach (array_filter([
            ['sparkles', 'cook_title', 'cook_text', route('recipes.what-can-i-cook', ['locale' => app()->getLocale()], false), 'cook_action'],
            ($links['plannerAllowed'] || (auth()->check() && ! auth()->user()->hasVerifiedEmail())) ? ['calendar', 'plan_title', 'plan_text', auth()->check() && ! auth()->user()->hasVerifiedEmail() ? route('verification.notice', [], false) : route('planner.index', ['locale' => app()->getLocale()], false), 'plan_action'] : null,
            ['book', 'create_title', 'create_text', $contributorUrl, $links['createUrl'] ? 'create_action' : 'contributor_action'],
        ]) as [$icon, $title, $text, $url, $action])
            <article class="home-action-card"><span class="home-action-icon"><x-ui.icon :name="$icon" /></span><h2>{{ __('homepage.'.$title) }}</h2><p>{{ __('homepage.'.$text) }}</p><a href="{{ $url }}">{{ __('homepage.'.$action) }}<x-ui.icon name="arrow" /></a></article>
        @endforeach
    </section>

    @if ($recent->isNotEmpty() || $quick->isNotEmpty())
        @php($discovery = $recent->isNotEmpty() ? $recent : $quick)
        <section class="home-discovery home-container" aria-labelledby="home-discovery-title">
            <div class="home-section-heading"><div><span class="home-eyebrow">{{ __('homepage.fresh_ideas') }}</span><h2 id="home-discovery-title">{{ __($recent->isNotEmpty() ? 'homepage.recent' : 'homepage.quick_meals') }}</h2></div><a class="home-section-link" href="{{ route('home.localized', ['locale' => app()->getLocale(), 'sort' => $recent->isNotEmpty() ? 'newest' : 'quickest'], false) }}#recipes">{{ __('homepage.explore') }}<x-ui.icon name="arrow" /></a></div>
            <div class="recipe-grid home-recipe-grid">@foreach ($discovery as $recipe)<x-recipe-card :recipe="$recipe" :locale="app()->getLocale()" :difficulty-label="$difficultyLabels[$recipe->difficulty] ?? ''" :show-save="true" :saved="$savedIds->contains($recipe->id)" />@endforeach</div>
        </section>
    @endif

    <section class="home-ingredients home-container" aria-labelledby="home-ingredients-title">
        <div class="home-section-heading"><div><span class="home-eyebrow">{{ __('homepage.know_your_ingredients') }}</span><h2 id="home-ingredients-title">{{ __('homepage.browse_ingredients') }}</h2></div><a class="home-section-link" href="{{ route('ingredients.index', ['locale' => app()->getLocale()], false) }}">{{ __('homepage.encyclopedia') }}<x-ui.icon name="arrow" /></a></div>
        <div class="home-ingredient-grid">
            @forelse ($ingredients as $ingredient)
                <a href="{{ $ingredient->encyclopediaUrl(app()->getLocale()) }}"><img src="{{ $ingredient->encyclopediaImageUrl(true) }}" alt="{{ $ingredient->encyclopediaImageAlt(app()->getLocale()) }}" loading="lazy" decoding="async" width="200" height="160"><strong>{{ $ingredient->getTranslation(app()->getLocale(), false)->name }}</strong><span>{{ $ingredient->catalogGroup?->translate()?->name }}</span></a>
            @empty
                <p>{{ __('homepage.ingredient_empty') }}</p>
            @endforelse
        </div>
    </section>

    <livewire:recipe-index :locale="app()->getLocale()" :show-hero="false" />
@endsection
