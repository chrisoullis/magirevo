<?php

namespace App\Http\Controllers;

use App\Models\RecipeReusePermission;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecipeRightsEvidenceController extends Controller
{
    public function __invoke(RecipeReusePermission $permission): StreamedResponse
    {
        Gate::authorize('view', $permission);
        $path = $permission->evidence_path;
        abort_unless(is_string($path) && $path !== '' && ! str_starts_with($path, '/') && ! preg_match('~(^|[/\\\\])\.\.([/\\\\]|$)~', $path), 404);
        $disk = Storage::disk('recipe_rights');
        abort_unless($disk->exists($path), 404);
        $stream = $disk->readStream($path);
        abort_unless(is_resource($stream), 404);
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        if (! hash_equals((string) $permission->evidence_sha256, hash_final($hash))) {
            fclose($stream);
            abort(409, 'The evidence file no longer matches the retained checksum.');
        }
        rewind($stream);

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 'rights-evidence-'.$permission->id.'.bin', [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
