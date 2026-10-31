<?php

namespace App\Models;

use Database\Factories\ScalingModeTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScalingModeTranslation extends Model
{
    /** @use HasFactory<ScalingModeTranslationFactory> */
    use HasFactory;

    protected $fillable = [
        'locale',
        'name',
        'description',
    ];

    public function scalingMode(): BelongsTo
    {
        return $this->belongsTo(ScalingMode::class);
    }
}
