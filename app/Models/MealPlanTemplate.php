<?php

namespace App\Models;

use Database\Factories\MealPlanTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealPlanTemplate extends Model
{
    /** @use HasFactory<MealPlanTemplateFactory> */
    use HasFactory;

    protected $attributes = ['version' => 1];

    protected $fillable = ['household_id', 'created_by', 'name', 'version'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function isPersonal(): bool
    {
        return $this->household_id === null;
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(MealPlanTemplateEntry::class);
    }
}
