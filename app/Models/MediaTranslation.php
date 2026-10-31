<?php

namespace App\Models;

use Database\Factories\MediaTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaTranslation extends Model
{
    /** @use HasFactory<MediaTranslationFactory> */
    use HasFactory;

    protected $fillable = [
        'locale',
        'alt_text',
        'caption',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
