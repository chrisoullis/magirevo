@extends('layouts.app')

@section('title', 'Verify your email | The Sunday Table')
@section('meta_description', 'Verify your email address to start saving recipes in your Sunday Table recipe box.')

@section('content')
    <section class="verify-page">
        <span class="verify-ornament" aria-hidden="true">&#10038;</span>
        <span class="eyebrow"><span class="eyebrow-dot"></span> One small thing</span>
        <h1>Check your<br><em>inbox.</em></h1>
        <p>We sent a link to <strong>{{ auth()->user()->email }}</strong>. Confirm your email to save recipes and open your recipe box.</p>
        @if (session('status'))<p class="verify-status" role="status">{{ session('status') }}</p>@endif
        <form method="post" action="{{ route('verification.send', [], false) }}">
            @csrf
            <button class="button button-dark" type="submit">Send a fresh link <span aria-hidden="true">&#8594;</span></button>
        </form>
    </section>
@endsection