<?php

namespace App\Models;

use App\Enums\DietaryClaimStatus;
use App\Enums\DietaryVerificationMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dietary claim for one recipe. It is bound to a content fingerprint and a rule version; changing the
 * state of a claim goes through DietaryClaimService so every transition is audited.
 */
class DietaryClaim extends Model
{
    protected $table = 'recipe_dietary_claims';

    protected $fillable = [
        'recipe_id',
        'recipe_revision_id',
        'tag_id',
        'status',
        'suggested_by',
        'reviewed_by',
        'reviewed_at',
        'verification_method',
        'evidence',
        'revision_hash',
        'rule_version',
        'review_notes',
        'suspended_at',
        'suspension_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => DietaryClaimStatus::class,
            'verification_method' => DietaryVerificationMethod::class,
            'evidence' => 'array',
            'reviewed_at' => 'datetime',
            'suspended_at' => 'datetime',
            'rule_version' => 'integer',
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

    /**
     * What the claim is verified against: the live recipe while its revision is the current published
     * content (or before any revision exists), otherwise the frozen payload of its own revision.
     */
    public function contentSubject(): Recipe|RecipeRevision
    {
        $recipe = $this->recipe;

        if ($this->recipe_revision_id === null || (int) $recipe->current_revision_id === (int) $this->recipe_revision_id) {
            return $recipe;
        }

        return $this->revision;
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function suggester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suggested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(DietaryClaimAudit::class)->orderBy('id');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', DietaryClaimStatus::Approved->value);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', DietaryClaimStatus::Suggested->value);
    }

    /**
     * Optional reminder support only; approvals never expire by date.
     */
    public function scopeDueForReminder(Builder $query): Builder
    {
        $months = config('dietary.review_reminder_months');

        return $months === null
            ? $query->whereRaw('1 = 0')
            : $query->approved()->where('reviewed_at', '<=', now()->subMonths((int) $months));
    }

    public function isHighRisk(): bool
    {
        return $this->tag->classification->isHighRisk()
            || (bool) config("dietary.claims.{$this->tag->code}.high_risk", false);
    }

    public function currentRuleVersion(): int
    {
        return (int) config("dietary.claims.{$this->tag->code}.rule_version", config('dietary.default_rule_version', 1));
    }
}
