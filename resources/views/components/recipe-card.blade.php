@props(['recipe', 'locale' => app()->getLocale(), 'heading' => 'h3', 'difficultyLabel' => null, 'showSave' => false, 'saved' => false, 'claims' => []])
@php
    $url = route('recipes.show', ['locale' => $locale, 'slug' => $recipe->translate($locale)->slug], false);
    $category = $recipe->categoryLabel();
    $difficultyLabel ??= $recipe->difficultyLabel();
@endphp
<article {{ $attributes->class(['recipe-card']) }}>
    <div class="recipe-card-visual">
    <a class="recipe-card-image" href="{{ $url }}" tabindex="-1" aria-hidden="true">
        <img src="{{ $recipe->coverUrl() }}" alt="" loading="lazy" width="720" height="540" onerror="this.onerror=null;this.src='{{ asset('images/recipe-placeholder.svg') }}'">
        @if ($category !== '')<span class="image-category">{{ $category }}</span>@endif
    </a>
    @if ($showSave)
        @if (auth()->user()?->hasVerifiedEmail())
            <form class="home-save-form" action="{{ route('recipes.save', ['locale' => $locale, 'recipe' => $recipe->id], false) }}" method="post">@csrf<button type="submit" class="home-save-button" aria-label="{{ __($saved ? 'site.remove_saved' : 'site.save_recipe') }}: {{ $recipe->translate($locale)->title }}" aria-pressed="{{ $saved ? 'true' : 'false' }}"><x-ui.icon name="bookmark" /></button></form>
        @else
            <a class="home-save-button" href="{{ auth()->check() ? route('verification.notice', [], false) : route('login', [], false) }}" aria-label="{{ __('site.join_to_save') }}: {{ $recipe->translate($locale)->title }}"><x-ui.icon name="bookmark" /></a>
        @endif
    @endif
    </div>
    <div class="recipe-card-copy">
        <div class="recipe-meta"><span>{{ __('site.minutes_short', ['minutes' => $recipe->totalMinutes()]) }}</span>@if ($difficultyLabel)<span class="meta-separator"></span><span>{{ $difficultyLabel }}</span>@endif</div>
        <{{ $heading }}><a href="{{ $url }}">{{ $recipe->translate($locale)->title }}</a></{{ $heading }}>
        @if (($recipe->public_rating_count ?? 0) > 0)
            <p class="home-recipe-rating"><span aria-hidden="true">★</span> {{ number_format((float) $recipe->public_rating_average, 1) }} <span>({{ $recipe->public_rating_count }})</span><span class="sr-only">{{ __('homepage.rating_scale') }}</span></p>
        @endif
        @if ($claims !== [])<ul class="recipe-verified-claims">@foreach ($claims as $claim)<li><x-ui.icon name="check" /><span aria-label="{{ __('discovery.verified_claim', ['name' => $claim]) }}">{{ $claim }}</span></li>@endforeach</ul>@endif
        <p>{{ $recipe->translate($locale)->summary }}</p>
        <a class="text-link" href="{{ $url }}">{{ __('site.get_recipe') }} <span aria-hidden="true">&#8594;</span></a>
    </div>
</article>
