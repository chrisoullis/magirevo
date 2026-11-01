@extends('layouts.app')

@php
    $name = $translation->name;
    $description = $translation->description ?: __('site.category_description', ['name' => $name]);
    $url = $alternateUrls[$locale];
    $categoryStructuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'CollectionPage',
        'name' => __('site.category_title', ['name' => $name]),
        'description' => $description,
        'url' => $url,
        'inLanguage' => $locale,
    ];
@endphp

@section('title', __('site.category_title', ['name' => $name]).' | The Sunday Table')
@section('meta_description', $description)
@section('canonical', $url)

@push('head')
    <script type="application/ld+json">@json($categoryStructuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
@endpush

@section('content')
    <section class="category-hero">
        <nav class="breadcrumbs" aria-label="{{ __('site.breadcrumb') }}"><a href="{{ route('home.localized', ['locale' => $locale], false) }}">{{ __('site.home') }}</a><span aria-hidden="true">/</span>@if ($category->parent)<a href="{{ route('categories.show', ['locale' => $locale, 'slug' => $category->parent->translate($locale)?->slug], false) }}">{{ $category->parent->translate($locale)?->name }}</a><span aria-hidden="true">/</span>@endif<span aria-current="page">{{ $name }}</span></nav>
        <span class="eyebrow"><span class="eyebrow-dot"></span> {{ __('site.category_eyebrow') }}</span>
        <h1>{{ __('site.category_title', ['name' => $name]) }}</h1>
        <p>{{ $description }}</p>
        @if ($category->media)
            <figure class="category-banner">
                <img src="{{ $category->imageUrl() }}" alt="{{ $category->imageAlt($locale) }}" width="1320" height="440" sizes="(min-width: 1392px) 1320px, 100vw" decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/category-placeholder.svg') }}'">
                @if ($category->media->translate($locale)?->caption)<figcaption>{{ $category->media->translate($locale)->caption }}</figcaption>@endif
            </figure>
        @endif
    </section>
    @if ($subcategories->isNotEmpty())
        <nav class="subcategory-cards" aria-label="{{ __('site.browse_categories') }}">
            @foreach ($subcategories as $subcategory)
                <a class="subcategory-card" href="{{ route('categories.show', ['locale' => $locale, 'slug' => $subcategory->translate($locale)->slug], false) }}">
                    <img src="{{ $subcategory->imageUrl() }}" alt="{{ $subcategory->imageAlt($locale) }}" loading="lazy" decoding="async" width="720" height="540" sizes="(min-width: 900px) 25vw, (min-width: 560px) 50vw, 100vw" onerror="this.onerror=null;this.src='{{ asset('images/category-placeholder.svg') }}'">
                    <span>{{ $subcategory->translate($locale)->name }} <small>({{ $subcategory->published_direct_recipes_count }})</small></span>
                </a>
            @endforeach
        </nav>
    @endif
    @livewire('recipe-index', ['locale' => $locale, 'categoryCode' => $category->code, 'showHero' => false])
@endsection
