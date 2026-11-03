<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class IngredientMedia extends Pivot
{
    protected $table = 'ingredient_media';

    public $incrementing = true;

    protected $fillable = [
        'ingredient_id', 'media_id', 'thumbnail_media_id', 'role', 'sort_order',
        'rights_basis', 'rights_reference', 'rights_confirmed_by', 'rights_confirmed_at',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'rights_confirmed_at' => 'datetime'];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function thumbnail(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'thumbnail_media_id');
    }

    public function rightsConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rights_confirmed_by');
    }

    public function scopePubliclyUsable(Builder $query): Builder
    {
        return $query->whereIn('rights_basis', ['own_work', 'licensed', 'permission', 'public_domain'])
            ->whereNotNull('rights_confirmed_by')->whereNotNull('rights_confirmed_at')->whereRaw('length(btrim(rights_reference)) > 0')
            ->whereHas('ingredient', fn (Builder $ingredient) => $ingredient->where(fn (Builder $visible) => $visible
                ->where(fn (Builder $en) => $en->publiclyVisible('en'))->orWhere(fn (Builder $el) => $el->publiclyVisible('el'))))
            ->whereHas('media', fn (Builder $media) => $media->ingredientLibrary()
                ->whereHas('translations', fn ($t) => $t->where('locale', 'en')->whereRaw('length(btrim(alt_text)) > 0'))
                ->whereHas('translations', fn ($t) => $t->where('locale', 'el')->whereRaw('length(btrim(alt_text)) > 0')))
            ->where(fn (Builder $image) => $image->whereNull('thumbnail_media_id')
                ->orWhereHas('thumbnail', fn (Builder $thumbnail) => $thumbnail->ingredientLibrary(thumbnail: true)));
    }

    public function publicVersion(string $variant = 'display'): string
    {
        $media = $variant === 'thumbnail' ? ($this->thumbnail ?? $this->media) : $this->media;

        return substr(hash('sha256', json_encode([$this->getKey(), $variant, $this->getRawOriginal('updated_at'), $this->rights_basis, $this->rights_reference, $this->rights_confirmed_by, $this->rights_confirmed_at, $media->id, $media->file_path, $media->getRawOriginal('updated_at')])), 0, 32);
    }

    public function publicUrl(string $variant = 'display'): string
    {
        return route('ingredients.image', ['image' => $this->id, 'variant' => $variant, 'version' => $this->publicVersion($variant)], false);
    }
}
