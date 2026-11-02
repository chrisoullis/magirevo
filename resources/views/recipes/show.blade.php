@extends('layouts.app')

@section('title', ($recipe->seo_title ?: $recipe->title).' | The Sunday Table')
@section('meta_description', $recipe->seo_description ?: $recipe->summary)
@section('og_title', ($recipe->seo_title ?: $recipe->title).' | The Sunday Table')
@section('og_type', 'article')
@if ($isPublic)
    @section('og_image', $recipe->coverUrl())
@else
    @section('robots', 'noindex, nofollow, noarchive')
@endif
@section('canonical', $alternateUrls[$locale])

@if ($isPublic)
    @push('head')
        <script type="application/ld+json">@json($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
    @endpush
@endif

@php
    $cover = $recipe->coverMedia();
    $dietaryClaims = app(\App\Services\DietaryClaimVisibility::class)->publicClaims($recipe);
    $placeholder = asset('images/recipe-placeholder.svg');
    $introductionHtml = $recipe->introduction ? \Filament\Forms\Components\RichEditor\RichContentRenderer::make($recipe->introduction)->toHtml() : '';
    $showJump = mb_strlen(trim(strip_tags($introductionHtml))) > \App\Services\RecipeContentPresenter::LONG_INTRODUCTION_CHARACTERS;
    $printUrl = route('recipes.print', ['locale' => $locale, 'slug' => $recipe->slug], false);
@endphp

@section('content')
    <article class="recipe-detail">
        <nav class="breadcrumbs" aria-label="{{ __('site.breadcrumb') }}"><a href="{{ route('home.localized', ['locale' => $locale], false) }}">{{ __('site.home') }}</a><span aria-hidden="true">/</span><span aria-current="page">{{ $recipe->title }}</span></nav>
        <header class="recipe-detail-header">
            <div class="recipe-detail-heading">
                @if ($category)<span class="eyebrow"><span class="eyebrow-dot"></span> {{ $category->translate($locale)?->name }}</span>@endif
                <h1>{{ $recipe->title }}</h1>
                <p>{{ $recipe->summary }}</p>
                <div class="detail-stats"><span>{{ __('site.minutes_total', ['minutes' => $recipe->totalMinutes()]) }}</span>@if ($recipe->difficultyLabel())<span>{{ $recipe->difficultyLabel() }}</span>@endif<span>{{ __('site.serves', ['count' => $baseServings]) }}</span></div>
                @if ($dietaryClaims->isNotEmpty())
                    <p class="dietary-claims"><strong>{{ __('site.dietary_information') }}:</strong> {{ $dietaryClaims->map(fn ($claim) => $claim->tag->translate($locale)?->name)->filter()->implode(', ') }}<br><small>{{ __('site.dietary_disclaimer') }}</small></p>
                    @if (auth()->check())
                        @foreach ($dietaryClaims as $claim)
                            <form method="POST" action="{{ route('recipes.dietary-claims.report', ['locale' => $locale, 'slug' => $recipe->slug, 'claim' => $claim->getKey()], false) }}">
                                @csrf
                                <label for="dietary-claim-report-{{ $claim->getKey() }}">{{ __('site.dietary_report_label', ['claim' => $claim->tag->translate($locale)?->name]) }}</label>
                                <textarea id="dietary-claim-report-{{ $claim->getKey() }}" name="notes" required maxlength="1000"></textarea>
                                <button class="text-link" type="submit">{{ __('site.dietary_report_submit') }}</button>
                            </form>
                        @endforeach
                    @endif
                @endif
                @if (session('dietary-report-status'))<p class="status-message" role="status">{{ session('dietary-report-status') }}</p>@endif
                <div class="recipe-actions">
                    @if ($showJump)
                        <a class="button" href="#recipe-content" data-jump-to-recipe>{{ __('site.jump_to_recipe') }}</a>
                    @endif
                    @if ($steps !== [])
                        <button class="button button-dark" type="button" data-cooking-start hidden>{{ __('site.cooking_mode_start') }}</button>
                    @endif
                    <a class="button" href="{{ $printUrl }}" target="_blank" rel="noopener" data-print-link>{{ __('site.print_recipe') }}</a>
                </div>
                @auth
                    @if ($isPublic && auth()->user()->hasVerifiedEmail())
                        <form class="save-recipe-form" method="post" action="{{ route('recipes.save', ['locale' => $locale, 'recipe' => $recipe], false) }}">
                            @csrf
                            <button class="button button-dark" type="submit"><span aria-hidden="true">&#9825;</span> {{ auth()->user()->favoriteRecipes()->whereKey($recipe->id)->exists() ? __('site.remove_saved') : __('site.save_recipe') }}</button>
                        </form>
                    @else
                        <a class="button button-dark" href="{{ route('verification.notice', [], false) }}">{{ __('site.verify_to_save') }}</a>
                    @endif
                    @can('share', $recipe)
                        <a class="button" href="{{ route('recipes.sharing', ['locale' => $locale, 'recipe' => $recipe->id], false) }}">{{ __('sharing.manage_link') }}</a>
                    @endcan
                @else
                    <a class="button button-dark" href="{{ route('register', [], false) }}">{{ __('site.join_to_save') }} <span aria-hidden="true">&#8599;</span></a>
                @endauth
            </div>
            <figure class="recipe-detail-image">
                <img src="{{ $recipe->coverUrl() }}" alt="{{ $recipe->coverAlt() }}" width="1200" height="900" fetchpriority="high" onerror="this.onerror=null;this.src='{{ $placeholder }}'">
                <figcaption>
                    @if ($cover?->credit)
                        @if ($cover->credit_url)<a href="{{ $cover->credit_url }}" rel="noopener nofollow" target="_blank">{{ $cover->credit }}</a>@else{{ $cover->credit }}@endif
                    @else
                        {{ __('site.photo_caption') }}
                    @endif
                </figcaption>
            </figure>
        </header>
        <div class="recipe-content" id="recipe-content" tabindex="-1">
            @livewire('recipe-servings', ['recipeId' => $recipe->id, 'locale' => $locale, 'baseServings' => $baseServings])
            <section class="method-panel" aria-labelledby="method-title">
                <span class="eyebrow"><span class="eyebrow-dot"></span> {{ __('site.step_eyebrow') }}</span>
                <h2 id="method-title">{{ __('site.method_title') }}</h2>
                @if ($introductionHtml !== '')
                    <div class="recipe-description">{!! $introductionHtml !!}</div>
                @endif
                <ol class="method-list">
                    @foreach ($steps as $step)
                        <li>
                            <span class="step-number">{{ str_pad($step['number'], 2, '0', STR_PAD_LEFT) }}</span>
                            <div class="step-content">
                                <p>{{ $step['text'] }}</p>
                                @if ($step['minutes'])<span class="step-duration">{{ __('site.step_minutes', ['minutes' => $step['minutes']]) }}</span>@endif
                                @include('recipes.partials.step-media')
                            </div>
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>
        @if ($steps !== [])
            <div class="cooking-overlay" role="dialog" aria-modal="true" aria-labelledby="cooking-title" data-cooking-overlay data-recipe-id="{{ $recipe->id }}" data-total="{{ count($steps) }}" hidden
                data-label-progress="{{ __('site.cooking_mode_progress', ['current' => '{current}', 'total' => '{total}']) }}"
                data-label-done-count="{{ __('site.cooking_completed_count', ['count' => '{count}']) }}"
                data-label-timer-start="{{ __('site.timer_start', ['minutes' => '{minutes}']) }}"
                data-label-timer-stop="{{ __('site.timer_stop') }}"
                data-label-timer-finished="{{ __('site.timer_finished', ['step' => '{step}']) }}"
                data-label-timer-running="{{ __('site.timer_running', ['step' => '{step}']) }}"
                data-label-mark-done="{{ __('site.step_mark_done') }}"
                data-label-marked-done="{{ __('site.step_marked_done') }}"
                data-label-wake-on="{{ __('site.wake_lock_on') }}"
                data-label-wake-off="{{ __('site.wake_lock_off') }}">
                <div class="cooking-shell">
                    <header class="cooking-top">
                        <div>
                            <p class="cooking-recipe">{{ $recipe->title }}</p>
                            <h2 id="cooking-title" tabindex="-1" data-cooking-heading>{{ __('site.cooking_mode_title') }}</h2>
                        </div>
                        <div class="cooking-tools">
                            <button class="button" type="button" data-text-smaller aria-label="{{ __('site.text_smaller') }}">A&minus;</button>
                            <button class="button" type="button" data-text-larger aria-label="{{ __('site.text_larger') }}">A+</button>
                            <button class="button" type="button" data-wake-toggle aria-pressed="false" hidden>{{ __('site.wake_lock_off') }}</button>
                            <button class="button button-dark" type="button" data-cooking-close>{{ __('site.cooking_mode_close') }}</button>
                        </div>
                    </header>
                    <div class="cooking-progress">
                        <span data-cooking-progress aria-live="polite"></span>
                        <span data-cooking-done-count></span>
                        <div class="cooking-bar" role="progressbar" aria-label="{{ __('site.cooking_mode_title') }}" aria-valuemin="1" aria-valuemax="{{ count($steps) }}" aria-valuenow="1" data-cooking-bar><span></span></div>
                    </div>
                    <div class="cooking-timers" data-cooking-timers hidden></div>
                    <p class="sr-only" role="alert" data-cooking-alert></p>
                    <div class="cooking-body">
                        <div class="cooking-steps">
                            @foreach ($steps as $step)
                                <section class="cooking-step" data-cooking-step data-step-number="{{ $step['number'] }}" @if ($step['minutes']) data-minutes="{{ $step['minutes'] }}" @endif hidden aria-labelledby="cook-step-{{ $step['number'] }}">
                                    <h3 id="cook-step-{{ $step['number'] }}">{{ __('site.step_label', ['number' => $step['number']]) }}</h3>
                                    <p class="cooking-step-text">{{ $step['text'] }}</p>
                                    <div class="cooking-step-actions">
                                        @if ($step['minutes'])
                                            <button class="button" type="button" data-timer-toggle></button>
                                        @endif
                                        <button class="button" type="button" data-step-done aria-pressed="false"></button>
                                    </div>
                                    @include('recipes.partials.step-media')
                                </section>
                            @endforeach
                        </div>
                        <details class="cooking-ingredients" open>
                            <summary>{{ __('site.ingredients') }}</summary>
                            <p class="servings-note">{{ __('site.cooking_ingredients_note') }}</p>
                            <div data-cooking-ingredients></div>
                        </details>
                    </div>
                    <nav class="cooking-nav" aria-label="{{ __('site.cooking_mode_title') }}">
                        <button class="button" type="button" data-cooking-previous>{{ __('site.cooking_mode_previous') }}</button>
                        <button class="button button-dark" type="button" data-cooking-next>{{ __('site.cooking_mode_next') }}</button>
                    </nav>
                    <p class="cooking-note">{{ __('site.timer_background_note') }}</p>
                </div>
            </div>
        @endif
        @if ($gallery->isNotEmpty())
            <section class="recipe-gallery" aria-label="{{ __('site.gallery') }}">
                @foreach ($gallery as $image)
                    <img src="{{ $image->publicUrl() }}" alt="{{ $image->translate($locale)?->alt_text }}" loading="lazy" onerror="this.onerror=null;this.src='{{ $placeholder }}'">
                @endforeach
            </section>
        @endif
        @can(\App\Support\AppPermissions::RECIPES_PERSONALIZE)
            @livewire('personal-recipe-notes', ['recipeId' => $recipe->id, 'locale' => $locale], key('recipe-notes-'.$recipe->id))
        @endcan
        @if ($isPublic)
            @livewire('recipe-ratings', ['recipeId' => $recipe->id, 'locale' => $locale], key('recipe-ratings-'.$recipe->id))
        @endif
        @if ($related->isNotEmpty())
            <section class="related-recipes">
                <h2>{{ __('site.related') }}</h2>
                <div class="recipe-grid">
                    @foreach ($related as $item)
                        <x-recipe-card :recipe="$item" :locale="$locale" />
                    @endforeach
                </div>
            </section>
        @endif
    </article>
@endsection
