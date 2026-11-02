@php
    $currentLocale = app()->getLocale();
    $alternateUrls ??= null;
    $pageTitle = trim($__env->yieldContent('title', __('site.default_title')));
    $pageDescription = trim($__env->yieldContent('meta_description', __('site.default_description')));
    $canonicalUrl = trim($__env->yieldContent('canonical', url()->current()));
    $ogLocales = ['en' => 'en_US', 'el' => 'el_GR'];
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', $currentLocale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f7f2">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    @if ($alternateUrls)
        @foreach ($alternateUrls as $alternateLocale => $alternateUrl)
            <link rel="alternate" hreflang="{{ $alternateLocale }}" href="{{ $alternateUrl }}">
        @endforeach
        <link rel="alternate" hreflang="x-default" href="{{ $alternateUrls[config('translatable.fallback_locale')] ?? reset($alternateUrls) }}">
    @endif
    @hasSection('robots')<meta name="robots" content="@yield('robots')">@endif
    <meta property="og:site_name" content="The Sunday Table">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:title" content="{{ trim($__env->yieldContent('og_title', $pageTitle)) }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:locale" content="{{ $ogLocales[$currentLocale] ?? 'en_US' }}">
    @foreach (array_diff_key($ogLocales, [$currentLocale => true]) as $alternateOgLocale)
        <meta property="og:locale:alternate" content="{{ $alternateOgLocale }}">
    @endforeach
    @hasSection('og_image')
        <meta property="og:image" content="@yield('og_image')">
        <meta name="twitter:image" content="@yield('og_image')">
    @endif
    <meta name="twitter:card" content="@hasSection('og_image')summary_large_image@else summary @endif">
    <meta name="twitter:title" content="{{ trim($__env->yieldContent('og_title', $pageTitle)) }}">
    <meta name="twitter:description" content="{{ $pageDescription }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Newsreader:opsz,wght@6..72,400;6..72,500;6..72,600&display=swap&subset=greek,greek-ext" rel="stylesheet">
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    @livewireStyles
    @stack('head')
</head>
<body>
    <a class="skip-link" href="#main">{{ __('site.skip') }}</a>
    <header class="site-header">
        <div class="header-inner">
            <a class="brand" href="{{ route('home.localized', ['locale' => $currentLocale], false) }}" aria-label="{{ __('site.brand_label') }}">
                <span class="brand-mark" aria-hidden="true">ST</span>
                <span>The Sunday Table</span>
            </a>
            <nav class="language-switcher" aria-label="{{ __('site.language_label') }}">
                @foreach (['en' => 'EN', 'el' => 'EL'] as $switchLocale => $label)
                    @php
                        $localeUrl = $alternateUrls[$switchLocale]
                            ?? (request()->routeIs('recipes.saved')
                                ? route('recipes.saved', ['locale' => $switchLocale], false)
                                : route('home.localized', ['locale' => $switchLocale], false));
                    @endphp
                    <a href="{{ $localeUrl }}" lang="{{ $switchLocale }}" hreflang="{{ $switchLocale }}" @if ($currentLocale === $switchLocale) aria-current="true" @endif @class(['is-active' => $currentLocale === $switchLocale])>{{ $label }}</a>
                @endforeach
            </nav>
            <nav class="main-nav" aria-label="{{ __('site.main_nav') }}">
                <a href="{{ route('home.localized', ['locale' => $currentLocale], false) }}#recipes">{{ __('site.nav_recipes') }}</a>
                <a href="{{ route('recipes.what-can-i-cook', ['locale' => $currentLocale], false) }}">{{ __('site.what_can_i_cook') }}</a>
                @auth
                    @if (auth()->user()->hasVerifiedEmail())
                        <a href="{{ route('recipes.saved', ['locale' => $currentLocale], false) }}">{{ __('site.recipe_box') }}</a>
                        @can(\App\Support\AppPermissions::RECIPES_VIEW_SHARED)
                            <a href="{{ route('recipes.shared', ['locale' => $currentLocale], false) }}">{{ __('sharing.nav') }}</a>
                        @endcan
                        @can('viewAny', \App\Models\Household::class)
                            <a href="{{ route('planner.index', ['locale' => $currentLocale], false) }}">{{ __('planner.nav') }}</a>
                            <a href="{{ route('households.index', ['locale' => $currentLocale], false) }}">{{ __('households.nav') }}</a>
                        @endcan
                        @can('create', \App\Models\ContributorRequest::class)
                            <a href="{{ route('contributor.show', ['locale' => $currentLocale], false) }}">{{ __('contributor.nav') }}</a>
                        @endcan
                    @else
                        <a href="{{ route('verification.notice', [], false) }}">{{ __('site.verify_email') }}</a>
                    @endif
                    <form action="{{ route('logout', [], false) }}" method="post">
                        @csrf
                        <button class="nav-action" type="submit">{{ __('site.log_out') }}</button>
                    </form>
                @else
                    <a href="{{ route('login', [], false) }}">{{ __('site.log_in') }}</a>
                    <a class="nav-join" href="{{ route('register', [], false) }}">{{ __('site.join') }} <span aria-hidden="true">&#8599;</span></a>
                @endauth
            </nav>
        </div>
    </header>

    @if (session('status'))
        <div class="status-message" role="status">{{ session('status') }}</div>
    @endif

    <main id="main">
        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="footer-inner">
            <a class="brand footer-brand" href="{{ route('home.localized', ['locale' => $currentLocale], false) }}"><span class="brand-mark" aria-hidden="true">ST</span><span>The Sunday Table</span></a>
            <p>{{ __('site.footer_tagline') }}</p>
            <a href="{{ route('sitemap', [], false) }}">{{ __('site.sitemap') }}</a>
        </div>
        <div class="footer-bottom"><span>&copy; {{ now()->year }} The Sunday Table</span><span>{{ __('site.footer_credit') }}</span></div>
    </footer>
    @livewireScripts
</body>
</html>
