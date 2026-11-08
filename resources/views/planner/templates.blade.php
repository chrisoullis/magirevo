@extends('layouts.app')

@section('title', __('planner.templates.title').' | My Kitchen')
@section('robots', 'noindex, nofollow')

@section('content')
    <x-site.kitchen-shortcuts />
    <section class="mx-auto w-full max-w-3xl px-4 py-8 sm:px-6">
        <livewire:meal-plan-templates :locale="$locale" :household-id="isset($household) ? (int) $household : null" />
    </section>
@endsection
