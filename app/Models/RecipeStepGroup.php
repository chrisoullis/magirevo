<?php

namespace App\Models;

use Astrotomic\Translatable\Translatable;
use Database\Factories\RecipeStepGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipeStepGroup extends Model
{
    /** @use HasFactory<RecipeStepGroupFactory> */
    use HasFactory;

    use Translatable;

    protected $fillable = ['recipe_id', 'position'];

    public array $translatedAttributes = ['title', 'description'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(RecipeStep::class)->orderBy('step_number')->orderBy('id');
    }
}
