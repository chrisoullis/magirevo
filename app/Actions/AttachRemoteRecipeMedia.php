<?php

namespace App\Actions;

use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AttachRemoteRecipeMedia
{
    /**
     * @param  array{en: array{alt_text: string, caption?: ?string}, el: array{alt_text: string, caption?: ?string}}  $translations
     */
    public function handle(Recipe $recipe, string $url, array $translations, string $role = 'gallery', ?string $credit = null, ?string $creditUrl = null): Media
    {
        Validator::make([
            'url' => $url,
            'credit_url' => $creditUrl,
            'translations' => $translations,
            'role' => $role,
        ], [
            'url' => ['required', 'url:https', 'max:2048'],
            'credit_url' => ['nullable', 'url:https', 'max:2048'],
            'translations.en.alt_text' => ['required', 'string', 'max:255'],
            'translations.el.alt_text' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(['cover', 'gallery'])],
        ])->validate();

        return DB::transaction(function () use ($recipe, $url, $translations, $role, $credit, $creditUrl): Media {
            if ($role === 'cover') {
                $coverIds = $recipe->media()->wherePivot('role', 'cover')->pluck('media.id')->all();
                $recipe->media()->updateExistingPivot($coverIds, ['role' => 'gallery']);
            }

            $media = Media::query()->create([
                'disk' => Media::REMOTE_DISK,
                'url' => $url,
                'credit' => $credit,
                'credit_url' => $creditUrl,
            ]);

            $media->translations()->createMany([
                ['locale' => 'en', ...$translations['en']],
                ['locale' => 'el', ...$translations['el']],
            ]);

            $recipe->media()->attach($media, [
                'role' => $role,
                'sort_order' => $recipe->media()->count(),
            ]);

            return $media;
        });
    }
}
