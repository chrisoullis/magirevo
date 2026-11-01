<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GoogleTranslationService
{
    private const ENDPOINT = 'https://translation.googleapis.com/language/translate/v2';

    /**
     * Translate text between languages, returning null when translation is unavailable.
     */
    public function translate(string $text, string $from, string $to, bool $isHtml = false): ?string
    {
        $key = config('services.google_translate.key');

        if (blank($key)) {
            return null;
        }

        try {
            $response = Http::timeout(15)->asForm()->post(self::ENDPOINT, [
                'key' => $key,
                'q' => $text,
                'source' => $from,
                'target' => $to,
                'format' => $isHtml ? 'html' : 'text',
            ]);

            if ($response->failed()) {
                Log::warning('Google Translate request failed.', ['status' => $response->status()]);

                return null;
            }

            $translated = $response->json('data.translations.0.translatedText');

            if (! is_string($translated)) {
                return null;
            }

            return $isHtml ? $translated : html_entity_decode($translated, ENT_QUOTES | ENT_HTML5);
        } catch (Throwable $exception) {
            Log::warning('Google Translate request errored.', ['message' => $exception->getMessage()]);

            return null;
        }
    }
}
