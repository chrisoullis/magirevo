@extends('layouts.app')

@section('title', 'My recipe box | The Sunday Table')
@section('meta_description', 'Your saved recipes from The Sunday Table.')

@section('content')
    <section class="saved-page">
        <div class="saved-heading">
            <span class="eyebrow"><span class="eyebrow-dot"></span> Kept close</span>
            <h1>Your recipe box.</h1>
            <p>A little collection of things you want to cook.</p>
        </div>
        @if ($recipes->isEmpty())
            <div class="empty-state saved-empty">
                <span class="empty-mark" aria-hidden="true">&#10038;</span>
                <h2>A fresh page.</h2>
                <p>Save recipes as you browse and they'll be waiting here.</p>
                <a class="button button-dark" href="{{ route('home.localized', ['locale' => app()->getLocale()], false) }}#recipes">Browse recipes <span aria-hidden="true">&#8594;</span></a>
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