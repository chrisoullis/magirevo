@extends('layouts.app')
@section('title', __('variations.editor_title').' | The Sunday Table')
@section('robots', 'noindex, nofollow, noarchive')
@section('content')
    @livewire('edit-recipe-variation', ['recipeId' => $recipeId, 'locale' => $locale])
@endsection
