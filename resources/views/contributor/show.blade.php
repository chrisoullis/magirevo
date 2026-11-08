@extends('layouts.app')

@section('title', __('contributor.title').' | My Kitchen')
@section('robots', 'noindex, nofollow')

@section('content')
    <section class="auth-page">
        <div class="auth-photo auth-photo-register" role="img" aria-label=""></div>
        <livewire:contributor-application :locale="$locale" />
    </section>
@endsection
