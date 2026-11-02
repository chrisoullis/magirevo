<?php

namespace App\Models;

use App\Enums\DietaryClaimAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history of claim decisions. The application refuses updates and deletes, and PostgreSQL
 * triggers enforce the same rule at the database level.
 */
class DietaryClaimAudit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'dietary_claim_id',
        'recipe_id',
        'recipe_revision_id',
        'tag_id',
        'action',
        'previous_status',
        'new_status',
        'actor_id',
        'verification_method',
        'evidence_reference',
        'notes',
        'revision_hash',
        'rule_version',
    ];

    protected function casts(): array
    {
        return [
            'action' => DietaryClaimAction::class,
            'evidence_reference' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Dietary claim audit records are append-only.'));
        static::deleting(fn () => throw new LogicException('Dietary claim audit records are append-only.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(DietaryClaim::class, 'dietary_claim_id');
    }
}
