<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use RuntimeException;

class RecipeStepImageOptimizer
{
    public function optimize(UploadedFile $file, int $maximumDimension = 1920): string
    {
        $source = $file->getRealPath();

        if (! is_string($source)) {
            throw new RuntimeException('Unable to read the uploaded step image.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'recipe-step-image-');

        if (! is_string($temporaryPath)) {
            throw new RuntimeException('Unable to create a temporary image file.');
        }

        try {
            ImageManager::usingDriver(Driver::class)
                ->decodePath($source)
                ->scaleDown(width: $maximumDimension, height: $maximumDimension)
                ->encode(new WebpEncoder(quality: 82))
                ->save($temporaryPath);
        } catch (\Throwable $exception) {
            if (! unlink($temporaryPath)) {
                throw new RuntimeException('Unable to remove the temporary image after optimization failed.', previous: $exception);
            }

            throw $exception;
        }

        return $temporaryPath;
    }
}
