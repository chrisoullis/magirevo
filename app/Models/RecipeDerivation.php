<?php

namespace App\Models;

use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Original provenance is written explicitly by the fork service and frozen by PostgreSQL. */
class RecipeDerivation extends Model
{
    use Translatable;

    protected $guarded = ['*'];

    // Evidence and historical source metadata must only be exposed through authorized presenters.
    protected $hidden = [
        'source_title_snapshot', 'source_author_snapshot', 'source_url_snapshot',
        'source_license_snapshot', 'attribution_snapshot', 'source_rights_basis',
        'source_permission_reference', 'source_permission_evidence_id',
        'idempotency_key', 'request_fingerprint',
    ];

    public array $translatedAttributes = ['change_summary'];

    protected function casts(): array
    {
        return [
            'source_license_snapshot' => 'array',
            'attribution_snapshot' => 'array',
            'provenance_version' => 'integer',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class)->withTrashed();
    }

    public function sourceRevision(): BelongsTo
    {
        return $this->belongsTo(RecipeRevision::class, 'source_recipe_revision_id');
    }

    public function permissionEvidence(): BelongsTo
    {
        return $this->belongsTo(RecipeReusePermission::class, 'source_permission_evidence_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
