<?php

namespace App\Models;

use Database\Factories\RecipeStepTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeStepTranslation extends Model
{
    /** @use HasFactory<RecipeStepTranslationFactory> */
    use HasFactory;

    protected $fillable = [
        'locale',
        'instruction',
    ];

    public function recipeStep(): BelongsTo
    {
        return $this->belongsTo(RecipeStep::class);
    }
}
