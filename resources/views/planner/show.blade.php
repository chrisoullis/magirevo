@extends('layouts.app')

@section('title', __('planner.title').' | The Sunday Table')
@section('robots', 'noindex, nofollow')

@section('content')
    <section class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6">
        <livewire:meal-planner :locale="$locale" :household-id="isset($household) ? (int) $household : null" />
    </section>
@endsection
