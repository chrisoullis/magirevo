<?php

namespace App\Models;

use Astrotomic\Translatable\Translatable;
use Database\Factories\IngredientSubstitutionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class IngredientSubstitution extends Model
{
    /** @use HasFactory<IngredientSubstitutionFactory> */
    use HasFactory;

    use Translatable;

    protected $fillable = [
        'ingredient_id',
        'replacement_ingredient_id',
        'recipe_id',
        'status',
        'validated_revision_id',
        'validated_at',
    ];

    public array $translatedAttributes = [
        'explanation',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $substitution): void {
            $substitution->stampValidation();

            if ($substitution->recipe_id !== null
                && ! RecipeIngredient::query()
                    ->where('recipe_id', $substitution->recipe_id)
                    ->where('ingredient_id', $substitution->ingredient_id)
                    ->exists()) {
                throw ValidationException::withMessages([
                    'recipe_id' => __('site.substitution_recipe_ingredient_mismatch'),
                ]);
            }
        });
    }

    /**
     * Approval of a recipe-specific substitution is tied to the revision that is current when it is granted. Any
     * change to the pairing, recipe or status re-stamps it, and a later revision never inherits it.
     */
    private function stampValidation(): void
    {
        if ($this->recipe_id === null || $this->status !== 'approved') {
            $this->validated_revision_id = null;
            $this->validated_at = null;

            return;
        }

        if (! $this->exists || $this->isDirty(['status', 'recipe_id', 'ingredient_id', 'replacement_ingredient_id'])) {
            $this->validated_revision_id = Recipe::query()->whereKey($this->recipe_id)->value('current_revision_id');
            $this->validated_at = now();
        }
    }

    public function validatedRevision(): BelongsTo
    {
        return $this->belongsTo(RecipeRevision::class, 'validated_revision_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function replacementIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'replacement_ingredient_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopeApplicableToRecipe(Builder $query, int $recipeId): Builder
    {
        return $query->applicableToRecipes([$recipeId]);
    }

    /**
     * General substitutions plus recipe-specific ones validated for the recipe's current revision. A recipe that has
     * no revision yet only accepts unstamped rows.
     *
     * @param  list<int>  $recipeIds
     */
    public function scopeApplicableToRecipes(Builder $query, array $recipeIds): Builder
    {
        return $query->where(fn (Builder $applicable): Builder => $applicable
            ->whereNull('ingredient_substitutions.recipe_id')
            ->orWhere(fn (Builder $specific): Builder => $specific
                ->whereIn('ingredient_substitutions.recipe_id', $recipeIds)
                ->whereRaw('ingredient_substitutions.validated_revision_id IS NOT DISTINCT FROM (SELECT current_revision_id FROM recipes WHERE recipes.id = ingredient_substitutions.recipe_id)')));
    }
}
