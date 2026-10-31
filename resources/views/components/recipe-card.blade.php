@props(['recipe', 'locale' => app()->getLocale(), 'heading' => 'h3'])
@php
    $url = route('recipes.show', ['locale' => $locale, 'slug' => $recipe->translate($locale)->slug], false);
    $category = $recipe->categoryLabel();
@endphp
<article {{ $attributes->class(['recipe-card']) }}>
    <a class="recipe-card-image" href="{{ $url }}" tabindex="-1" aria-hidden="true">
        <img src="{{ $recipe->coverUrl() }}" alt="" loading="lazy" width="720" height="540" onerror="this.onerror=null;this.src='{{ asset('images/recipe-placeholder.svg') }}'">
        @if ($category !== '')<span class="image-category">{{ $category }}</span>@endif
    </a>
    <div class="recipe-card-copy">
        <div class="recipe-meta"><span>{{ __('site.minutes_short', ['minutes' => $recipe->totalMinutes()]) }}</span>@if ($recipe->difficultyLabel())<span class="meta-separator"></span><span>{{ $recipe->difficultyLabel() }}</span>@endif</div>
        <{{ $heading }}><a href="{{ $url }}">{{ $recipe->translate($locale)->title }}</a></{{ $heading }}>
        <p>{{ $recipe->translate($locale)->summary }}</p>
        <a class="text-link" href="{{ $url }}">{{ __('site.get_recipe') }} <span aria-hidden="true">&#8594;</span></a>
    </div>
</article>
