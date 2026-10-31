@extends('layouts.app')

@section('title', 'Join the table | The Sunday Table')
@section('meta_description', 'Create your free Sunday Table account to save recipes and build your personal recipe box.')

@section('content')
    <section class="auth-page">
        <div class="auth-photo auth-photo-register" role="img" aria-label="Fresh produce ready to prepare for dinner"></div>
        <div class="auth-panel">
            <span class="eyebrow"><span class="eyebrow-dot"></span> Save a place for yourself</span>
            <h1>There's always<br><em>room for one.</em></h1>
            <p class="auth-intro">Make an account to keep your favorite recipes close.</p>
            <form class="auth-form" method="post" action="{{ route('register', [], false) }}">
                @csrf
                <label for="name">Your name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" autocomplete="name" required autofocus>
                @error('name')<p class="field-error">{{ $message }}</p>@enderror
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
                @error('email')<p class="field-error">{{ $message }}</p>@enderror
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required>
                @error('password')<p class="field-error">{{ $message }}</p>@enderror
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                <button class="button button-dark auth-submit" type="submit">Create my account <span aria-hidden="true">&#8594;</span></button>
            </form>
            <p class="auth-switch">Already have a place? <a href="{{ route('login', [], false) }}">Log in</a></p>
        </div>
    </section>
@endsection