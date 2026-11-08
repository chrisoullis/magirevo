@props(['navigation', 'localeUrls'])
<header class="site-header kitchen-header">
    <div class="kitchen-header-inner">
        <x-site.brand />
        <nav class="kitchen-desktop-nav" aria-label="{{ __('site.main_nav') }}">
            @foreach ($navigation['primary'] as $link)<x-site.nav-link :link="$link" :icon="false" />@endforeach
            <x-site.dropdown id="desktop-kitchen" :class="collect($navigation['kitchen'])->contains('active', true) ? 'is-active' : ''">
                <x-slot:trigger>{{ __('navigation.my_kitchen') }}</x-slot:trigger>
                <x-site.kitchen-links :links="$navigation['kitchen']" />
            </x-site.dropdown>
        </nav>
        <div class="kitchen-header-actions">
            @if ($navigation['createUrl'])
                <x-ui.button :href="$navigation['createUrl']" class="kitchen-create"><x-ui.icon name="plus" />{{ __('navigation.create_recipe') }}</x-ui.button>
            @endif
            <nav class="kitchen-language-switcher" aria-label="{{ __('site.language_label') }}">
                @foreach (['en' => 'EN', 'el' => 'EL'] as $language => $label)
                    <a href="{{ $localeUrls[$language] }}" lang="{{ $language }}" hreflang="{{ $language }}" @if(app()->getLocale() === $language) aria-current="true" @endif data-language-link>{{ $label }}<span class="sr-only"> — {{ $language === 'en' ? 'English' : 'Ελληνικά' }}</span></a>
                @endforeach
            </nav>
            @auth
                <x-site.dropdown id="account-navigation" class="kitchen-account">
                    <x-slot:trigger><span class="kitchen-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span><span class="sr-only">{{ __('navigation.account_for', ['name' => auth()->user()->name]) }}</span></x-slot:trigger>
                    <p class="kitchen-menu-heading kitchen-account-name">{{ auth()->user()->name }}</p>
                    @if (! auth()->user()->hasVerifiedEmail())
                        <a class="kitchen-nav-link" href="{{ route('verification.notice', [], false) }}">{{ __('site.verify_email') }}</a>
                    @endif
                    @if (auth()->user()->isStaff())
                        <a class="kitchen-nav-link" href="{{ route('filament.admin.pages.dashboard', [], false) }}"><x-ui.icon name="home" />{{ __('navigation.administration') }}</a>
                    @endif
                    <form action="{{ route('logout', [], false) }}" method="post">@csrf<button class="kitchen-nav-link" type="submit"><x-ui.icon name="logout" />{{ __('site.log_out') }}</button></form>
                </x-site.dropdown>
            @else
                <a class="kitchen-login" href="{{ route('login', [], false) }}">{{ __('site.log_in') }}</a>
                <x-ui.button :href="route('register', [], false)" class="kitchen-register">{{ __('site.join') }}</x-ui.button>
            @endauth
            <details class="kitchen-mobile-menu" data-navigation-disclosure>
                <summary class="kitchen-icon-button" aria-controls="mobile-navigation-panel"><x-ui.icon name="menu" /><span class="sr-only">{{ __('navigation.open_menu') }}</span></summary>
                <nav id="mobile-navigation-panel" class="kitchen-mobile-panel" aria-label="{{ __('navigation.mobile_navigation') }}">
                    <p class="kitchen-menu-heading">{{ __('navigation.discover') }}</p>
                    @foreach ($navigation['primary'] as $link)<x-site.nav-link :link="$link" />@endforeach
                    <div class="kitchen-menu-section">
                        <p class="kitchen-menu-heading">{{ __('navigation.my_kitchen') }}</p>
                        <x-site.kitchen-links :links="$navigation['kitchen']" />
                    </div>
                    @if ($navigation['createUrl'])
                        <x-ui.button :href="$navigation['createUrl']"><x-ui.icon name="plus" />{{ __('navigation.create_recipe') }}</x-ui.button>
                    @endif
                    @auth
                        <div class="kitchen-menu-section">
                            <p class="kitchen-menu-heading kitchen-account-name">{{ auth()->user()->name }}</p>
                            @if (auth()->user()->isStaff())
                                <a class="kitchen-nav-link" href="{{ route('filament.admin.pages.dashboard', [], false) }}"><x-ui.icon name="home" />{{ __('navigation.administration') }}</a>
                            @endif
                            <form action="{{ route('logout', [], false) }}" method="post">@csrf<button class="kitchen-nav-link" type="submit"><x-ui.icon name="logout" />{{ __('site.log_out') }}</button></form>
                        </div>
                    @endauth
                </nav>
            </details>
        </div>
    </div>
</header>
@php
    $plannerLink = collect($navigation['primary'])->firstWhere('icon', 'calendar') ?? $navigation['primary'][2];
    $kitchenUrl = auth()->check()
        ? (auth()->user()->hasVerifiedEmail() ? route('my-kitchen.index', ['locale' => app()->getLocale()], false) : route('verification.notice', [], false))
        : route('login', [], false);
@endphp
<nav class="kitchen-bottom-nav" aria-label="{{ __('navigation.quick_navigation') }}">
    <x-site.nav-link :link="[...$navigation['primary'][0], 'label' => __('navigation.discover')]" />
    <x-site.nav-link :link="$navigation['primary'][1]" />
    <x-site.nav-link :link="$plannerLink" />
    <x-site.nav-link :link="['url' => $kitchenUrl, 'label' => __('navigation.my_kitchen'), 'icon' => 'home', 'active' => collect($navigation['kitchen'])->contains('active', true)]" />
</nav>
