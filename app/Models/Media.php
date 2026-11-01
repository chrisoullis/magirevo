<?php

namespace App\Models;

use App\Services\RecipeMediaStorage;
use Astrotomic\Translatable\Translatable;
use Database\Factories\MediaFactory;
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

    public function isRemote(): bool
    {
        return $this->disk === self::REMOTE_DISK;
    }

    public function isPrivate(): bool
    {
        return ! $this->isRemote() && $this->disk !== RecipeMediaStorage::PUBLIC_DISK;
    }

    /**
     * Direct URL for public and remote images. Private files are only reachable through the authorized media route,
     * which never exposes the storage path.
     */
    public function publicUrl(): string
    {
        if ($this->isRemote()) {
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
            || $this->categories()->exists();
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
