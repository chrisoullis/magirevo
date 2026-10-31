<?php

namespace App\Models;

use Database\Factories\UnitTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitTranslation extends Model
{
    /** @use HasFactory<UnitTranslationFactory> */
    use HasFactory;

    protected $fillable = [
        'locale',
        'name',
        'abbreviation',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
