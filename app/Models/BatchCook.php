<?php

namespace App\Models;

use Database\Factories\BatchCookFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BatchCook extends Model
{
    /** @use HasFactory<BatchCookFactory> */
    use HasFactory;

    protected $attributes = ['version' => 1, 'reserved_portions' => 0, 'consumed_portions' => 0];

    protected $fillable = [
        'recipe_revision_id',
        'household_id', 'created_by', 'recipe_id', 'source_entry_id', 'cooked_on', 'prepared_portions',
        'reserved_portions', 'consumed_portions', 'notes', 'version',
    ];

    protected function casts(): array
    {
        return [
            'cooked_on' => 'immutable_date',
            'prepared_portions' => 'integer',
            'reserved_portions' => 'integer',
            'consumed_portions' => 'integer',
            'version' => 'integer',
        ];
    }

    public function isPersonal(): bool
    {
        return $this->household_id === null;
    }

    public function availablePortions(): int
    {
        return $this->prepared_portions - $this->reserved_portions - $this->consumed_portions;
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function sourceEntry(): BelongsTo
    {
        return $this->belongsTo(MealPlanEntry::class, 'source_entry_id');
    }

    public function leftovers(): HasMany
    {
        return $this->hasMany(MealPlanEntry::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(BatchCookMovement::class);
    }
}
