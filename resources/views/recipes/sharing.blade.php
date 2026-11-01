@extends('layouts.app')

@section('title', __('sharing.title').' | The Sunday Table')
@section('robots', 'noindex, nofollow')

@section('content')
    <section class="auth-page">
        <div class="auth-photo auth-photo-register" role="img" aria-label=""></div>
        <livewire:manage-recipe-sharing :locale="$locale" :recipe-id="(int) $recipe" />
    </section>
@endsection
