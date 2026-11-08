@extends('layouts.app')

@section('title', __('households.invitation.title').' | My Kitchen')
@section('robots', 'noindex, nofollow')

@section('content')
    <x-site.kitchen-shortcuts />
    <section class="mx-auto w-full max-w-xl px-4 py-8 sm:px-6">
        <livewire:household-invitation-accept :locale="$locale" :token="$token" />
    </section>
@endsection
