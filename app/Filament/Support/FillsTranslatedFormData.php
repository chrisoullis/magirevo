<?php

namespace App\Filament\Support;

/**
 * Loads each locale's translated attributes into the form as "{locale}.{attribute}" state.
 * Saving needs nothing extra because Translatable models accept locale-keyed arrays in fill().
 */
trait FillsTranslatedFormData
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        foreach (config('translatable.locales') as $locale) {
            $data[$locale] = $record->getTranslation($locale, false)?->only($record->translatedAttributes) ?? [];
        }

        return $data;
    }
}
