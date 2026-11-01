<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A usage entitlement bundle. NULL limits are unlimited; zero means the capability is not allowed.
 * Billing is deliberately absent: plans never decide permissions or recipe access.
 */
class Plan extends Model
{
    public const FREE = 'free';

    protected $fillable = ['code', 'is_active', 'max_personal_recipes', 'max_active_shares_per_recipe', 'max_storage_mb'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'max_personal_recipes' => 'integer',
            'max_active_shares_per_recipe' => 'integer',
            'max_storage_mb' => 'integer',
        ];
    }

    public static function free(): self
    {
        return self::query()->firstOrCreate(['code' => self::FREE], [
            'is_active' => true,
            'max_personal_recipes' => 20,
            'max_active_shares_per_recipe' => 5,
        ]);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(UserEntitlement::class);
    }
}
