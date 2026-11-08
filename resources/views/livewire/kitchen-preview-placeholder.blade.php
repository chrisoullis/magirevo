<section class="home-kitchen home-container" aria-label="{{ __('navigation.my_kitchen') }}">
    <div class="kitchen-panel home-preview-placeholder"><h2>{{ __('homepage.kitchen_heading') }}</h2><p role="status">{{ __('homepage.loading_kitchen') }}</p><a href="{{ route('recipes.saved', ['locale' => app()->getLocale()], false) }}">{{ __('site.open_box') }}</a></div>
</section>
