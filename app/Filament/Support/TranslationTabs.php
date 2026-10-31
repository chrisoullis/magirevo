<?php

namespace App\Filament\Support;

use Closure;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;

class TranslationTabs
{
    /**
     * @param  Closure(string): array<int, mixed>  $fields  Receives the locale and returns that tab's components.
     */
    public static function make(Closure $fields): Tabs
    {
        return Tabs::make('Translations')
            ->columnSpanFull()
            ->tabs([
                Tab::make('English')->schema($fields('en')),
                Tab::make('Ελληνικά')->schema($fields('el')),
            ]);
    }
}
