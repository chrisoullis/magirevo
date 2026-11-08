<?php

namespace App\Actions;

use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Services\RecipeMediaStorage;
use App\Services\RecipeStepImageOptimizer;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

class StoreRecipeStepImage
{
    public function __construct(
        private StoreMedia $storeMedia,
        private RecipeMediaStorage $storage,
        private RecipeStepImageOptimizer $optimizer,
    ) {}

    /**
     * @param  array{en: array{alt_text: string, caption: ?string}, el: array{alt_text: string, caption: ?string}}  $translations
     */
    public function handle(Recipe $recipe, RecipeStep $step, UploadedFile $file, array $translations, int $sortOrder): Media
    {
        return $this->store($file, $translations,
            fn (Media $media) => $step->media()->attach($media, ['kind' => 'image', 'sort_order' => $sortOrder]),
            $this->storage->diskFor($recipe),
        );
    }

    /** Stores optimized step media; the caller authorizes and attaches it inside the callback. */
    public function store(UploadedFile $file, array $translations, Closure $within, string $disk): Media
    {
        Validator::make(['file' => $file], [
            'file' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:10240',
                Rule::dimensions()->maxWidth(6000)->maxHeight(6000),
            ],
        ])->validate();

        $temporaryPath = $this->optimizer->optimize($file);

        try {
            $optimizedFile = new UploadedFile($temporaryPath, 'step-image.webp', 'image/webp', UPLOAD_ERR_OK, true);

            return $this->storeMedia->handle(
                $optimizedFile,
                $translations,
                'recipe-step-media',
                $within,
                $disk,
            );
        } finally {
            if (is_file($temporaryPath) && ! unlink($temporaryPath)) {
                throw new RuntimeException('Unable to remove the temporary optimized step image.');
            }
        }
    }
}
