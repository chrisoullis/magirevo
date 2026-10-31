<?php

namespace App\Models;

use Astrotomic\Translatable\Translatable;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    use Translatable;

    protected $table = 'media';

    protected $fillable = [
        'disk',
        'file_path',
        'mime_type',
        'file_size',
        'width',
        'height',
    ];

    public array $translatedAttributes = [
        'alt_text',
        'caption',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'recipe_media')
            ->withPivot(['role', 'sort_order'])
            ->withTimestamps();
    }

    public function recipeSteps(): BelongsToMany
    {
        return $this->belongsToMany(RecipeStep::class, 'recipe_step_media')
            ->withPivot('sort_order')
            ->withTimestamps();
    }
}
