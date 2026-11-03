@extends('layouts.app')
@section('title', __('variations.collection').' | The Sunday Table')
@section('robots', 'noindex, nofollow, noarchive')
@section('content')
    @livewire('personal-recipes', ['locale' => $locale])
@endsection
