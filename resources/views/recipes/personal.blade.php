@extends('layouts.app')
@section('title', __('variations.collection').' | My Kitchen')
@section('robots', 'noindex, nofollow, noarchive')
@section('content')
    <x-site.kitchen-shortcuts />
    @livewire('personal-recipes', ['locale' => $locale])
@endsection
