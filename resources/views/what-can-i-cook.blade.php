@extends('layouts.app')

@php
    $locale = app()->getLocale();
    $alternateUrls = collect(config('translatable.locales'))
        ->mapWithKeys(fn (string $alternateLocale): array => [
            $alternateLocale => route('recipes.what-can-i-cook', ['locale' => $alternateLocale]),
        ])
        ->all();
@endphp

@section('title', __('site.cooking_page_title'))
@section('meta_description', __('site.cooking_page_description'))
@section('canonical', route('recipes.what-can-i-cook', ['locale' => $locale]))

@section('content')
    <section class="what-cook-page">
        <header class="what-cook-page-heading">
            <span class="eyebrow"><span class="eyebrow-dot"></span> {{ __('site.cooking_page_eyebrow') }}</span>
            <h1>{{ __('site.what_can_i_cook') }}</h1>
            <p>{{ __('site.cooking_intro') }}</p>
        </header>

        @livewire('what-can-i-cook', ['locale' => $locale])
    </section>
@endsection
