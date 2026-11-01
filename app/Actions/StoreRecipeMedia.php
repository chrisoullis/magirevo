<?php

namespace App\Actions;

use App\Models\Media;
use App\Models\Recipe;
use App\Services\RecipeMediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class StoreRecipeMedia
{
    public function __construct(private StoreMedia $storeMedia, private RecipeMediaStorage $storage) {}

    /**
     * @param  array{en: array{alt_text: string, caption?: ?string}, el: array{alt_text: string, caption?: ?string}}  $translations
     */
    public function handle(Recipe $recipe, UploadedFile $file, array $translations, string $role = 'gallery'): Media
    {
        Validator::make(['role' => $role], ['role' => ['required', Rule::in(['cover', 'gallery'])]])->validate();

        return $this->storeMedia->handle(
            $file,
            $translations,
            'recipe-media',
            fn (Media $media) => $recipe->media()->attach($media, [
                'role' => $role,
                'sort_order' => $recipe->media()->count(),
            ]),
            $this->storage->diskFor($recipe),
        );
    }
}
