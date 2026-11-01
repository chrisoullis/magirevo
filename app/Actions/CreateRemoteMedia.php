<?php

namespace App\Actions;

use App\Models\Media;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateRemoteMedia
{
    /**
     * @param  array{en: array{alt_text: string, caption?: ?string}, el: array{alt_text: string, caption?: ?string}}  $translations
     * @param  (Closure(Media): void)|null  $within
     */
    public function handle(string $url, array $translations, ?string $credit = null, ?string $creditUrl = null, ?Closure $within = null): Media
    {
        Validator::make([
            'url' => $url,
            'credit_url' => $creditUrl,
            'translations' => $translations,
        ], [
            'url' => ['required', 'url:https', 'max:2048'],
            'credit_url' => ['nullable', 'url:https', 'max:2048'],
            'translations.en.alt_text' => ['required', 'string', 'max:255'],
            'translations.el.alt_text' => ['required', 'string', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($url, $translations, $credit, $creditUrl, $within): Media {
            $media = Media::query()->create([
                'disk' => Media::REMOTE_DISK,
                'media_type' => Media::TYPE_IMAGE,
                'source' => Media::SOURCE_REMOTE,
                'url' => $url,
                'credit' => $credit,
                'credit_url' => $creditUrl,
            ]);

            $media->translations()->createMany([
                ['locale' => 'en', ...$translations['en']],
                ['locale' => 'el', ...$translations['el']],
            ]);

            if ($within !== null) {
                $within($media);
            }

            return $media;
        });
    }
}
