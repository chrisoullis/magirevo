<?php

namespace App\Models;

use App\Enums\HouseholdRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class HouseholdMember extends Pivot
{
    public $incrementing = true;

    protected $table = 'household_members';

    protected $fillable = ['household_id', 'user_id', 'role', 'joined_at'];

    protected function casts(): array
    {
        return [
            'role' => HouseholdRole::class,
            'joined_at' => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
