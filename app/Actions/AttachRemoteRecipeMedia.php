<?php

namespace App\Actions;

use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AttachRemoteRecipeMedia
{
    public function __construct(private CreateRemoteMedia $createRemoteMedia) {}

    /**
     * @param  array{en: array{alt_text: string, caption?: ?string}, el: array{alt_text: string, caption?: ?string}}  $translations
     */
    public function handle(Recipe $recipe, string $url, array $translations, string $role = 'gallery', ?string $credit = null, ?string $creditUrl = null): Media
    {
        Validator::make(['role' => $role], ['role' => ['required', Rule::in(['cover', 'gallery'])]])->validate();

        return $this->createRemoteMedia->handle($url, $translations, $credit, $creditUrl, function (Media $media) use ($recipe, $role): void {
            if ($role === 'cover') {
                $coverIds = $recipe->media()->wherePivot('role', 'cover')->pluck('media.id')->all();
                $recipe->media()->updateExistingPivot($coverIds, ['role' => 'gallery']);
            }

            $recipe->media()->attach($media, [
                'role' => $role,
                'sort_order' => $recipe->media()->count(),
            ]);
        });
    }
}
