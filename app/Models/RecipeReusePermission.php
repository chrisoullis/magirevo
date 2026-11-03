<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only rights decisions; a new decision creates a new version. Suspension is permanent for that version. */
class RecipeReusePermission extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['evidence_path', 'evidence_sha256', 'permission_reference', 'review_notes', 'suspension_reason'];

    protected function casts(): array
    {
        return [
            'license_conditions' => 'array',
            'allows_text_reproduction' => 'boolean',
            'allows_private_adaptation' => 'boolean',
            'allows_public_adaptation' => 'boolean',
            'allows_sharing' => 'boolean',
            'allows_commercial_use' => 'boolean',
            'allows_public_attribution' => 'boolean',
            'requires_attribution' => 'boolean',
            'requires_share_alike' => 'boolean',
            'prohibits_derivatives' => 'boolean',
            'reviewed_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class)->withTrashed();
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(RecipeRevision::class, 'recipe_revision_id');
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
