@extends('layouts.app')

@section('title', __('households.invitation.title').' | The Sunday Table')
@section('robots', 'noindex, nofollow')

@section('content')
    <section class="mx-auto w-full max-w-xl px-4 py-8 sm:px-6">
        <livewire:household-invitation-accept :locale="$locale" :token="$token" />
    </section>
@endsection
