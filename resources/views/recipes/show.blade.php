@extends('layouts.app')

@section('title', ($recipe->seo_title ?: $recipe->title).' | The Sunday Table')
@section('meta_description', $recipe->seo_description ?: $recipe->summary)
@section('og_title', ($recipe->seo_title ?: $recipe->title).' | The Sunday Table')
@section('og_type', 'article')
@section('og_image', $recipe->coverUrl())
@section('canonical', $alternateUrls[$locale])

@push('head')
    <script type="application/ld+json">@json($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
@endpush

@php
    $cover = $recipe->coverMedia();
    $placeholder = asset('images/recipe-placeholder.svg');
@endphp

@section('content')
    <article class="recipe-detail">
        <nav class="breadcrumbs" aria-label="{{ __('site.breadcrumb') }}"><a href="{{ route('home.localized', ['locale' => $locale], false) }}">{{ __('site.home') }}</a><span aria-hidden="true">/</span><span aria-current="page">{{ $recipe->title }}</span></nav>
        <header class="recipe-detail-header">
            <div class="recipe-detail-heading">
                @if ($category)<span class="eyebrow"><span class="eyebrow-dot"></span> {{ $category->translate($locale)?->name }}</span>@endif
                <h1>{{ $recipe->title }}</h1>
                <p>{{ $recipe->summary }}</p>
                <div class="detail-stats"><span>{{ __('site.minutes_total', ['minutes' => $recipe->totalMinutes()]) }}</span>@if ($recipe->difficultyLabel())<span>{{ $recipe->difficultyLabel() }}</span>@endif<span>{{ __('site.serves', ['count' => $baseServings]) }}</span></div>
                @auth
                    @if (auth()->user()->hasVerifiedEmail())
                        <form class="save-recipe-form" method="post" action="{{ route('recipes.save', ['locale' => $locale, 'recipe' => $recipe], false) }}">
                            @csrf
                            <button class="button button-dark" type="submit"><span aria-hidden="true">&#9825;</span> {{ auth()->user()->favoriteRecipes()->whereKey($recipe->id)->exists() ? __('site.remove_saved') : __('site.save_recipe') }}</button>
                        </form>
                    @else
                        <a class="button button-dark" href="{{ route('verification.notice', [], false) }}">{{ __('site.verify_to_save') }}</a>
                    @endif
                @else
                    <a class="button button-dark" href="{{ route('register', [], false) }}">{{ __('site.join_to_save') }} <span aria-hidden="true">&#8599;</span></a>
                @endauth
            </div>
            <figure class="recipe-detail-image">
                <img src="{{ $recipe->coverUrl() }}" alt="{{ $recipe->coverAlt() }}" width="1200" height="900" fetchpriority="high" onerror="this.onerror=null;this.src='{{ $placeholder }}'">
                <figcaption>
                    @if ($cover?->credit)
                        @if ($cover->credit_url)<a href="{{ $cover->credit_url }}" rel="noopener nofollow" target="_blank">{{ $cover->credit }}</a>@else{{ $cover->credit }}@endif
                    @else
                        {{ __('site.photo_caption') }}
                    @endif
                </figcaption>
            </figure>
        </header>
        <div class="recipe-content">
            @livewire('recipe-servings', ['recipeId' => $recipe->id, 'locale' => $locale, 'baseServings' => $baseServings])
            <section class="method-panel" aria-labelledby="method-title">
                <span class="eyebrow"><span class="eyebrow-dot"></span> {{ __('site.step_eyebrow') }}</span>
                <h2 id="method-title">{{ __('site.method_title') }}</h2>
                @if ($recipe->introduction)
                    <div class="recipe-description">{{ \Filament\Forms\Components\RichEditor\RichContentRenderer::make($recipe->introduction) }}</div>
                @endif
                <ol class="method-list">
                    @foreach ($steps as $step)
                        <li><span class="step-number">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><p>{{ $step['text'] }}</p></li>
                    @endforeach
                </ol>
            </section>
        </div>
        @if ($gallery->isNotEmpty())
            <section class="recipe-gallery" aria-label="{{ __('site.gallery') }}">
                @foreach ($gallery as $image)
                    <img src="{{ $image->publicUrl() }}" alt="{{ $image->translate($locale)?->alt_text }}" loading="lazy" onerror="this.onerror=null;this.src='{{ $placeholder }}'">
                @endforeach
            </section>
        @endif
        @if ($related->isNotEmpty())
            <section class="related-recipes">
                <h2>{{ __('site.related') }}</h2>
                <div class="recipe-grid">
                    @foreach ($related as $item)
                        <x-recipe-card :recipe="$item" :locale="$locale" />
                    @endforeach
                </div>
            </section>
        @endif
    </article>
@endsection
