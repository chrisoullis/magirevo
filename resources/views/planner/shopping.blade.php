@extends('layouts.app')

@section('title', __('planner.shopping.title').' | The Sunday Table')
@section('robots', 'noindex, nofollow')

@section('content')
    <section class="mx-auto w-full max-w-3xl px-4 py-8 sm:px-6">
        <livewire:shopping-list-page :locale="$locale" :household-id="isset($household) ? (int) $household : null" />
    </section>
@endsection
