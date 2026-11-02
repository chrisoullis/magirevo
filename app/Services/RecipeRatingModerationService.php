<?php

namespace App\Services;

use App\Models\RecipeRating;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecipeRatingModerationService
{
    public function __construct(private AuditLogger $audit) {}

    public function moderateReview(User $moderator, RecipeRating $rating, string $status): void
    {
        Gate::forUser($moderator)->authorize('moderateReview', $rating);

        if (! in_array($status, ['pending', 'published', 'rejected'], true)) {
            throw ValidationException::withMessages(['review_status' => __('site.rating_moderation_invalid')]);
        }

        $rating->forceFill(['review_status' => $status])->save();
        $this->audit->record('recipe_review_moderated', [
            'recipe_rating_id' => $rating->getKey(),
            'review_status' => $status,
        ]);
    }

    public function removeRating(User $moderator, RecipeRating $rating, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['moderation_reason' => __('site.rating_moderation_reason_required')]);
        }

        DB::transaction(function () use ($moderator, $rating, $reason): void {
            $lockedRating = RecipeRating::query()->whereKey($rating->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($moderator)->authorize('remove', $lockedRating);

            $lockedRating->forceFill([
                'rating_status' => 'removed',
                'moderation_reason' => $reason,
            ])->save();

            $lockedRating->reports()->where('status', 'pending')->update([
                'status' => 'upheld',
                'updated_at' => now(),
            ]);

            $this->audit->record('recipe_rating_removed', [
                'recipe_rating_id' => $lockedRating->getKey(),
                'reason' => $reason,
            ]);
        });
    }

    public function dismissPendingReports(User $moderator, RecipeRating $rating): void
    {
        Gate::forUser($moderator)->authorize('moderateReports', $rating);

        $dismissed = $rating->reports()->where('status', 'pending')->update([
            'status' => 'dismissed',
            'updated_at' => now(),
        ]);

        if ($dismissed > 0) {
            $this->audit->record('recipe_rating_reports_dismissed', [
                'recipe_rating_id' => $rating->getKey(),
                'report_count' => $dismissed,
            ]);
        }
    }
}
