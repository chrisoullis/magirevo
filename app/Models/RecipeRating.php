<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipeRating extends Model
{
    protected $fillable = [
        'rating',
        'review_text',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(RecipeRatingReport::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('rating_status', 'active');
    }

    public function scopePublishedReview(Builder $query): Builder
    {
        return $query
            ->where('review_status', 'published')
            ->whereNotNull('review_text');
    }
}
