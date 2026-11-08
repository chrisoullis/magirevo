<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeStepGroupTranslation extends Model
{
    protected $fillable = ['locale', 'title', 'description'];

    public function recipeStepGroup(): BelongsTo
    {
        return $this->belongsTo(RecipeStepGroup::class);
    }
}
