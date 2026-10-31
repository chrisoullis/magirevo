<?php

namespace App\Filament\Support;

use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TranslatedColumn
{
    public static function make(string $locale, string $attribute = 'name', ?string $label = null): TextColumn
    {
        return TextColumn::make("{$locale}_{$attribute}")
            ->label($label ?? strtoupper($locale).' '.str_replace('_', ' ', $attribute))
            ->state(fn (Model $record): ?string => $record->getTranslation($locale, false)?->{$attribute})
            ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                'translations',
                fn (Builder $translation) => $translation
                    ->where('locale', $locale)
                    ->where($attribute, 'ilike', '%'.addcslashes($search, '%_\\').'%'),
            ));
    }
}
