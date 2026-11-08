@extends('layouts.app')

@section('title', __('modules.saved_title').' | My Kitchen')
@section('robots', 'noindex, nofollow')
@section('meta_description', __('modules.saved_intro'))

@section('content')
    <x-site.kitchen-shortcuts />
    <section class="saved-page">
        <div class="saved-heading">
            <span class="eyebrow"><span class="eyebrow-dot"></span> {{ __('modules.saved_eyebrow') }}</span>
            <h1>{{ __('modules.saved_title') }}</h1>
            <p>{{ __('modules.saved_intro') }}</p>
        </div>
        @if ($recipes->isEmpty())
            <div class="empty-state saved-empty">
                <span class="empty-mark" aria-hidden="true">&#10038;</span>
                <h2>{{ __('modules.saved_empty_title') }}</h2>
                <p>{{ __('modules.saved_empty') }}</p>
                <a class="button button-dark" href="{{ route('home.localized', ['locale' => app()->getLocale()], false) }}#recipes">{{ __('kitchen.browse') }} <span aria-hidden="true">&#8594;</span></a>
            </div>
        @else
            <div class="recipe-grid saved-grid">
                @foreach ($recipes as $recipe)
                    <x-recipe-card :recipe="$recipe" :locale="app()->getLocale()" heading="h2" />
                @endforeach
            </div>
        @endif
    </section>
@endsection