<?php

namespace App\Models;

use App\Enums\ContributorRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContributorRequest extends Model
{
    protected $fillable = ['user_id', 'status', 'message', 'locale'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => ContributorRequestStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ContributorRequestStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === ContributorRequestStatus::Pending;
    }
}
