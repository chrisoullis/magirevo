@extends('layouts.app')

@section('title', $translation->name.' | '.__('ingredients.title').' | My Kitchen')
@section('meta_description', \Illuminate\Support\Str::limit(strip_tags((string) ($translation->description ?: __('ingredients.intro'))), 160))
@section('canonical', $alternateUrls[$locale])
@if ($ingredient->publicPrimaryImage) @section('og_image', url($ingredient->encyclopediaImageUrl())) @endif

@php
    $otherLocale = $locale === 'en' ? 'el' : 'en';
    $education = collect(['culinary_uses' => 'uses', 'preparation_tips' => 'preparation', 'storage_tips' => 'storage'])->filter(fn ($label, $field) => filled($translation->$field));
    $structuredIngredient = array_filter([
        '@context' => 'https://schema.org', '@type' => 'DefinedTerm', 'name' => $translation->name,
        'description' => strip_tags((string) $translation->description), 'url' => $alternateUrls[$locale],
        'inDefinedTermSet' => route('ingredients.index', ['locale' => $locale]),
        'image' => $ingredient->publicPrimaryImage ? url($ingredient->encyclopediaImageUrl()) : null,
        'alternateName' => $ingredient->aliases->where('locale', $locale)->pluck('alias')->values()->all(),
    ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    $structuredBreadcrumb = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => __('site.home'), 'item' => route('home.localized', ['locale' => $locale])],
        ['@type' => 'ListItem', 'position' => 2, 'name' => __('ingredients.title'), 'item' => route('ingredients.index', ['locale' => $locale])],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $translation->name, 'item' => $alternateUrls[$locale]],
    ]];
@endphp
@push('head')<script type="application/ld+json">@json([$structuredIngredient, $structuredBreadcrumb], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>@endpush

@section('content')
    <article class="ingredient-page encyclopedia-detail">
        <nav class="breadcrumbs" aria-label="{{ __('site.breadcrumb') }}"><a href="{{ route('home.localized', ['locale' => $locale], false) }}">{{ __('site.home') }}</a><span aria-hidden="true">/</span><a href="{{ route('ingredients.index', ['locale' => $locale], false) }}">{{ __('ingredients.title') }}</a><span aria-hidden="true">/</span><span aria-current="page">{{ $translation->name }}</span></nav>
        <div class="ingredient-detail">
            <figure class="ingredient-portrait">
                <x-ingredient-image :ingredient="$ingredient" :thumbnail="false" :eager="true" sizes="(min-width: 900px) 50vw, 100vw" />
                @if ($ingredient->publicPrimaryImage?->media->getTranslation($locale, false)?->caption)<figcaption>{{ $ingredient->publicPrimaryImage->media->getTranslation($locale, false)->caption }}</figcaption>@endif
                @if ($ingredient->publicPrimaryImage?->media->credit)<figcaption>{{ $ingredient->publicPrimaryImage->media->credit }}</figcaption>@endif
            </figure>
            <div class="ingredient-heading">
                <a class="ingredient-group-pill" href="{{ route('ingredients.index', ['locale' => $locale, 'group' => $ingredient->catalogGroup->code], false) }}"><x-ui.icon name="leaf" />{{ $ingredient->catalogGroup->getTranslation($locale, false)->name }}</a>
                <h1>{{ $translation->name }}</h1>
                @if ($ingredient->hasPublicTranslation($otherLocale))<a class="ingredient-other-name" lang="{{ $otherLocale }}" hreflang="{{ $otherLocale }}" href="{{ $ingredient->encyclopediaUrl($otherLocale) }}">{{ $ingredient->getTranslation($otherLocale, false)->name }}</a>@endif
                @if (filled($translation->description))<p class="ingredient-prose ingredient-introduction">{{ $translation->description }}</p>@else<p class="ingredient-pending">{{ __('ingredients.content_pending') }}</p>@endif
                @php($aliases = $ingredient->aliases->where('locale', $locale))
                @if ($aliases->isNotEmpty())<p class="ingredient-aliases"><strong>{{ __('ingredients.aliases') }}:</strong> {{ $aliases->pluck('alias')->implode(', ') }}</p>@endif
                <div class="ingredient-detail-actions"><x-ui.button :href="route('recipes.what-can-i-cook', ['locale' => $locale, 'ingredient' => $ingredient->code], false)"><x-ui.icon name="sparkles" />{{ __('ingredients.cook_with') }}</x-ui.button><x-ui.button href="#ingredient-recipes-title" variant="secondary">{{ __('ingredients.view_recipes') }}</x-ui.button></div>
                <nav class="ingredient-section-links" aria-label="{{ __('ingredients.on_this_page') }}">@foreach ($education as $field => $label)<a href="#ingredient-{{ $field }}">{{ __('ingredients.'.$label) }}</a>@endforeach<a href="#ingredient-substitutions-title">{{ __('ingredients.substitutions') }}</a></nav>
            </div>
        </div>
        @if ($education->isNotEmpty())
            <div class="ingredient-education-grid">@foreach ($education as $field => $label)<section class="ingredient-education kitchen-panel" aria-labelledby="ingredient-{{ $field }}"><span class="ingredient-education-icon"><x-ui.icon :name="match($field) { 'culinary_uses' => 'book', 'preparation_tips' => 'clock', default => 'home' }" /></span><h2 id="ingredient-{{ $field }}">{{ __('ingredients.'.$label) }}</h2><p class="ingredient-prose">{{ $translation->$field }}</p></section>@endforeach</div>
        @endif
        @if ($gallery->total() > 0)
            <section class="ingredient-section" aria-labelledby="ingredient-photos-title"><h2 id="ingredient-photos-title">{{ __('ingredients.more_photos') }}</h2><div class="ingredient-gallery">@foreach ($gallery as $photo)<figure><a href="{{ $photo->publicUrl() }}" aria-label="{{ __('ingredients.open_photo') }}: {{ $photo->media->getTranslation($locale, false)->alt_text }}"><img src="{{ $photo->publicUrl('thumbnail') }}" alt="{{ $photo->media->getTranslation($locale, false)->alt_text }}" loading="lazy" decoding="async" width="480" height="320" onerror="this.onerror=null;this.src='{{ asset('images/ingredient-placeholder.svg') }}'"></a>@if ($photo->media->getTranslation($locale, false)?->caption)<figcaption>{{ $photo->media->getTranslation($locale, false)->caption }}</figcaption>@endif @if ($photo->media->credit)<figcaption>{{ $photo->media->credit }}</figcaption>@endif</figure>@endforeach</div>{{ $gallery->links('components.ingredient-pagination') }}</section>
        @endif
        <section class="ingredient-section ingredient-substitution-panel" aria-labelledby="ingredient-substitutions-title">
            <span class="home-eyebrow">{{ __('ingredients.in_the_kitchen') }}</span><h2 id="ingredient-substitutions-title">{{ __('ingredients.substitutions') }}</h2>
            @if ($substitutions->total() > 0)
                <p>{{ __('ingredients.substitution_note') }}</p><ul class="ingredient-substitutions">@foreach ($substitutions as $substitution)<li><x-ui.icon name="leaf" /><div><a href="{{ $substitution->replacementIngredient->encyclopediaUrl($locale) }}">{{ $substitution->replacementIngredient->getTranslation($locale, false)->name }}</a>@if (filled($substitution->getTranslation($locale, false)?->explanation))<p class="ingredient-prose">{{ $substitution->getTranslation($locale, false)->explanation }}</p>@endif</div></li>@endforeach</ul>{{ $substitutions->links('components.ingredient-pagination') }}
            @else<p>{{ __('ingredients.no_substitutions') }}</p>@endif
        </section>
        <section class="ingredient-section" aria-labelledby="ingredient-recipes-title"><div class="home-section-heading"><h2 id="ingredient-recipes-title">{{ __('ingredients.recipes', ['name' => $translation->name]) }}</h2><a class="home-section-link" href="{{ route('home.localized', ['locale' => $locale, 'ingredients' => [$ingredient->code]], false) }}#recipes">{{ __('ingredients.filter_recipes') }}<x-ui.icon name="arrow" /></a></div><div class="recipe-grid">@forelse ($recipes as $recipe)<x-recipe-card :recipe="$recipe" :locale="$locale" :difficulty-label="$difficultyLabels[$recipe->difficulty] ?? $recipe->difficulty ?? ''" />@empty<p>{{ __('ingredients.no_recipes') }}</p>@endforelse</div>{{ $recipes->links('components.ingredient-pagination') }}</section>
        <a class="kitchen-button kitchen-button-secondary" href="{{ route('ingredients.index', ['locale' => $locale], false) }}"><x-ui.icon name="leaf" />{{ __('ingredients.back') }}</a>
    </article>
@endsection
