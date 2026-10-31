<?php

namespace App\Models;

use Database\Factories\TagTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TagTranslation extends Model
{
    /** @use HasFactory<TagTranslationFactory> */
    use HasFactory;

    protected $fillable = [
        'locale',
        'name',
        'slug',
        'description',
    ];

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
