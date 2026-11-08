@props(['navigation'])
<footer class="site-footer kitchen-footer">
    <div class="kitchen-footer-inner">
        <div class="kitchen-footer-intro">
            <x-site.brand />
            <p>{{ __('navigation.footer_description') }}</p>
            <span class="kitchen-footer-motto"><x-ui.icon name="leaf" />{{ __('site.footer_credit') }}</span>
        </div>
        <nav aria-label="{{ __('navigation.footer_discover') }}">
            <h2>{{ __('navigation.discover') }}</h2>
            @foreach ($navigation['primary'] as $link)<a href="{{ $link['url'] }}">{{ $link['label'] }}</a>@endforeach
        </nav>
        <nav aria-label="{{ __('navigation.footer_kitchen') }}">
            <h2>{{ __('navigation.my_kitchen') }}</h2>
            @if (auth()->user()?->hasVerifiedEmail())
                @foreach ($navigation['kitchen'] as $link)<a href="{{ $link['url'] }}">{{ $link['label'] }}</a>@endforeach
            @elseif (auth()->check())
                <a href="{{ route('verification.notice', [], false) }}">{{ __('site.verify_email') }}</a>
            @else
                <a href="{{ route('login', [], false) }}">{{ __('site.log_in') }}</a>
                <a href="{{ route('register', [], false) }}">{{ __('navigation.create_account') }}</a>
            @endif
        </nav>
    </div>
    <div class="kitchen-footer-bottom"><span>&copy; {{ now()->year }} My Kitchen</span><span>{{ __('site.footer_tagline') }}</span><a href="{{ route('sitemap', [], false) }}">{{ __('site.sitemap') }}</a></div>
</footer>
