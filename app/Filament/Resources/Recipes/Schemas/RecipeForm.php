<?php

namespace App\Filament\Resources\Recipes\Schemas;

use App\Models\Category;
use App\Models\Difficulty;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\ScalingMode;
use App\Models\Tag;
use App\Models\Unit;
use App\Services\GoogleTranslationService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class RecipeForm
{
    private const SLUG_PATTERN = '/^[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*$/u';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Recipe')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('English')->schema(self::translationFields('en')),
                    Tab::make('Ελληνικά')->schema(self::translationFields('el')),
                    Tab::make('Details')->schema(self::detailFields()),
                    Tab::make('Ingredients')->schema([self::ingredientsRepeater()]),
                    Tab::make('Cooking steps')->schema([self::stepsRepeater()]),
                ]),
        ]);
    }

    /**
     * @return list<Section|TextInput|Textarea>
     */
    private static function translationFields(string $locale): array
    {
        return [
            TextInput::make("{$locale}.title")
                ->label('Title')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state) use ($locale): void {
                    if (blank($get("{$locale}.slug")) && filled($state)) {
                        $set("{$locale}.slug", Str::slug($state));
                    }
                }),
            TextInput::make("{$locale}.slug")
                ->label('Slug')
                ->helperText('Generated from the title when left empty.')
                ->extraAttributes(['wire:target' => "data.{$locale}.title"])
                ->maxLength(255)
                ->regex(self::SLUG_PATTERN)
                ->unique(
                    table: 'recipe_translations',
                    column: 'slug',
                    ignorable: fn (?Recipe $record) => $record?->getTranslation($locale, false),
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('locale', $locale),
                ),
            Textarea::make("{$locale}.summary")
                ->label('Summary')
                ->rows(2)
                ->maxLength(1000)
                ->hintAction(self::translateAction("{$locale}.summary", $locale)),
            RichEditor::make("{$locale}.introduction")
                ->label('Introduction')
                ->hintAction(self::translateAction("{$locale}.introduction", $locale, isHtml: true))
                ->toolbarButtons([['bold', 'italic', 'underline', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList', 'blockquote'], ['undo', 'redo']])
                ->maxLength(10000)
                ->columnSpanFull(),
            TextInput::make("{$locale}.seo_title")->label('SEO title')->maxLength(255),
            TextInput::make("{$locale}.seo_description")->label('SEO description')->maxLength(320),
        ];
    }

    /**
     * @return list<Section>
     */
    private static function detailFields(): array
    {
        return [
            Section::make('Classification')->columns(2)->schema([
                Select::make('categories')
                    ->relationship('categories', 'code')
                    ->options(fn (?Recipe $record): array => Category::query()
                        ->with(['translations', 'parent.translations'])
                        ->where(fn (Builder $categories) => $categories
                            ->where(fn (Builder $active) => $active->publiclyVisible())
                            ->when($record, fn (Builder $categories) => $categories->orWhereHas('recipes', fn (Builder $recipes) => $recipes->whereKey($record->getKey()))))
                        ->hierarchyOrdered()
                        ->get()
                        ->mapWithKeys(fn (Category $category): array => [$category->getKey() => self::categoryLabel($category)])
                        ->all())
                    ->multiple()
                    ->preload(),
                Select::make('tags')
                    ->relationship(
                        'tags',
                        'code',
                        fn (Builder $query) => $query->where('is_active', true)->orderBy('sort_order'),
                    )
                    ->getOptionLabelFromRecordUsing(fn (Tag $tag): string => $tag->translate('en')?->name ?? $tag->code)
                    ->multiple()
                    ->preload(),
                Select::make('difficulty')
                    ->options(fn (?Recipe $record): array => Difficulty::options($record?->difficulty))
                    ->required(),
                Toggle::make('is_featured')->label('Feature on homepage'),
            ]),
            Section::make('Timing and yield')->columns(2)->schema([
                TextInput::make('prep_minutes')->numeric()->integer()->minValue(0)->maxValue(10000)->required(),
                TextInput::make('cook_minutes')->numeric()->integer()->minValue(0)->maxValue(10000)->required(),
                TextInput::make('rest_minutes')->numeric()->integer()->minValue(0)->maxValue(10000)->required()->default(0),
                TextInput::make('base_servings')->numeric()->minValue(0.01)->maxValue(1000)->required(),
            ]),
            Section::make('Publication')->columns(2)->visibleOn('edit')->schema([
                Select::make('status')
                    ->options(['draft' => 'Draft', 'pending' => 'Pending review', 'published' => 'Published', 'archived' => 'Archived'])
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Use the workflow buttons at the top of the page to change the status.'),
                Select::make('visibility')
                    ->options(['private' => 'Private', 'shared' => 'Shared', 'public' => 'Public'])
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Change who can read the recipe with the visibility buttons at the top of the page.'),
                DateTimePicker::make('published_at')->disabled()->dehydrated(false),
            ]),
        ];
    }

    private static function ingredientsRepeater(): Repeater
    {
        return Repeater::make('ingredients_data')
            ->label('Ingredients')
            ->minItems(1)
            ->maxItems(100)
            ->reorderable()
            ->collapsible()
            ->cloneable()
            ->itemLabel(fn (array $state): ?string => filled($state['ingredient_id'] ?? null)
                ? self::ingredientLabel(Ingredient::with('translations')->find($state['ingredient_id']))
                : null)
            ->columns(4)
            ->schema([
                Hidden::make('id'),
                Select::make('ingredient_id')
                    ->label('Ingredient')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Ingredient::query()
                        ->where('is_active', true)
                        ->where(fn (Builder $query) => $query
                            ->where('code', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                            ->orWhereHas('translations', fn (Builder $translation) => $translation
                                ->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')))
                        ->with('translations')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Ingredient $ingredient): array => [$ingredient->id => self::ingredientLabel($ingredient)])
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => self::ingredientLabel(Ingredient::with('translations')->find($value)))
                    ->exists('ingredients', 'id')
                    ->columnSpan(2),
                TextInput::make('quantity')->numeric()->minValue(0)->maxValue(99999999),
                TextInput::make('quantity_max')
                    ->label('Up to')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(99999999)
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (filled($value) && (blank($get('quantity')) || (float) $value < (float) $get('quantity'))) {
                                $fail('The maximum quantity must be at least the quantity.');
                            }
                        },
                    ]),
                Select::make('unit_id')
                    ->label('Unit')
                    ->options(fn (): array => Unit::query()->with('translations')->orderBy('sort_order')->get()
                        ->mapWithKeys(fn (Unit $unit): array => [$unit->id => $unit->translate('en')?->name ?? $unit->code])
                        ->all())
                    ->searchable()
                    ->exists('units', 'id'),
                Select::make('scaling_mode')
                    ->options(fn (Get $get): array => ScalingMode::options($get('scaling_mode')))
                    ->default('linear')
                    ->required(),
                Toggle::make('is_optional')->label('Optional')->inline(false),
                TextInput::make('quantity_text_en')->label('Quantity text (EN)')->maxLength(255),
                TextInput::make('quantity_text_el')->label('Quantity text (EL)')->maxLength(255),
                TextInput::make('preparation_note_en')->label('Preparation note (EN)')->maxLength(255)->columnSpan(2),
                TextInput::make('preparation_note_el')->label('Preparation note (EL)')->maxLength(255)->columnSpan(2),
            ]);
    }

    private static function stepsRepeater(): Repeater
    {
        return Repeater::make('steps_data')
            ->label('Cooking steps')
            ->minItems(1)
            ->maxItems(100)
            ->reorderable()
            ->collapsible()
            ->itemNumbers()
            ->columns(2)
            ->schema([
                Hidden::make('id'),
                Textarea::make('instruction_en')
                    ->label('Instruction (EN)')
                    ->required()
                    ->rows(3)
                    ->maxLength(5000)
                    ->hintAction(self::translateAction('instruction_en', 'en', targetPath: 'instruction_el')),
                Textarea::make('instruction_el')
                    ->label('Instruction (EL)')
                    ->required()
                    ->rows(3)
                    ->maxLength(5000)
                    ->hintAction(self::translateAction('instruction_el', 'el', targetPath: 'instruction_en')),
                TextInput::make('duration_minutes')->label('Duration (minutes)')->numeric()->integer()->minValue(1)->maxValue(32000),
            ]);
    }

    /**
     * Builds an action that translates the field's content into the other language
     * and writes the result to the counterpart field.
     */
    private static function translateAction(string $sourcePath, string $sourceLocale, bool $isHtml = false, ?string $targetPath = null): Action
    {
        $targetLocale = $sourceLocale === 'en' ? 'el' : 'en';
        $label = $targetLocale === 'el' ? 'Translate to Greek' : 'Translate to English';

        return Action::make('translate_'.str_replace('.', '_', $sourcePath))
            ->label($label)
            ->icon('heroicon-m-language')
            ->link()
            ->action(function (Get $get, Set $set) use ($sourcePath, $sourceLocale, $targetLocale, $isHtml, $targetPath): void {
                $text = $get($sourcePath);

                if (! is_string($text) || blank(strip_tags($text))) {
                    Notification::make()->title('Nothing to translate')->warning()->send();

                    return;
                }

                $translated = app(GoogleTranslationService::class)->translate($text, $sourceLocale, $targetLocale, $isHtml);

                if ($translated === null) {
                    Notification::make()->title('Translation failed')->body('Google Translate is unavailable or not configured.')->danger()->send();

                    return;
                }

                $set($targetPath ?? str_replace($sourceLocale.'.', $targetLocale.'.', $sourcePath), $translated);
            });
    }

    private static function categoryLabel(Category $category): string
    {
        $name = $category->translate('en')?->name ?? $category->code;

        if ($category->parent !== null) {
            $name = ($category->parent->translate('en')?->name ?? $category->parent->code).' › '.$name;
        }

        return $category->isEffectivelyActive() ? $name : $name.' (inactive)';
    }

    private static function ingredientLabel(?Ingredient $ingredient): ?string
    {
        return $ingredient?->translate('en')?->name ?? $ingredient?->code;
    }
}
