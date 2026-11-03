<?php

namespace App\Models;

use App\Services\RecipeMediaStorage;
use Astrotomic\Translatable\Translatable;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    use Translatable;

    protected $table = 'media';

    protected $fillable = [
        'disk',
        'media_type',
        'source',
        'file_path',
        'url',
        'credit',
        'credit_url',
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

    public const REMOTE_DISK = 'remote';

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    public const SOURCE_LOCAL = 'local';

    public const SOURCE_REMOTE = 'remote';

    public const SOURCE_EMBED = 'embed';

    public function isRemote(): bool
    {
        return $this->source === self::SOURCE_REMOTE;
    }

    public function isLocal(): bool
    {
        return $this->source === self::SOURCE_LOCAL;
    }

    public function isEmbed(): bool
    {
        return $this->source === self::SOURCE_EMBED;
    }

    public function isPrivate(): bool
    {
        return $this->isLocal() && $this->disk !== RecipeMediaStorage::PUBLIC_DISK;
    }

    /**
     * Direct URL for public and remote images. Private files are only reachable through the authorized media route,
     * which never exposes the storage path.
     */
    public function publicUrl(): string
    {
        if ($this->isRemote() || $this->isEmbed()) {
            return (string) $this->url;
        }

        return $this->isPrivate()
            ? route('media.show', ['media' => $this->getKey()], false)
            : Storage::disk($this->disk)->url((string) $this->file_path);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function isInUse(): bool
    {
        return $this->recipes()->exists()
            || $this->recipeSteps()->exists()
            || $this->categories()->exists()
            || $this->ingredientImages()->exists()
            || $this->ingredientThumbnails()->exists()
            || $this->isRetainedByRevision();
    }

    public function ingredientImages(): HasMany
    {
        return $this->hasMany(IngredientMedia::class, 'media_id');
    }

    public function ingredientThumbnails(): HasMany
    {
        return $this->hasMany(IngredientMedia::class, 'thumbnail_media_id');
    }

    public function scopeIngredientLibrary(Builder $query, bool $thumbnail = false): Builder
    {
        return $query->where('source', self::SOURCE_LOCAL)->where('disk', 'local')->where('media_type', self::TYPE_IMAGE)
            ->where('file_path', 'like', $thumbnail ? 'ingredient-thumbnails/%' : 'ingredient-media/%')
            ->whereDoesntHave('recipes')->whereDoesntHave('recipeSteps')->whereDoesntHave('categories')
            ->whereNotIn('id', RecipeRevisionMedia::query()->select('media_id'));
    }

    /**
     * Media shown by any retained recipe revision, current or historical, must stay available.
     */
    public function isRetainedByRevision(): bool
    {
        return RecipeRevisionMedia::query()->where('media_id', $this->getKey())->exists();
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
            ->withPivot(['kind', 'sort_order'])
            ->withTimestamps();
    }
}
