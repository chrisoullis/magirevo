@extends('layouts.app')

@section('title', $translation->name.' | '.__('ingredients.title'))
@section('meta_description', \Illuminate\Support\Str::limit(strip_tags((string) ($translation->description ?: __('ingredients.intro'))), 160))
@section('canonical', $alternateUrls[$locale])

@section('content')
    <article class="ingredient-page">
        <nav class="breadcrumbs" aria-label="{{ __('site.breadcrumb') }}"><a href="{{ route('ingredients.index', ['locale' => $locale], false) }}">{{ __('ingredients.title') }}</a><span aria-hidden="true">/</span><span aria-current="page">{{ $translation->name }}</span></nav>
        <div class="ingredient-detail">
            <figure class="ingredient-portrait">
                <img src="{{ $ingredient->encyclopediaImageUrl() }}" alt="{{ $ingredient->encyclopediaImageAlt($locale) }}" width="1920" height="1280" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/ingredient-placeholder.svg') }}'">
                @if ($ingredient->publicPrimaryImage?->media->getTranslation($locale, false)?->caption)
                    <figcaption>{{ $ingredient->publicPrimaryImage->media->getTranslation($locale, false)->caption }}</figcaption>
                @endif
                @if ($ingredient->publicPrimaryImage?->media->credit)<figcaption>{{ $ingredient->publicPrimaryImage->media->credit }}</figcaption>@endif
            </figure>
            <div class="ingredient-heading">
                <span class="eyebrow"><span class="eyebrow-dot"></span>{{ $ingredient->catalogGroup->getTranslation($locale, false)->name }}</span>
                <h1>{{ $translation->name }}</h1>
                @if (filled($translation->description))<p class="ingredient-prose">{{ $translation->description }}</p>@endif
                @php($aliases = $ingredient->aliases->where('locale', $locale))
                @if ($aliases->isNotEmpty())<p><strong>{{ __('ingredients.aliases') }}:</strong> {{ $aliases->pluck('alias')->implode(', ') }}</p>@endif
                @foreach (['culinary_uses' => 'uses', 'preparation_tips' => 'preparation', 'storage_tips' => 'storage'] as $field => $label)
                    @if (filled($translation->$field))
                        <section class="ingredient-education" aria-labelledby="ingredient-{{ $field }}">
                            <h2 id="ingredient-{{ $field }}">{{ __('ingredients.'.$label) }}</h2>
                            <p class="ingredient-prose">{{ $translation->$field }}</p>
                        </section>
                    @endif
                @endforeach
            </div>
        </div>
        @if ($gallery->total() > 0)
            <section class="ingredient-section" aria-labelledby="ingredient-photos-title">
                <h2 id="ingredient-photos-title">{{ __('ingredients.more_photos') }}</h2>
                <div class="ingredient-gallery">
                    @foreach ($gallery as $photo)
                        <figure>
                            <a href="{{ $photo->publicUrl() }}" aria-label="{{ __('ingredients.open_photo') }}: {{ $photo->media->getTranslation($locale, false)->alt_text }}">
                                <img src="{{ $photo->publicUrl('thumbnail') }}" alt="{{ $photo->media->getTranslation($locale, false)->alt_text }}" loading="lazy" decoding="async" width="480" height="320" onerror="this.onerror=null;this.src='{{ asset('images/ingredient-placeholder.svg') }}'">
                            </a>
                            @if ($photo->media->getTranslation($locale, false)?->caption)<figcaption>{{ $photo->media->getTranslation($locale, false)->caption }}</figcaption>@endif
                            @if ($photo->media->credit)<figcaption>{{ $photo->media->credit }}</figcaption>@endif
                        </figure>
                    @endforeach
                </div>
                {{ $gallery->links('components.ingredient-pagination') }}
            </section>
        @endif
        <section class="ingredient-section" aria-labelledby="ingredient-substitutions-title">
            <h2 id="ingredient-substitutions-title">{{ __('ingredients.substitutions') }}</h2>
            @if ($substitutions->total() > 0)
                <p>{{ __('ingredients.substitution_note') }}</p>
                <ul class="ingredient-substitutions">
                    @foreach ($substitutions as $substitution)
                        <li><a href="{{ route('ingredients.show', ['locale' => $locale, 'slug' => $substitution->replacementIngredient->getTranslation($locale, false)->slug], false) }}">{{ $substitution->replacementIngredient->getTranslation($locale, false)->name }}</a>
                            @if (filled($substitution->getTranslation($locale, false)?->explanation))<p class="ingredient-prose">{{ $substitution->getTranslation($locale, false)->explanation }}</p>@endif
                        </li>
                    @endforeach
                </ul>
                {{ $substitutions->links('components.ingredient-pagination') }}
            @else
                <p>{{ __('ingredients.no_substitutions') }}</p>
            @endif
        </section>
        <section class="ingredient-section" aria-labelledby="ingredient-recipes-title">
            <h2 id="ingredient-recipes-title">{{ __('ingredients.recipes', ['name' => $translation->name]) }}</h2>
            <div class="recipe-grid">
                @forelse ($recipes as $recipe)
                    <x-recipe-card :recipe="$recipe" :locale="$locale" :difficulty-label="$difficultyLabels[$recipe->difficulty] ?? $recipe->difficulty ?? ''" />
                @empty
                    <p>{{ __('ingredients.no_recipes') }}</p>
                @endforelse
            </div>
            {{ $recipes->links('components.ingredient-pagination') }}
        </section>
        <p><a class="text-link" href="{{ route('ingredients.index', ['locale' => $locale], false) }}">{{ __('ingredients.back') }}</a></p>
        <p><a class="text-link" href="{{ route('recipes.what-can-i-cook', ['locale' => $locale, 'ingredient' => $ingredient->code], false) }}">{{ __('ingredients.cook_with') }}</a></p>
    </article>
@endsection
