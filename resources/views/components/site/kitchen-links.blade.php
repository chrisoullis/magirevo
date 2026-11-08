@props(['links'])
@if (auth()->user()?->hasVerifiedEmail())
    @foreach ($links as $link)<x-site.nav-link :link="$link" />@endforeach
@elseif (auth()->check())
    <p class="kitchen-menu-note">{{ __('navigation.verify_hint') }}</p>
    <a class="kitchen-nav-link" href="{{ route('verification.notice', [], false) }}"><x-ui.icon name="user" />{{ __('site.verify_email') }}</a>
@else
    <p class="kitchen-menu-heading">{{ __('navigation.your_kitchen') }}</p>
    <p class="kitchen-menu-note">{{ __('navigation.sign_in_hint') }}</p>
    <x-ui.button :href="route('login', [], false)">{{ __('site.log_in') }}<x-ui.icon name="arrow" /></x-ui.button>
    <a class="kitchen-menu-register" href="{{ route('register', [], false) }}">{{ __('navigation.create_account') }}</a>
@endif
