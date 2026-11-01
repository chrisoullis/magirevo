<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEntitlement extends Model
{
    protected $fillable = ['user_id', 'plan_id', 'personal_recipe_limit_override', 'sharing_limit_override', 'granted_by'];

    protected function casts(): array
    {
        return [
            'personal_recipe_limit_override' => 'integer',
            'sharing_limit_override' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
