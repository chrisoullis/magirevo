<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeRevisionDependency extends Model
{
    protected $fillable = ['recipe_revision_id', 'line_uuid', 'component_recipe_id', 'component_recipe_revision_id'];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(RecipeRevision::class, 'recipe_revision_id');
    }

    public function componentRecipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'component_recipe_id')->withTrashed();
    }

    public function componentRevision(): BelongsTo
    {
        return $this->belongsTo(RecipeRevision::class, 'component_recipe_revision_id');
    }
}
