<?php

namespace App\Filament\Resources\IngredientCatalogGroups\Schemas;

use App\Filament\Support\TranslationTabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class IngredientCatalogGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ingredient group')->columns(3)->columnSpanFull()->schema([
                    TextInput::make('code')
                        ->required()
                        ->maxLength(100)
                        ->regex('/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/')
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit')
                        ->helperText('Permanent catalogue code; recipe sections use a separate model.'),
                    TextInput::make('sort_order')->numeric()->integer()->default(0)->required(),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->inline(false)
                        ->helperText('Keeps the group available for administration while marking it inactive.'),
                ]),
                TranslationTabs::make(fn (string $locale): array => [
                    TextInput::make("{$locale}.name")->label('Name')->required()->maxLength(150),
                    Textarea::make("{$locale}.description")->label('Description')->rows(3),
                ]),
            ]);
    }
}
