@extends('layouts.app')

@section('title', __('sharing.page.title').' | The Sunday Table')
@section('robots', 'noindex, nofollow')

@section('content')
    <section class="saved-page">
        <div class="saved-heading">
            <span class="eyebrow"><span class="eyebrow-dot"></span> {{ __('sharing.page.eyebrow') }}</span>
            <h1>{{ __('sharing.page.heading') }}</h1>
            <p>{{ __('sharing.page.intro') }}</p>
        </div>
        @if ($recipes->isEmpty())
            <div class="empty-state saved-empty">
                <span class="empty-mark" aria-hidden="true">&#10038;</span>
                <h2>{{ __('sharing.page.empty_title') }}</h2>
                <p>{{ __('sharing.page.empty_text') }}</p>
            </div>
        @else
            <div class="recipe-grid saved-grid">
                @foreach ($recipes as $recipe)
                    @php($translation = $recipe->displayTranslation($locale))
                    @continue($translation === null)
                    <article class="recipe-card">
                        <a class="recipe-card-image" href="{{ route('recipes.show', ['locale' => $translation->locale, 'slug' => $translation->slug], false) }}" tabindex="-1" aria-hidden="true">
                            <img src="{{ $recipe->coverUrl() }}" alt="" loading="lazy" width="720" height="540">
                        </a>
                        <div class="recipe-card-copy">
                            <h2><a href="{{ route('recipes.show', ['locale' => $translation->locale, 'slug' => $translation->slug], false) }}">{{ $translation->title }}</a></h2>
                            <p>{{ $translation->summary }}</p>
                            @if ($sharers->has($recipe->pivot->shared_by))
                                <p>{{ __('sharing.page.by', ['name' => $sharers[$recipe->pivot->shared_by]]) }}</p>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
