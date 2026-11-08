@extends('layouts.app')
@section('title', __('studio.title').' | My Kitchen')
@section('robots', 'noindex, nofollow, noarchive')
@push('head')
    @filamentStyles
    @vite('resources/css/studio.css')
@endpush
@push('scripts')
    @filamentScripts
@endpush
@section('content')
    @livewire('contributor-recipe-studio', ['locale' => $locale, 'recipeId' => $recipeId])
@endsection
