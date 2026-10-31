<?php

namespace App\Filament\Resources\Units\Schemas;

use App\Filament\Support\TranslationTabs;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UnitForm
{
    public const TYPES = ['mass' => 'Mass', 'volume' => 'Volume', 'count' => 'Count'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Unit')->columns(3)->columnSpanFull()->schema([
                    TextInput::make('code')
                        ->required()
                        ->maxLength(30)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->helperText('Lowercase letters, numbers and hyphens.')
                        ->unique(ignoreRecord: true),
                    Select::make('unit_type')->options(self::TYPES)->required(),
                    TextInput::make('base_conversion')
                        ->numeric()
                        ->rule('gt:0')
                        ->maxValue(9999999999)
                        ->helperText('Factor to grams, millilitres or pieces, depending on the type.'),
                    TextInput::make('sort_order')->numeric()->integer()->default(0)->required(),
                    Toggle::make('allow_fraction')->label('Allow fractions')->default(true)->inline(false),
                    Toggle::make('is_scalable')->label('Scalable')->default(true)->inline(false),
                ]),
                TranslationTabs::make(fn (string $locale): array => [
                    TextInput::make("{$locale}.name")->label('Name')->required()->maxLength(100),
                    TextInput::make("{$locale}.abbreviation")->label('Abbreviation')->required()->maxLength(30),
                ]),
            ]);
    }
}
