<?php

namespace App\Models;

use Database\Factories\CookingSessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CookingSession extends Model
{
    /** @use HasFactory<CookingSessionFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['state' => 'array', 'version' => 'integer', 'servings' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class)->withTrashed();
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(RecipeRevision::class, 'recipe_revision_id');
    }
}
