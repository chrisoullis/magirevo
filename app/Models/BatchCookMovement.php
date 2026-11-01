<?php

namespace App\Models;

use App\Enums\BatchMovementType;
use Database\Factories\BatchCookMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchCookMovement extends Model
{
    /** @use HasFactory<BatchCookMovementFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['batch_cook_id', 'type', 'portions', 'from_reserved', 'meal_plan_entry_id', 'user_id', 'idempotency_key'];

    protected function casts(): array
    {
        return ['type' => BatchMovementType::class, 'portions' => 'integer', 'from_reserved' => 'boolean'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BatchCook::class, 'batch_cook_id');
    }
}
