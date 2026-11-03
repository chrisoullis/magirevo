<?php

namespace App\Models;

use Astrotomic\Translatable\Translatable;
use Database\Factories\IngredientCatalogGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IngredientCatalogGroup extends Model
{
    /** @use HasFactory<IngredientCatalogGroupFactory> */
    use HasFactory;

    use Translatable;

    protected $fillable = ['code', 'is_active', 'sort_order'];

    public array $translatedAttributes = ['name', 'description'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class, 'catalog_group_id');
    }

    public function isInUse(): bool
    {
        return $this->ingredients()->exists();
    }
}
