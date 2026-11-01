<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MealPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealPlan extends Model
{
    /** @use HasFactory<MealPlanFactory> */
    use HasFactory;

    protected $attributes = ['version' => 1];

    protected $fillable = ['household_id', 'created_by', 'week_start', 'name', 'version'];

    protected function casts(): array
    {
        return ['week_start' => 'immutable_date', 'version' => 'integer'];
    }

    public static function weekStartFor(\DateTimeInterface|string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date)->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
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
        return $this->hasMany(MealPlanEntry::class);
    }
}
