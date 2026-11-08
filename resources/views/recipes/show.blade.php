@extends('layouts.app')

@section('title', ($recipe->seo_title ?: $recipe->title).' | My Kitchen')
@section('meta_description', (string) ($recipe->seo_description ?: $recipe->summary))
@section('og_title', ($recipe->seo_title ?: $recipe->title).' | My Kitchen')
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
    <article class="recipe-detail recipe-experience">
        <nav class="breadcrumbs" aria-label="{{ __('site.breadcrumb') }}"><a href="{{ route('home.localized', ['locale' => $locale], false) }}">{{ __('site.home') }}</a><span aria-hidden="true">/</span>@if ($category && filled($category->getTranslation($locale, false)?->slug))<a href="{{ route('categories.show', ['locale' => $locale, 'slug' => $category->getTranslation($locale, false)->slug], false) }}">{{ $category->getTranslation($locale, false)->name }}</a><span aria-hidden="true">/</span>@endif<span aria-current="page">{{ $recipe->title }}</span></nav>
        <header class="recipe-detail-header">
            <div class="recipe-detail-heading">
                @if ($category)<span class="eyebrow"><span class="eyebrow-dot"></span> {{ $category->translate($locale)?->name }}</span>@endif
                <h1>{{ $recipe->title }}</h1>
                @if ($recipe->author)<p class="recipe-byline"><x-ui.icon name="user" />{{ __('recipe_detail.by', ['name' => $recipe->author->name]) }}</p>@endif
                <p>{{ $recipe->summary }}</p>
                <dl class="recipe-facts">
                    <div><dt>{{ __('site.prep') }}</dt><dd>{{ __('site.minutes_total', ['minutes' => $recipe->prep_minutes]) }}</dd></div>
                    <div><dt>{{ __('site.cook') }}</dt><dd>{{ __('site.minutes_total', ['minutes' => $recipe->cook_minutes]) }}</dd></div>
                    @if ($recipe->rest_minutes)<div><dt>{{ __('recipe_detail.rest') }}</dt><dd>{{ __('site.minutes_total', ['minutes' => $recipe->rest_minutes]) }}</dd></div>@endif
                    <div><dt>{{ __('recipe_detail.total') }}</dt><dd>{{ __('site.minutes_total', ['minutes' => $recipe->totalMinutes() + $recipe->rest_minutes]) }}</dd></div>
                    <div><dt>{{ __('site.servings') }}</dt><dd>{{ $baseServings }}</dd></div>
                </dl>
                <div class="recipe-summary-meta">@if ($recipe->difficultyLabel())<span><x-ui.icon name="leaf" />{{ $recipe->difficultyLabel() }}</span>@endif @if ($ratingSummary['count'] > 0)<a href="#recipe-reviews"><span aria-hidden="true">★</span> {{ number_format($ratingSummary['average'], 1) }} / 5 · {{ __('recipe_detail.reviews', ['count' => $ratingSummary['count']]) }}</a>@endif</div>
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
                @if ($recipe->derived_from_recipe_id !== null)
                    @if (auth()->id() === $recipe->author_id && ! $isPublic)
                        @livewire('variation-publication-status', ['recipeId' => $recipe->id, 'locale' => $locale])
                    @endif
                    @include('recipes.partials.variation-attribution', ['attribution' => app(\App\Services\Recipes\RecipeVariationAttribution::class)->present($recipe, auth()->user(), $locale)])
                    @can('editVariation', $recipe)
                        <p><a class="button" href="{{ route('recipes.variations.edit', ['locale' => $locale, 'recipe' => $recipe->id], false) }}">{{ __('variations.edit') }}</a></p>
                    @endcan
                @endif
                @if (config('recipe-variations.enabled'))
                    @can('fork', $recipe)
                        @livewire('make-recipe-variation', ['recipeId' => $recipe->id, 'locale' => $locale], key('fork-'.$recipe->id))
                    @endcan
                @endif
                <div class="recipe-actions">
                    @if ($showJump)
                        <a class="button" href="#recipe-content" data-jump-to-recipe>{{ __('site.jump_to_recipe') }}</a>
                    @endif
                    @if ($steps !== [])
                        <button class="button button-dark" type="button" data-cooking-start hidden>{{ __('site.cooking_mode_start') }}</button>
                    @endif
                    <a class="button" href="{{ $printUrl }}" target="_blank" rel="noopener" data-print-link>{{ __('site.print_recipe') }}</a>
                    <a class="button" href="#recipe-planning"><x-ui.icon name="calendar" />{{ __('recipe_detail.add_plan') }}</a>
                </div>
                @auth
                    @if ($isPublic && auth()->user()->hasVerifiedEmail())
                        <form class="save-recipe-form" method="post" action="{{ route('recipes.save', ['locale' => $locale, 'recipe' => $recipe], false) }}">
                            @csrf
                            <button class="button button-dark" type="submit"><span aria-hidden="true">&#9825;</span> {{ auth()->user()->favoriteRecipes()->whereKey($recipe->id)->exists() ? __('site.remove_saved') : __('site.save_recipe') }}</button>
                        </form>
                    @elseif (! auth()->user()->hasVerifiedEmail())
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
                        {{ $recipe->title }}
                    @endif
                </figcaption>
            </figure>
        </header>
        <nav class="recipe-section-nav" aria-label="{{ __('recipe_detail.recipe_navigation') }}"><a href="#ingredients">{{ __('site.ingredients') }}</a><a href="#method-title">{{ __('site.method_title') }}</a><a href="#recipe-planning">{{ __('recipe_detail.open_plan') }}</a>@if ($isPublic)<a href="#recipe-reviews">{{ __('site.rating_title') }}</a>@endif</nav>
        <div class="recipe-content" id="recipe-content" tabindex="-1">
            @livewire('recipe-servings', ['recipeId' => $recipe->id, 'locale' => $locale, 'baseServings' => $baseServings])
            <section class="method-panel" aria-labelledby="method-title">
                <span class="eyebrow"><span class="eyebrow-dot"></span> {{ __('site.step_eyebrow') }}</span>
                <h2 id="method-title">{{ __('site.method_title') }}</h2>
                @if ($introductionHtml !== '')
                    <div class="recipe-description">{!! $introductionHtml !!}</div>
                @endif
                @php
                    $stepSections ??= [['title' => null, 'description' => null, 'steps' => $steps]];
                @endphp
                @foreach ($stepSections as $section)
                    @if ($section['title'])
                        <div class="method-group-heading">
                            <h3>{{ $section['title'] }}</h3>
                            @if ($section['description'])<p>{{ $section['description'] }}</p>@endif
                        </div>
                    @endif
                    <ol class="method-list">
                        @foreach ($section['steps'] as $step)
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
                @endforeach
            </section>
        </div>
        @if ($steps !== [])
            <div class="cooking-overlay" role="dialog" aria-modal="true" aria-labelledby="cooking-title" data-cooking-overlay data-session-enabled="{{ config('cooking.sessions_enabled') ? 'true' : 'false' }}" data-session-user="{{ auth()->user()?->hasVerifiedEmail() ? auth()->id() : '' }}" data-page-hash="{{ $cookingPageHash ?? '' }}" data-recipe-id="{{ $recipe->id }}" data-state-version="{{ $recipe->current_revision_id ?? $recipe->updated_at?->getTimestamp() }}" data-total="{{ count($steps) }}" hidden
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
                @php
                    $cookingLabels = ['manualComponent' => __('nested_public.manual'), 'prepared' => __('cooking.prepared'), 'viewComponent' => __('cooking.viewComponent'), 'loading' => __('cooking.loading'), 'saved' => __('cooking.saved'), 'saving' => __('cooking.saving'), 'saveError' => __('cooking.saveError'), 'unavailable' => __('cooking.unavailable'), 'conflict' => __('cooking.conflict'), 'wait' => __('cooking.wait'), 'continueStep' => __('cooking.continueStep'), 'reopen' => __('cooking.reopen'), 'pause' => __('cooking.pause'), 'resume' => __('cooking.resume'), 'restart' => __('cooking.restart'), 'paused' => __('cooking.paused'), 'finished' => __('cooking.finished'), 'goToStep' => __('cooking.goToStep'), 'groupProgress' => __('cooking.groupProgress'), 'groupWaiting' => __('cooking.groupWaiting'), 'groupTimers' => __('cooking.groupTimers'), 'soundOff' => __('cooking.soundOff'), 'soundOn' => __('cooking.soundOn'), 'again' => __('cooking.again'), 'servings' => __('cooking.servings'), 'state_not_started' => __('cooking.state_not_started'), 'state_in_progress' => __('cooking.state_in_progress'), 'state_waiting' => __('cooking.state_waiting'), 'state_completed' => __('cooking.state_completed'), 'missingTranslation' => __('cooking.missingTranslation'), 'wakeFailed' => __('cooking.wakeFailed'), 'optional' => __('cooking.optional'), 'componentSteps' => __('cooking.componentSteps'), 'resetIngredients' => __('cooking.resetIngredients'), 'ingredientsTitle' => __('cooking.ingredientsTitle'), 'videoTitle' => __('cooking.videoTitle'), 'ungrouped' => __('cooking.ungrouped'), 'stepLabel' => __('site.step_label', ['number' => '{number}'])];
                    $cookingUrls = [
                        'content' => route('cooking-sessions.content', ['locale' => $locale, 'recipe' => $recipe->id], false),
                        'start' => route('cooking-sessions.store', ['locale' => $locale, 'recipe' => $recipe->id], false),
                        'latest' => route('cooking-sessions.latest', ['locale' => $locale, 'recipe' => $recipe->id], false),
                        'session' => route('cooking-sessions.show', ['locale' => $locale, 'session' => '__SESSION__'], false),
                    ];
                @endphp
                <script type="application/json" data-cooking-labels>{!! json_encode($cookingLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
                <script type="application/json" data-cooking-urls>{!! json_encode($cookingUrls, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
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
                            <button class="button" type="button" data-cooking-sound aria-pressed="false">{{ __('cooking.soundOff') }}</button>
                            <button class="button button-dark" type="button" data-cooking-close>{{ __('site.cooking_mode_close') }}</button>
                        </div>
                    </header>
                    <div class="cooking-progress">
                        <span data-cooking-progress aria-live="polite"></span>
                        <span data-cooking-done-count></span>
                        <div class="cooking-bar" role="progressbar" aria-label="{{ __('site.cooking_mode_title') }}" aria-valuemin="1" aria-valuemax="{{ count($steps) }}" aria-valuenow="1" data-cooking-bar><span></span></div>
                    </div>
                    <div class="cooking-timers" data-cooking-timers hidden></div>
                    <button class="button" type="button" data-cooking-context-back hidden>{{ __('nested_public.return_parent') }}</button>
                    <p class="cooking-note" role="status" data-cooking-status></p>
                    <p class="cooking-note cooking-alert" role="alert" data-cooking-alert></p>
                    <div class="cooking-body">
                        <details class="cooking-groups" data-cooking-groups data-cooking-interactive open hidden>
                            <summary>{{ __('cooking.groups') }} · <span data-cooking-group-current></span></summary>
                            <div class="cooking-group-list" data-cooking-group-list></div>
                        </details>
                        <div class="cooking-steps">
                            @include('recipes.partials.components', ['cooking' => true])
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
                            <label data-cooking-servings-control hidden>{{ __('cooking.servings') }} <input data-cooking-servings type="number" min="0.01" max="1000" step="0.01" inputmode="decimal"></label>
                            <div data-cooking-ingredients data-cooking-interactive></div>
                        </details>
                    </div>
                    <nav class="cooking-nav" aria-label="{{ __('site.cooking_mode_title') }}">
                        <button class="button" type="button" data-cooking-previous>{{ __('site.cooking_mode_previous') }}</button>
                        <button class="button" type="button" data-cooking-again hidden>{{ __('cooking.again') }}</button>
                        <button class="button button-dark" type="button" data-cooking-next>{{ __('site.cooking_mode_next') }}</button>
                    </nav>
                    <p class="cooking-note">{{ __('site.timer_background_note') }}</p><p class="cooking-note" data-wake-status role="status">{{ __('recipe_detail.wake_unavailable') }}</p>
                </div>
            </div>
        @endif
        @livewire('recipe-planning', ['recipeId' => $recipe->id, 'locale' => $locale], key('recipe-planning-'.$recipe->id))
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
            <div id="recipe-reviews">
            @livewire('recipe-ratings', ['recipeId' => $recipe->id, 'locale' => $locale], key('recipe-ratings-'.$recipe->id))
            </div>
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
