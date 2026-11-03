<aside class="variation-attribution" aria-label="{{ __('variations.based_on') }}">
    <strong>{{ __('variations.based_on') }}</strong>
    @if ($attribution['title'])
        <p>{{ $attribution['title'] }}@if ($attribution['author']) — {{ $attribution['author'] }}@endif</p>
    @endif
    @if ($attribution['url'])<p><a class="text-link" href="{{ $attribution['url'] }}">{{ __('variations.back_original') }}</a></p>@endif
    @if ($attribution['summary'] ?? null)<p>{{ __('variations.change_summary_public') }} {{ $attribution['summary'] }}</p>@endif
    @if ($attribution['license_identifier'] ?? null)<p>{{ __('variations.license') }} @if ($attribution['license_url'])<a href="{{ $attribution['license_url'] }}" rel="noopener nofollow">{{ $attribution['license_identifier'] }}</a>@else{{ $attribution['license_identifier'] }}@endif</p>@endif
    @if ($attribution['updated'])<p>{{ __('variations.source_updated') }}</p>@endif
</aside>
