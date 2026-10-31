<?php

namespace App\Filament\Resources\ScalingModes\Schemas;

use App\Filament\Support\TranslationTabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ScalingModeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Scaling mode')->columns(3)->columnSpanFull()->schema([
                    TextInput::make('code')
                        ->required()
                        ->maxLength(20)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit')
                        ->helperText('Stored on recipe ingredients, so it cannot be changed after creation. Only the "linear" mode is scaled automatically when servings change.'),
                    TextInput::make('sort_order')->numeric()->integer()->default(0)->required(),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->inline(false)
                        ->helperText('Inactive modes are hidden from the recipe form.'),
                ]),
                TranslationTabs::make(fn (string $locale): array => [
                    TextInput::make("{$locale}.name")->label('Name')->required()->maxLength(100),
                    Textarea::make("{$locale}.description")->label('Description')->rows(3),
                ]),
            ]);
    }
}
