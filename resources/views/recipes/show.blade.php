@extends('layouts.app')

@section('title', ($recipe->seo_title ?: $recipe->title).' | The Sunday Table')
@section('meta_description', $recipe->seo_description ?: $recipe->summary)
@section('og_title', ($recipe->seo_title ?: $recipe->title).' | The Sunday Table')
@section('og_type', 'article')
@section('og_image', $recipe->image_url)
@section('canonical', route('recipes.show', ['locale' => $locale, 'slug' => $recipe->slug]))

@push('head')
    <script type="application/ld+json">@json($structuredData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
@endpush

@section('content')
    <article class="recipe-detail">
        <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="{{ route('home.localized', ['locale' => $locale], false) }}">Home</a><span aria-hidden="true">/</span><a href="{{ route('home.localized', ['locale' => $locale], false) }}#recipes">Recipes</a><span aria-hidden="true">/</span><span aria-current="page">{{ $recipe->title }}</span></nav>
        <header class="recipe-detail-header">
            <div class="recipe-detail-heading">
                <span class="eyebrow"><span class="eyebrow-dot"></span> {{ $recipe->category }}</span>
                <h1>{{ $recipe->title }}</h1>
                <p>{{ $recipe->summary }}</p>
                <div class="detail-stats"><span>{{ $recipe->prep_minutes + $recipe->cook_minutes }} minutes</span><span>{{ $recipe->difficulty }}</span><span>Serves {{ $recipe->servings }}</span></div>
                @auth
                    @if (auth()->user()->hasVerifiedEmail())
                        <form class="save-recipe-form" method="post" action="{{ route('recipes.save', ['locale' => $locale, 'recipe' => $recipe], false) }}">
                            @csrf
                            <button class="button button-dark" type="submit"><span aria-hidden="true">&#9825;</span> {{ auth()->user()->savedRecipes()->whereKey($recipe->id)->exists() ? 'Remove from recipe box' : 'Save this recipe' }}</button>
                        </form>
                    @else
                        <a class="button button-dark" href="{{ route('verification.notice', [], false) }}">Verify email to save</a>
                    @endif
                @else
                    <a class="button button-dark" href="{{ route('register', [], false) }}">Join to save this recipe <span aria-hidden="true">&#8599;</span></a>
                @endauth
            </div>
            <figure class="recipe-detail-image"><img src="{{ $recipe->image_url }}" alt="{{ $recipe->title }}" width="1200" height="900" fetchpriority="high"><figcaption>From The Sunday Table kitchen</figcaption></figure>
        </header>
        <div class="recipe-content">
            <aside class="ingredients-panel">
                <span class="eyebrow"><span class="eyebrow-dot"></span> What you'll need</span>
                <h2>Ingredients</h2>
                <p class="servings-note">For {{ $recipe->servings }} servings</p>
                <ul class="ingredient-list">
                    @foreach ($recipe->ingredients as $ingredient)
                        <li>{{ $ingredient }}</li>
                    @endforeach
                </ul>
            </aside>
            <section class="method-panel" aria-labelledby="method-title">
                <span class="eyebrow"><span class="eyebrow-dot"></span> Take it step by step</span>
                <h2 id="method-title">Let's make it.</h2>
                <div class="recipe-description">{{ \Filament\Forms\Components\RichEditor\RichContentRenderer::make($recipe->introduction) }}</div>
                <ol class="method-list">
                    @foreach ($recipe->instructions as $step)
                        <li><span class="step-number">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><p>{{ $step }}</p></li>
                    @endforeach
                </ol>
            </section>
        </div>
    </article>
@endsection