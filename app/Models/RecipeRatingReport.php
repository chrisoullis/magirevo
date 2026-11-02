<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeRatingReport extends Model
{
    protected $fillable = [
        'reporter_id',
        'reason',
    ];

    public function rating(): BelongsTo
    {
        return $this->belongsTo(RecipeRating::class, 'recipe_rating_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
