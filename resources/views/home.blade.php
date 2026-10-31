@extends('layouts.app')

@php
    $alternateUrls = collect(config('translatable.locales'))->mapWithKeys(fn ($alternate) => [$alternate => route('home.localized', ['locale' => $alternate])])->all();
    $websiteStructuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => 'The Sunday Table',
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
    @livewire('recipe-index', ['locale' => app()->getLocale()])
@endsection
