@extends('layouts.app')
@section('title', __('variations.editor_title').' | My Kitchen')
@section('robots', 'noindex, nofollow, noarchive')
@section('content')
    <x-site.kitchen-shortcuts />
    @livewire('edit-recipe-variation', ['recipeId' => $recipeId, 'locale' => $locale])
@endsection
