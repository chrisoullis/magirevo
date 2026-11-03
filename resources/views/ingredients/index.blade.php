@extends('layouts.app')

@section('title', __('ingredients.title').' | The Sunday Table')
@section('meta_description', __('ingredients.intro'))
@section('canonical', route('ingredients.index', ['locale' => $locale]))

@section('content')
    <section class="ingredient-page" aria-labelledby="ingredient-index-title">
        <header class="ingredient-heading">
            <span class="eyebrow"><span class="eyebrow-dot"></span> The Sunday Table</span>
            <h1 id="ingredient-index-title">{{ __('ingredients.title') }}</h1>
            <p>{{ __('ingredients.intro') }}</p>
        </header>
        @livewire('ingredient-index', ['locale' => $locale])
    </section>
@endsection
