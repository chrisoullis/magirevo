<?php

namespace App\Filament\Resources\Ingredients\Schemas;

use App\Filament\Support\TranslationTabs;
use App\Models\Unit;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class IngredientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ingredient')->columns(3)->columnSpanFull()->schema([
                    TextInput::make('code')
                        ->required()
                        ->maxLength(100)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->helperText('Lowercase letters, numbers and hyphens.')
                        ->unique(ignoreRecord: true),
                    Select::make('default_unit_id')
                        ->label('Default unit')
                        ->relationship('defaultUnit', 'code', fn (Builder $query) => $query->orderBy('sort_order'))
                        ->getOptionLabelFromRecordUsing(fn (Unit $unit): string => $unit->translate('en')?->name ?? $unit->code)
                        ->searchable()
                        ->preload(),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->inline(false)
                        ->helperText('Inactive ingredients cannot be added to recipes.'),
                ]),
                TranslationTabs::make(fn (string $locale): array => [
                    TextInput::make("{$locale}.name")->label('Name')->required()->maxLength(255),
                    TextInput::make("{$locale}.plural_name")->label('Plural name')->maxLength(255),
                    Textarea::make("{$locale}.description")->label('Description')->rows(3),
                ]),
            ]);
    }
}
