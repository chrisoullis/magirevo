<?php

namespace App\Filament\Resources\Ingredients\Schemas;

use App\Filament\Support\TranslationTabs;
use App\Models\Ingredient;
use App\Models\IngredientCatalogGroup;
use App\Models\Tag;
use App\Models\Unit;
use App\Support\AppPermissions;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class IngredientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ingredient')->columns(3)->columnSpanFull()->schema([
                    TextInput::make('code')
                        ->required()
                        ->disabledOn('edit')
                        ->maxLength(100)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->helperText('Lowercase letters, numbers and hyphens.')
                        ->unique(ignoreRecord: true),
                    Select::make('catalog_group_id')->label('Ingredient group')
                        ->options(fn (): array => IngredientCatalogGroup::query()->with('translations')->orderBy('sort_order')->get()
                            ->mapWithKeys(fn (IngredientCatalogGroup $group): array => [$group->id => $group->translate(app()->getLocale())?->name ?? $group->code])->all())
                        ->searchable()->required()->exists('ingredient_catalog_groups', 'id'),
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
                    TextInput::make("{$locale}.name")->label('Name')->required()->maxLength(255)->live(onBlur: true)
                        ->helperText(fn (Get $get, ?Ingredient $record): string => self::duplicates((string) $get("{$locale}.name"), $locale, $record))
                        ->rules([fn (?Ingredient $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($locale, $record): void {
                            if (Ingredient::query()->when($record, fn ($query) => $query->whereKeyNot($record->id))
                                ->whereHas('translations', fn ($query) => $query->where('locale', $locale)->whereRaw('lower(name) = lower(?)', [$value]))->exists()) {
                                $fail('This name already belongs to another ingredient. Check the duplicate suggestions.');
                            }
                        }]),
                    TextInput::make("{$locale}.plural_name")->label('Plural name')->maxLength(255),
                    TextInput::make("{$locale}.slug")->label('Page slug')->nullable()->maxLength(180)
                        ->regex('/^[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*$/u')
                        ->unique(table: 'ingredient_translations', column: 'slug',
                            ignorable: fn (?Model $record) => $record?->getTranslation($locale, false),
                            modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('locale', $locale)),
                    Textarea::make("{$locale}.description")->label('Description')->rows(3),
                    Textarea::make("{$locale}.culinary_uses")->label('Common culinary uses')->rows(3)->maxLength(10000),
                    Textarea::make("{$locale}.preparation_tips")->label('Cooking and preparation tips')->rows(3)->maxLength(10000),
                    Textarea::make("{$locale}.storage_tips")->label('Storage recommendations')->rows(3)->maxLength(10000),
                ]),
                Section::make('Search aliases')->columnSpanFull()->schema([
                    Repeater::make('aliases')->relationship()->schema([
                        Select::make('locale')->options(['en' => 'English', 'el' => 'Ελληνικά'])->required()->rules([Rule::in(['en', 'el'])]),
                        TextInput::make('alias')->required()->maxLength(255),
                    ])->columns(2)->defaultItems(0)->addActionLabel('Add alias'),
                ]),
                Section::make('Dietary and allergen evidence')
                    ->description('Internal decision support only. These records are not professional certification or a safety guarantee.')
                    ->visible(fn (): bool => auth()->user()?->can(AppPermissions::DIETARY_CLAIMS_REVIEW) ?? false)
                    ->columnSpanFull()
                    ->schema([
                        Section::make('Composition profile')
                            ->relationship('dietaryProfile')
                            ->columns(2)
                            ->schema([
                                Select::make('composition_status')
                                    ->options(['complete' => 'Complete', 'incomplete' => 'Incomplete', 'unknown' => 'Unknown'])
                                    ->default('unknown')
                                    ->required(),
                                Select::make('origin_classification')
                                    ->options([
                                        'animal_flesh' => 'Animal flesh',
                                        'animal_derived_other' => 'Other animal-derived',
                                        'not_animal_derived' => 'Not animal-derived',
                                        'unknown' => 'Unknown',
                                    ])
                                    ->default('unknown')
                                    ->required(),
                                TagsInput::make('evidence')
                                    ->label('Composition evidence references')
                                    ->placeholder('Add a source reference')
                                    ->columnSpanFull(),
                                Hidden::make('reviewed_by'),
                                Hidden::make('reviewed_at'),
                            ]),
                        Repeater::make('components')
                            ->relationship()
                            ->label('Compound ingredient components')
                            ->schema([
                                Select::make('component_ingredient_id')
                                    ->label('Known ingredient component')
                                    ->relationship('componentIngredient', 'code')
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(fn (Set $set): mixed => $set('unresolved_component', null)),
                                TextInput::make('unresolved_component')
                                    ->label('Unresolved component description')
                                    ->maxLength(255)
                                    ->live()
                                    ->afterStateUpdated(fn (Set $set): mixed => $set('component_ingredient_id', null)),
                                TagsInput::make('evidence')
                                    ->label('Component evidence references'),
                            ])
                            ->columns(2)
                            ->addActionLabel('Add component'),
                        Repeater::make('dietaryAssessments')
                            ->relationship()
                            ->label('Dietary assessments')
                            ->schema([
                                Select::make('tag_id')
                                    ->label('Classification')
                                    ->relationship('tag', 'code')
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                                        $code = Tag::query()->whereKey($state)->value('code');

                                        if ($code !== null) {
                                            $set('rule_version', (int) config("dietary.claims.{$code}.rule_version", config('dietary.default_rule_version', 1)));
                                        }
                                    })
                                    ->required(),
                                Select::make('outcome')
                                    ->options(['compatible' => 'Compatible', 'incompatible' => 'Incompatible', 'unknown' => 'Unknown'])
                                    ->default('unknown')
                                    ->required(),
                                TextInput::make('verification_method')->maxLength(40),
                                TagsInput::make('evidence')->label('Evidence references'),
                                Textarea::make('review_notes')->rows(2),
                                Hidden::make('reviewed_by'),
                                Hidden::make('reviewed_at'),
                                TextInput::make('rule_version')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default((int) config('dietary.default_rule_version', 1))
                                    ->required()
                                    ->helperText('Use the version configured for this dietary classification.'),
                            ])
                            ->columns(2)
                            ->addActionLabel('Add assessment'),
                        Repeater::make('allergenAssessments')
                            ->relationship()
                            ->label('Allergen assessments')
                            ->schema([
                                Select::make('allergen_code')
                                    ->options(array_combine(
                                        ['celery', 'cereals_containing_gluten', 'crustaceans', 'eggs', 'fish', 'lupin', 'milk', 'molluscs', 'mustard', 'peanuts', 'sesame', 'soybeans', 'sulphites', 'tree_nuts'],
                                        ['Celery', 'Cereals containing gluten', 'Crustaceans', 'Eggs', 'Fish', 'Lupin', 'Milk', 'Molluscs', 'Mustard', 'Peanuts', 'Sesame', 'Soybeans', 'Sulphites', 'Tree nuts'],
                                    ))
                                    ->required(),
                                Select::make('presence')
                                    ->options([
                                        'contains' => 'Contains',
                                        'may_contain' => 'May contain',
                                        'not_present' => 'Not present (reviewed)',
                                        'unknown' => 'Unknown',
                                    ])
                                    ->default('unknown')
                                    ->required(),
                                TextInput::make('verification_method')->maxLength(40),
                                TagsInput::make('evidence')->label('Evidence references'),
                                Textarea::make('review_notes')->rows(2),
                                Hidden::make('reviewed_by'),
                                Hidden::make('reviewed_at'),
                            ])
                            ->columns(2)
                            ->addActionLabel('Add allergen assessment'),
                    ]),
            ]);
    }

    private static function duplicates(string $name, string $locale, ?Ingredient $record): string
    {
        if (mb_strlen(trim($name)) < 3) {
            return 'Use a canonical name; add synonyms as aliases.';
        }
        $pattern = '%'.addcslashes(trim($name), '%_\\').'%';
        $codes = Ingredient::query()->when($record, fn ($query) => $query->whereKeyNot($record->id))
            ->where(fn ($query) => $query->whereHas('translations', fn ($q) => $q->where('locale', $locale)->where('name', 'ilike', $pattern))
                ->orWhereHas('aliases', fn ($q) => $q->where('locale', $locale)->where('alias', 'ilike', $pattern)))
            ->orderBy('code')->limit(5)->pluck('code');

        return $codes->isEmpty() ? 'No similar names found.' : 'Possible duplicates: '.$codes->implode(', ').'. Review before creating a new ingredient.';
    }
}
