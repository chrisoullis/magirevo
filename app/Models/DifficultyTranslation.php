<?php

namespace App\Models;

use Database\Factories\DifficultyTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DifficultyTranslation extends Model
{
    /** @use HasFactory<DifficultyTranslationFactory> */
    use HasFactory;

    protected $fillable = [
        'locale',
        'name',
        'description',
    ];

    public function difficulty(): BelongsTo
    {
        return $this->belongsTo(Difficulty::class);
    }
}
