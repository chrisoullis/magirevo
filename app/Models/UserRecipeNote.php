<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRecipeNote extends Model
{
    protected $fillable = [
        'recipe_id',
        'body',
        'last_cooked_on',
    ];

    protected function casts(): array
    {
        return [
            'last_cooked_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
