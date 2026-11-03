<?php

namespace App\Filament\Resources\Tags\Schemas;

use App\Enums\TagGroup;
use App\Filament\Support\TranslationTabs;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class TagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tag')->columns(3)->columnSpanFull()->schema([
                    TextInput::make('code')
                        ->required()
                        ->maxLength(100)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->helperText('Lowercase letters, numbers and hyphens.')
                        ->unique(ignoreRecord: true),
                    Select::make('group_code')
                        ->label(__('tags.group'))
                        ->options(TagGroup::options())
                        ->rules([Rule::enum(TagGroup::class)])
                        ->searchable(),
                    Select::make('classification')
                        ->options([
                            'unclassified' => 'Unclassified (hidden from the public)',
                            'descriptive' => 'Descriptive (e.g. Quick, Baking)',
                            'cuisine' => 'Cuisine',
                            'diet_style' => 'Dietary style (claim, needs approval)',
                            'nutrition_claim' => 'Nutrition claim (claim, needs approval)',
                            'ingredient_allergen' => 'Ingredient / allergen information (claim, needs approval)',
                        ])
                        ->required()
                        ->default('unclassified')
                        ->helperText('Dietary classifications are only shown publicly for recipes with a current approved claim.'),
                    Toggle::make('is_public_enabled')
                        ->label('Allow public display of approved claims')
                        ->default(false)
                        ->inline(false)
                        ->helperText('Leave off for gluten-free, allergen and nutrition claims until verification rules are reviewed.'),
                    TextInput::make('sort_order')->numeric()->integer()->default(0)->required(),
                    Toggle::make('is_active')->label('Active')->default(true)->inline(false),
                ]),
                TranslationTabs::make(fn (string $locale): array => [
                    TextInput::make("{$locale}.name")
                        ->label('Name')
                        ->required()
                        ->maxLength(150)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $state) use ($locale): void {
                            if (blank($get("{$locale}.slug")) && filled($state)) {
                                $set("{$locale}.slug", Str::slug($state));
                            }
                        }),
                    TextInput::make("{$locale}.slug")
                        ->label('Slug')
                        ->required()
                        ->maxLength(180)
                        ->regex('/^[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*$/u')
                        ->unique(
                            table: 'tag_translations',
                            column: 'slug',
                            ignorable: fn (?Model $record) => $record?->getTranslation($locale, false),
                            modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('locale', $locale),
                        ),
                    Textarea::make("{$locale}.description")->label('Description')->rows(3),
                ]),
            ]);
    }
}
