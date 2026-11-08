<a href="{{ route('home.localized', ['locale' => app()->getLocale()], false) }}" {{ $attributes->class('kitchen-brand') }} aria-label="{{ __('site.brand_label') }}">
    <span class="kitchen-brand-mark"><x-ui.icon name="leaf" /></span>
    <span>My Kitchen<span class="kitchen-brand-dot" aria-hidden="true">.</span></span>
</a>
