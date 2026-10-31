@extends('layouts.app')

@section('title', 'Log in | The Sunday Table')
@section('meta_description', 'Log in to your Sunday Table recipe box.')

@section('content')
    <section class="auth-page">
        <div class="auth-photo" role="img" aria-label="A table set for a shared meal"></div>
        <div class="auth-panel">
            <span class="eyebrow"><span class="eyebrow-dot"></span> Welcome back</span>
            <h1>Pull up<br><em>a chair.</em></h1>
            <p class="auth-intro">Log in to find your saved recipes.</p>
            <form class="auth-form" method="post" action="{{ route('login', [], false) }}">
                @csrf
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
                @error('email')<p class="field-error">{{ $message }}</p>@enderror
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
                @error('password')<p class="field-error">{{ $message }}</p>@enderror
                <label class="remember-option"><input type="checkbox" name="remember" value="1"><span>Keep me logged in</span></label>
                <button class="button button-dark auth-submit" type="submit">Log in <span aria-hidden="true">&#8594;</span></button>
            </form>
            <p class="auth-switch">New around here? <a href="{{ route('register', [], false) }}">Join the table</a></p>
        </div>
    </section>
@endsection