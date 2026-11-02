<div>
    <section class="recipe-ratings" aria-labelledby="recipe-ratings-title">
        <span class="eyebrow"><span class="eyebrow-dot"></span> {{ __('site.rating_eyebrow') }}</span>
        <h2 id="recipe-ratings-title">{{ __('site.rating_title') }}</h2>
        <p class="rating-summary">
            @if ($ratingCount > 0)
                <strong>{{ number_format($averageRating, 1, $locale === 'el' ? ',' : '.', '') }} / 5</strong>
                <span aria-label="{{ trans_choice('site.rating_count', $ratingCount, ['count' => $ratingCount]) }}">{{ trans_choice('site.rating_count', $ratingCount, ['count' => $ratingCount]) }}</span>
            @else
                {{ __('site.rating_none') }}
            @endif
        </p>

        @if (session()->has('recipe-rating-status'))
            <p class="rating-status" role="status">{{ session('recipe-rating-status') }}</p>
        @endif

        @if ($canRate)
            <form class="rating-form" wire:submit="save">
                <fieldset>
                    <legend>{{ __('site.rating_your_rating') }}</legend>
                    <div class="rating-stars">
                        @for ($star = 5; $star >= 1; $star--)
                            <label wire:key="rating-star-{{ $star }}">
                                <input type="radio" name="rating" wire:model="rating" value="{{ $star }}" required>
                                <span aria-hidden="true">&#9733;</span>
                                <span class="sr-only">{{ trans_choice('site.rating_stars', $star, ['count' => $star]) }}</span>
                            </label>
                        @endfor
                    </div>
                </fieldset>
                @error('rating')<p class="field-error">{{ $message }}</p>@enderror
                <label for="recipe-review">{{ __('site.rating_review_optional') }}</label>
                <textarea id="recipe-review" wire:model="reviewText" rows="4" maxlength="3000"></textarea>
                @error('reviewText')<p class="field-error">{{ $message }}</p>@enderror
                <button class="button button-dark" type="submit" wire:loading.attr="disabled">{{ __('site.rating_save') }}</button>
                <p class="rating-moderation-note">{{ __('site.rating_moderation_note') }}</p>
            </form>
        @elseif (! auth()->check())
            <p><a href="{{ route('login', [], false) }}">{{ __('site.rating_login') }}</a></p>
        @endif

        <div class="rating-reviews">
            <h3>{{ __('site.rating_reviews') }}</h3>
            @forelse ($reviews as $review)
                <article class="rating-review" wire:key="recipe-review-{{ $review->id }}">
                    <div class="rating-review-stars" aria-label="{{ trans_choice('site.rating_stars', $review->rating, ['count' => $review->rating]) }}">
                        @for ($star = 1; $star <= 5; $star++)
                            <span aria-hidden="true">{{ $star <= $review->rating ? '★' : '☆' }}</span>
                        @endfor
                    </div>
                    <p>{{ $review->review_text }}</p>
                    <time datetime="{{ $review->created_at->toIso8601String() }}">{{ $review->created_at->toDateString() }}</time>
                    @if (auth()->check() && (int) auth()->id() !== (int) $review->user_id && ! $review->reported_by_viewer)
                        @if ($reportRatingId !== $review->id)
                            <button class="rating-report-button" type="button" wire:click="prepareReport({{ $review->id }})">{{ __('site.rating_report') }}</button>
                        @else
                            <form class="rating-report-form" wire:submit="reportAbuse">
                                <input type="hidden" wire:model="reportRatingId">
                                <label for="rating-report-reason-{{ $review->id }}">{{ __('site.rating_report_reason') }}</label>
                                <textarea id="rating-report-reason-{{ $review->id }}" wire:model="reportReason" rows="3" maxlength="1000" required></textarea>
                                @error('reportReason')<p class="field-error">{{ $message }}</p>@enderror
                                <button class="button" type="submit" wire:loading.attr="disabled">{{ __('site.rating_report_submit') }}</button>
                            </form>
                        @endif
                    @endif
                </article>
            @empty
                <p>{{ __('site.rating_no_reviews') }}</p>
            @endforelse
        </div>
    </section>
</div>
