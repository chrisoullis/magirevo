<?php

namespace App\Http\Controllers;

use App\Models\IngredientMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IngredientImageController extends Controller
{
    public function show(Request $request, IngredientMedia $image, string $variant, string $version): StreamedResponse
    {
        abort_unless(IngredientMedia::query()->whereKey($image->id)->publiclyUsable()->exists(), 404);
        $image->load(['media', 'thumbnail']);
        abort_unless(hash_equals($image->publicVersion($variant), $version), 404);
        $media = $variant === 'thumbnail' ? ($image->thumbnail ?? $image->media) : $image->media;
        $disk = Storage::disk($media->disk);
        abort_unless($disk->exists($media->file_path), 404);
        $etag = '"'.hash('sha256', $version.':'.$disk->lastModified($media->file_path).':'.$disk->size($media->file_path)).'"';
        $response = $disk->response($media->file_path, null, [
            'Cache-Control' => 'public, max-age=0, must-revalidate',
            'ETag' => $etag,
            'Content-Type' => $media->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->isNotModified($request);

        return $response;
    }
}
