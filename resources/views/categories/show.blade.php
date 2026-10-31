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
        <nav class="breadcrumbs" aria-label="{{ __('site.breadcrumb') }}"><a href="{{ route('home.localized', ['locale' => $locale], false) }}">{{ __('site.home') }}</a><span aria-hidden="true">/</span><span aria-current="page">{{ $name }}</span></nav>
        <span class="eyebrow"><span class="eyebrow-dot"></span> {{ __('site.category_eyebrow') }}</span>
        <h1>{{ __('site.category_title', ['name' => $name]) }}</h1>
        <p>{{ $description }}</p>
    </section>
    @livewire('recipe-index', ['locale' => $locale, 'categoryCode' => $category->code, 'showHero' => false])
@endsection
