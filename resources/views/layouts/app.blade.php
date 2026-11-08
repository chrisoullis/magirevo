@php
    $currentLocale = app()->getLocale();
    $alternateUrls ??= null;
    $pageTitle = trim($__env->yieldContent('title', __('site.default_title')));
    $pageDescription = trim($__env->yieldContent('meta_description', __('site.default_description')));
    $canonicalUrl = trim($__env->yieldContent('canonical', url()->current()));
    $navigation = app(\App\Support\PublicNavigation::class)->links(auth()->user(), $currentLocale);
    $localeUrls = collect(config('translatable.locales'))->mapWithKeys(fn ($language) => [$language => app(\App\Support\PublicLocaleUrls::class)->resolve($language, $alternateUrls ?? [], $languageSwitchUrls ?? [])])->all();
    $ogLocales = ['en' => 'en_US', 'el' => 'el_GR'];
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', $currentLocale) }}">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#FAF9F6">
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
    <meta property="og:site_name" content="My Kitchen">
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
    <meta name="twitter:card" content="{{ trim($__env->yieldContent('og_image')) !== '' ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ trim($__env->yieldContent('og_title', $pageTitle)) }}">
    <meta name="twitter:description" content="{{ $pageDescription }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans:wght@400;500;600;700&family=Noto+Serif:wght@400;500;600&display=swap" rel="stylesheet">
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    @livewireStyles
    @stack('head')
</head>
<body>
    <a class="skip-link" href="#main">{{ __('site.skip') }}</a>
    <x-site.header :navigation="$navigation" :locale-urls="$localeUrls" />

    @if (session('status'))
        <div class="status-message" role="status">{{ session('status') }}</div>
    @endif

    <main id="main" tabindex="-1">
        @yield('content')
    </main>

    <x-site.footer :navigation="$navigation" />
    @stack('scripts')
    @livewireScripts
</body>
</html>
