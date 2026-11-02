<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history of revision decisions. PostgreSQL enforces the same rule with a trigger.
 */
class RecipeRevisionAudit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'recipe_revision_id',
        'recipe_id',
        'actor_id',
        'action',
        'from_status',
        'to_status',
        'note',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Recipe revision audit records are append-only.'));
        static::deleting(fn () => throw new LogicException('Recipe revision audit records are append-only.'));
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(RecipeRevision::class, 'recipe_revision_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
