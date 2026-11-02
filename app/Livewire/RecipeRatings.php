<?php

namespace App\Livewire;

use App\Models\Recipe;
use App\Models\RecipeRating;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RecipeRatings extends Component
{
    #[Locked]
    public int $recipeId;

    #[Locked]
    public string $locale = 'en';

    public int $rating = 0;

    public ?string $reviewText = null;

    public ?int $reportRatingId = null;

    public string $reportReason = '';

    public function mount(int $recipeId, string $locale): void
    {
        abort_unless(in_array($locale, config('translatable.locales'), true), 404);
        $this->recipeId = $recipeId;
        $this->locale = $locale;

        $existingRating = $this->currentUser()?->recipeRatings()
            ->where('recipe_id', $this->recipeId)
            ->first();

        if ($existingRating !== null) {
            $this->rating = $existingRating->rating;
            $this->reviewText = $existingRating->review_text;
        }
    }

    public function save(): void
    {
        $recipe = $this->recipe();
        $user = $this->user();
        $existingRating = $user->recipeRatings()->where('recipe_id', $recipe->getKey())->first();

        if ($existingRating === null) {
            Gate::authorize('create', [RecipeRating::class, $recipe]);
            $existingRating = new RecipeRating;
        } else {
            Gate::authorize('update', $existingRating);
        }

        $validated = $this->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'reviewText' => ['nullable', 'string', 'max:3000'],
        ]);

        $reviewText = filled($validated['reviewText']) ? trim($validated['reviewText']) : null;
        $reviewChanged = ! $existingRating->exists || $existingRating->review_text !== $reviewText;

        $existingRating->fill([
            'rating' => $validated['rating'],
            'review_text' => $reviewText,
        ]);

        if ($reviewChanged) {
            $existingRating->review_status = $reviewText === null ? 'published' : 'pending';
        }

        $existingRating->recipe()->associate($recipe);
        $existingRating->user()->associate($user);
        $existingRating->save();

        session()->flash('recipe-rating-status', __('site.rating_saved'));
    }

    public function prepareReport(int $ratingId): void
    {
        $this->user();

        $rating = $this->recipe()->ratings()
            ->active()
            ->publishedReview()
            ->findOrFail($ratingId);

        Gate::authorize('report', $rating);
        $this->reportRatingId = $rating->getKey();
        $this->reportReason = '';
    }

    public function reportAbuse(): void
    {
        $recipe = $this->recipe();
        $user = $this->user();

        $validated = $this->validate([
            'reportRatingId' => ['required', 'integer', 'min:1'],
            'reportReason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $rating = $recipe->ratings()
            ->active()
            ->publishedReview()
            ->findOrFail($validated['reportRatingId']);

        Gate::authorize('report', $rating);
        $report = $rating->reports()->firstOrCreate(
            ['reporter_id' => $user->getKey()],
            ['reason' => trim($validated['reportReason'])],
        );

        $this->reset('reportRatingId', 'reportReason');
        session()->flash('recipe-rating-status', $report->wasRecentlyCreated
            ? __('site.rating_reported')
            : __('site.rating_report_already_sent'));
    }

    public function render(): View
    {
        app()->setLocale($this->locale);

        $recipe = $this->recipe();
        $summary = $recipe->ratings()
            ->active()
            ->selectRaw('COUNT(*) AS rating_count, AVG(rating) AS average_rating')
            ->first();
        $user = $this->currentUser();
        $existingRating = $user?->recipeRatings()->where('recipe_id', $recipe->getKey())->first();

        $reviews = $recipe->ratings()
            ->active()
            ->publishedReview()
            ->when($user, fn ($query) => $query->withExists([
                'reports as reported_by_viewer' => fn ($reports) => $reports->where('reporter_id', $user->getKey()),
            ]))
            ->latest('created_at')
            ->limit(20)
            ->get();

        return view('livewire.recipe-ratings', [
            'averageRating' => $summary->average_rating === null ? null : (float) $summary->average_rating,
            'ratingCount' => (int) $summary->rating_count,
            'reviews' => $reviews,
            'canRate' => $user !== null && ($existingRating !== null
                ? Gate::forUser($user)->allows('update', $existingRating)
                : Gate::forUser($user)->allows('create', [RecipeRating::class, $recipe])),
        ]);
    }

    private function recipe(): Recipe
    {
        $recipe = Recipe::query()->findOrFail($this->recipeId);
        abort_unless($recipe->isPubliclyAccessible(), 404);

        return $recipe;
    }

    private function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function user(): User
    {
        $user = $this->currentUser();
        abort_unless($user !== null, 403);

        return $user;
    }
}
