<?php

namespace App\Models;

use App\Enums\RecipeRevisionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of a recipe's content. The payload is editable only while the revision is a draft; PostgreSQL freezes it
 * from submission onwards and refuses to delete retained history.
 */
class RecipeRevision extends Model
{
    protected $fillable = [
        'recipe_id',
        'revision_number',
        'status',
        'payload',
        'media_snapshot',
        'media_snapshot_hash',
        'resolved_ids',
        'schema_version',
        'content_hash',
        'base_revision_id',
        'restored_from_revision_id',
        'created_by',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'rejection_reason',
        'published_by',
        'published_at',
        'superseded_at',
    ];

    protected $hidden = ['publication_context', 'publication_context_hash'];

    protected function casts(): array
    {
        return [
            'status' => RecipeRevisionStatus::class,
            'payload' => 'array',
            'publication_context' => 'array',
            'media_snapshot' => 'array',
            'resolved_ids' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function baseRevision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_revision_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(RecipeRevisionAudit::class)->orderBy('id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(RecipeRevisionMedia::class);
    }
}
