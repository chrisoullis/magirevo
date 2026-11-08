<?php

namespace App\Services\Recipes;

use App\Enums\TagClassification;
use App\Models\Category;
use App\Models\Difficulty;
use App\Models\Ingredient;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\ScalingMode;
use App\Models\Tag;
use App\Models\Unit;
use App\Services\GoogleTranslationService;
use App\Services\RecipeRevisionSnapshotter;
use App\Support\AppPermissions;
use App\Support\TrustedVideoUrl;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class RecipeEditorFields
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
                    Tab::make('Details')->schema(self::detailFields(administrative: true)),
                    Tab::make('Ingredients')->schema([self::groupsRepeater(), self::ingredientsRepeater()]),
                    Tab::make('Cooking steps')->schema([...self::stepGroupFields(), self::stepsRepeater()]),
                ]),
        ]);
    }

    /**
     * @return list<Section|TextInput|Textarea>
     */
    public static function translationFields(string $locale): array
    {
        return [
            TextInput::make("{$locale}.title")
                ->label(__('studio.fields.title'))
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state) use ($locale): void {
                    if (blank($get("{$locale}.slug")) && filled($state)) {
                        $set("{$locale}.slug", Str::slug($state));
                    }
                }),
            TextInput::make("{$locale}.slug")
                ->label(__('studio.fields.slug'))
                ->helperText(__('studio.fields.generated_from_the_title_when_left_empty'))
                ->extraAttributes(['wire:target' => "data.{$locale}.title"])
                ->maxLength(255)
                ->regex(self::SLUG_PATTERN)
                ->unique(
                    table: 'recipe_translations',
                    column: 'slug',
                    ignorable: fn (Recipe|RecipeRevision|null $record) => ($record instanceof RecipeRevision ? $record->recipe : $record)?->getTranslation($locale, false),
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('locale', $locale),
                ),
            Textarea::make("{$locale}.summary")
                ->label(__('studio.fields.summary'))
                ->rows(2)
                ->maxLength(1000)
                ->hintAction(self::translateAction("{$locale}.summary", $locale)),
            RichEditor::make("{$locale}.introduction")
                ->label(__('studio.fields.introduction'))
                ->hintAction(self::translateAction("{$locale}.introduction", $locale, isHtml: true))
                ->toolbarButtons([['bold', 'italic', 'underline', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList', 'blockquote'], ['undo', 'redo']])
                ->maxLength(10000)
                ->columnSpanFull(),
            TextInput::make("{$locale}.seo_title")->label(__('studio.fields.seo_title'))->maxLength(255),
            TextInput::make("{$locale}.seo_description")->label(__('studio.fields.seo_description'))->maxLength(320),
        ];
    }

    /**
     * @return list<Section>
     */
    public static function detailFields(bool $administrative = false): array
    {
        return [
            Section::make(__('studio.fields.classification'))->columns(2)->schema([
                Select::make('categories')->label(__('studio.fields.categories'))

                    ->options(fn (Recipe|RecipeRevision|null $record): array => Category::query()
                        ->with(['translations', 'parent.translations'])
                        ->where(fn (Builder $categories) => $categories
                            ->where(fn (Builder $active) => $active->publiclyVisible())
                            ->when($record, fn (Builder $categories) => $categories->orWhereHas('recipes', fn (Builder $recipes) => $recipes->whereKey($record instanceof RecipeRevision ? $record->recipe_id : $record->getKey()))))
                        ->hierarchyOrdered()
                        ->get()
                        ->mapWithKeys(fn (Category $category): array => [$category->getKey() => self::categoryLabel($category)])
                        ->all())
                    ->multiple()
                    ->preload(),
                Select::make('tags')->label(__('studio.fields.tags'))
                    ->options(fn (): array => Tag::query()->where('is_active', true)->editorial()->with('translations')->get()
                        ->mapWithKeys(fn (Tag $tag): array => [$tag->id => $tag->groupLabel().' · '.($tag->translate(app()->getLocale())?->name ?? $tag->code)])->all())
                    ->getOptionLabelFromRecordUsing(fn (Tag $tag): string => $tag->groupLabel().' · '.($tag->translate(app()->getLocale())?->name ?? $tag->code))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Tag::query()->where('is_active', true)->editorial()
                        ->where(fn (Builder $query) => $query->where('code', 'ilike', '%'.$search.'%')
                            ->orWhere('group_code', 'ilike', '%'.$search.'%')
                            ->orWhereHas('translations', fn (Builder $translation) => $translation->where('name', 'ilike', '%'.$search.'%')))
                        ->with('translations')->orderBy('group_code')->orderBy('sort_order')->limit(50)->get()
                        ->mapWithKeys(fn (Tag $tag): array => [$tag->id => $tag->groupLabel().' · '.($tag->translate(app()->getLocale())?->name ?? $tag->code)])->all())
                    ->helperText(__('tags.claim_notice'))
                    ->nestedRecursiveRules([
                        Rule::exists('tags', 'id')->where('is_active', true)
                            ->whereIn('classification', TagClassification::freelyPublicValues())
                            ->where(fn ($query) => $query->whereNull('group_code')->orWhere('group_code', '!=', 'dietary_claim')),
                    ])
                    ->multiple()
                    ->preload(),
                Select::make('difficulty')->label(__('studio.fields.difficulty'))
                    ->options(fn (Recipe|RecipeRevision|null $record): array => Difficulty::options(($record instanceof RecipeRevision ? $record->recipe : $record)?->difficulty))
                    ->required(),
                Toggle::make('is_featured')->label(__('studio.fields.feature_on_homepage'))->visible($administrative)->dehydrated($administrative),
            ]),
            Section::make(__('studio.fields.timing_and_yield'))->columns(2)->schema([
                TextInput::make('prep_minutes')->label(__('studio.fields.prep_minutes'))->numeric()->integer()->minValue(0)->maxValue(10000)->required(),
                TextInput::make('cook_minutes')->label(__('studio.fields.cook_minutes'))->numeric()->integer()->minValue(0)->maxValue(10000)->required(),
                TextInput::make('rest_minutes')->label(__('studio.fields.rest_minutes'))->numeric()->integer()->minValue(0)->maxValue(10000)->required()->default(0),
                TextInput::make('base_servings')->label(__('studio.fields.base_servings'))->numeric()->minValue(0.01)->maxValue(1000)->required(),
                Repeater::make('yields_data')->label(__('nested.yields'))->defaultItems(0)->maxItems(2)->columnSpanFull()
                    ->visible(fn (): bool => auth()->user()?->can(AppPermissions::RECIPES_REVIEW) ?? false)
                    ->schema([
                        Select::make('basis')->label(__('nested.quantity_basis'))->options(['weight' => __('nested.weight'), 'volume' => __('nested.volume')])->required(),
                        TextInput::make('quantity')->label(__('studio.fields.quantity'))->numeric()->minValue(0.0001)->maxValue(99999999)->required(),
                        Select::make('unit_id')->label(__('studio.fields.unit'))->options(fn (): array => Unit::whereIn('unit_type', ['mass', 'volume'])->with('translations')->get()->mapWithKeys(fn (Unit $unit): array => [$unit->id => $unit->translate(app()->getLocale())?->name ?? $unit->code])->all())->required(),
                        Textarea::make('evidence.notes')->label(__('nested.evidence'))->maxLength(2000)->required(),
                    ]),
            ]),
            Section::make(__('studio.fields.publication'))->columns(2)->visible(fn (string $operation): bool => $administrative && $operation === 'edit')->schema([
                Select::make('status')
                    ->options(['draft' => 'Draft', 'pending' => 'Pending review', 'published' => 'Published', 'archived' => 'Archived'])
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText(__('studio.fields.use_the_workflow_buttons_at_the_top_of_the_page_to_change_the_status')),
                Select::make('visibility')
                    ->options(['private' => 'Private', 'shared' => 'Shared', 'public' => 'Public'])
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText(__('studio.fields.change_who_can_read_the_recipe_with_the_visibility_buttons_at_the_top_of_the_page')),
                DateTimePicker::make('published_at')->disabled()->dehydrated(false),
            ]),
        ];
    }

    public static function groupsRepeater(): Repeater
    {
        return Repeater::make('ingredient_groups_data')->label(__('studio.fields.ingredient_groups'))->defaultItems(0)->maxItems(30)->schema([
            Hidden::make('id'),
            TextInput::make('names.en')->label(__('studio.fields.name_en'))->maxLength(150),
            TextInput::make('names.el')->label(__('studio.fields.name_el'))->maxLength(150),
        ])->live()->afterStateUpdated(function (?array $state, ?array $old, Get $get, Set $set): void {
            $before = array_keys($old ?? []);
            $after = array_keys($state ?? []);
            if ($before === $after) {
                return;
            }
            $lines = $get('ingredients_data') ?? [];
            foreach ($lines as &$line) {
                if (isset($line['group_index'], $before[$line['group_index']])) {
                    $index = array_search($before[$line['group_index']], $after, true);
                    $line['group_index'] = $index === false ? null : $index;
                }
            }
            $set('ingredients_data', $lines);
        });
    }

    public static function ingredientsRepeater(): Repeater
    {
        return Repeater::make('ingredients_data')
            ->addActionLabel(fn (): string => config('nested-recipes.enabled') ? __('nested.add_line') : __('nested.add_ingredient'))
            ->label(__('studio.fields.ingredients'))
            ->minItems(1)
            ->maxItems(100)
            ->reorderable()
            ->collapsible()
            ->cloneable()
            ->cloneAction(fn (Action $action): Action => $action->after(function (Repeater $component, array $arguments): void {
                $items = $component->getRawState();
                $key = array_key_last($items);
                if (! array_key_exists($arguments['item'], $items) || (string) $key === (string) $arguments['item']) {
                    return;
                }
                $items[$key]['id'] = null;
                $items[$key]['line_uuid'] = null;
                $component->rawState($items);
            }))
            ->itemLabel(fn (array $state): ?string => ($state['line_type'] ?? 'ingredient') === 'recipe'
                ? app(RecipeComponentEligibility::class)->label(auth()->user(), $state['component_recipe_id'] ?? null, revisionId: $state['component_recipe_revision_id'] ?? null)
                : (filled($state['ingredient_id'] ?? null)
                ? self::ingredientLabel(Ingredient::with('translations')->find($state['ingredient_id']))
                : null))
            ->columns(4)
            ->schema([
                Hidden::make('id'),
                Hidden::make('line_uuid'),
                Hidden::make('component_recipe_revision_id'),
                Select::make('line_type')->label(__('nested.line_type'))
                    ->options(fn (Get $get): array => config('nested-recipes.enabled') || $get('line_type') === 'recipe'
                        ? ['ingredient' => __('nested.ingredient'), 'recipe' => __('nested.recipe')]
                        : ['ingredient' => __('nested.ingredient')])
                    ->default('ingredient')->nullable()->live()
                    ->afterStateHydrated(fn (Select $component, ?string $state) => $component->state($state ?? 'ingredient'))
                    ->dehydrateStateUsing(fn (?string $state): string => $state ?? 'ingredient')
                    ->afterStateUpdated(function (Set $set, ?string $state, ?string $old): void {
                        if (($state ?? 'ingredient') === ($old ?? 'ingredient')) {
                            return;
                        }
                        foreach (['ingredient_id', 'component_recipe_id', 'component_recipe_revision_id'] as $field) {
                            $set($field, null);
                        }
                    }),
                Select::make('component_recipe_id')->label(__('nested.recipe'))
                    ->visible(fn (Get $get): bool => $get('line_type') === 'recipe')->required(fn (Get $get): bool => $get('line_type') === 'recipe')
                    ->searchable()->live()
                    ->getSearchResultsUsing(fn (string $search, Recipe|RecipeRevision|null $record): array => app(RecipeComponentEligibility::class)->search(auth()->user(), $search, $record instanceof RecipeRevision ? $record->recipe_id : $record?->id))
                    ->getOptionLabelUsing(fn ($value, Get $get, Recipe|RecipeRevision|null $record): ?string => app(RecipeComponentEligibility::class)->label(auth()->user(), $value, $record instanceof RecipeRevision ? $record->recipe_id : $record?->id, $get('component_recipe_revision_id')))
                    ->afterStateUpdated(fn (Set $set) => $set('component_recipe_revision_id', null))
                    ->columnSpan(2),
                Select::make('quantity_basis')->label(__('nested.quantity_basis'))
                    ->options(collect(['whole_batch', 'servings', 'weight', 'volume'])->mapWithKeys(fn (string $basis): array => [$basis => __('nested.'.$basis)])->all())
                    ->default('whole_batch')->visible(fn (Get $get): bool => $get('line_type') === 'recipe')->required(fn (Get $get): bool => $get('line_type') === 'recipe'),
                Select::make('shopping_mode')->label(__('nested.shopping_mode'))
                    ->options(['scratch' => __('nested.scratch'), 'prepared' => __('nested.prepared')])->default('scratch')->live()
                    ->visible(fn (Get $get): bool => $get('line_type') === 'recipe')->required(fn (Get $get): bool => $get('line_type') === 'recipe'),
                Select::make('prepared_ingredient_id')->label(__('nested.prepared_ingredient'))
                    ->visible(fn (Get $get): bool => $get('line_type') === 'recipe')->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Ingredient::query()->where('is_active', true)->searchCatalogue($search)->with('translations')->limit(50)->get()->mapWithKeys(fn (Ingredient $ingredient): array => [$ingredient->id => self::ingredientLabel($ingredient)])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => self::ingredientLabel(Ingredient::with('translations')->find($value))),
                TextInput::make('prepared_quantity_per_batch')->label(__('nested.prepared_quantity'))->numeric()->minValue(0.0001)->maxValue(99999999)
                    ->visible(fn (Get $get): bool => $get('line_type') === 'recipe'),
                Select::make('prepared_unit_id')->label(__('nested.prepared_unit'))
                    ->options(fn (): array => Unit::with('translations')->get()->mapWithKeys(fn (Unit $unit): array => [$unit->id => $unit->translate(app()->getLocale())?->name ?? $unit->code])->all())
                    ->visible(fn (Get $get): bool => $get('line_type') === 'recipe'),
                Html::make(fn (Get $get, Recipe|RecipeRevision|null $record): HtmlString => new HtmlString(e(app(RecipeComponentEligibility::class)->label(auth()->user(), $get('component_recipe_id'), $record instanceof RecipeRevision ? $record->recipe_id : $record?->id, $get('component_recipe_revision_id')) ?? __('nested.unavailable'))))
                    ->visible(fn (Get $get): bool => $get('line_type') === 'recipe')->columnSpanFull(),
                Select::make('group_index')->label(__('studio.fields.ingredient_group'))->options(function (Get $get): array {
                    return collect($get('../../ingredient_groups_data') ?? [])->values()->mapWithKeys(fn (array $group, int $index): array => [$index => $group['names'][app()->getLocale()] ?? $group['names']['en'] ?? (string) ($index + 1)])->all();
                }),
                Select::make('ingredient_id')
                    ->label(__('studio.fields.ingredient'))
                    ->visible(fn (Get $get): bool => $get('line_type') !== 'recipe')
                    ->required(fn (Get $get): bool => $get('line_type') !== 'recipe')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Ingredient::query()
                        ->where('is_active', true)
                        ->searchCatalogue($search)
                        ->with('translations')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Ingredient $ingredient): array => [$ingredient->id => self::ingredientLabel($ingredient)])
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => self::ingredientLabel(Ingredient::with('translations')->find($value)))
                    ->exists('ingredients', 'id')
                    ->columnSpan(2),
                TextInput::make('quantity')->label(__('studio.fields.quantity'))->numeric()->minValue(0)->maxValue(99999999),
                TextInput::make('quantity_max')
                    ->label(__('studio.fields.up_to'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(99999999)
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (filled($value) && (blank($get('quantity')) || (float) $value < (float) $get('quantity'))) {
                                $fail(__('studio.quantity_range'));
                            }
                        },
                    ]),
                Select::make('unit_id')
                    ->label(__('studio.fields.unit'))
                    ->options(fn (): array => Unit::query()->with('translations')->orderBy('sort_order')->get()
                        ->mapWithKeys(fn (Unit $unit): array => [$unit->id => $unit->translate(app()->getLocale())?->name ?? $unit->code])
                        ->all())
                    ->searchable()
                    ->exists('units', 'id'),
                Select::make('scaling_mode')->label(__('studio.fields.scaling_mode'))
                    ->options(fn (Get $get): array => ScalingMode::options($get('scaling_mode')))
                    ->default('linear')
                    ->required(),
                Toggle::make('is_optional')->label(__('studio.fields.optional'))->inline(false),
                TextInput::make('quantity_text_en')->label(__('studio.fields.quantity_text_en'))->maxLength(255),
                TextInput::make('quantity_text_el')->label(__('studio.fields.quantity_text_el'))->maxLength(255),
                TextInput::make('preparation_note_en')->label(__('studio.fields.preparation_note_en'))->maxLength(255)->columnSpan(2),
                TextInput::make('preparation_note_el')->label(__('studio.fields.preparation_note_el'))->maxLength(255)->columnSpan(2),
            ]);
    }

    /** The same optional group controls are used by the panel, revision form and Contributor Studio. */
    public static function stepGroupFields(): array
    {
        return [
            Hidden::make('step_group_removals')->default([]),
            Repeater::make('step_groups_data')
                ->label(__('cooking.groups'))
                ->dehydratedWhenHidden()
                ->visible(fn (): bool => (bool) config('cooking.step_groups_enabled'))
                ->defaultItems(0)->maxItems(30)->reorderable()->collapsible()->live()
                ->addActionLabel(__('cooking.add_group'))
                ->itemLabel(fn (array $state): string => $state['translations'][app()->getLocale()]['title'] ?? __('cooking.group'))
                ->schema([
                    Hidden::make('id'),
                    Hidden::make('key')->default(fn (): string => (string) Str::uuid()),
                    TextInput::make('translations.en.title')->label(__('cooking.title_en'))->maxLength(150)->live(onBlur: true),
                    TextInput::make('translations.el.title')->label(__('cooking.title_el'))->maxLength(150)->live(onBlur: true),
                    Textarea::make('translations.en.description')->label(__('cooking.description_en'))->maxLength(5000),
                    Textarea::make('translations.el.description')->label(__('cooking.description_el'))->maxLength(5000),
                ])
                ->deleteAction(fn (Action $action): Action => $action->requiresConfirmation()
                    ->modalDescription(__('cooking.remove_group_description'))
                    ->before(function (array $arguments, Repeater $component, Get $get, Set $set): void {
                        $group = $component->getRawItemState($arguments['item']);
                        $removals = $get('step_group_removals') ?? [];
                        if (filled($group['id'] ?? null)) {
                            $removals[] = (int) $group['id'];
                            $set('step_group_removals', array_values(array_unique($removals)));
                        }
                        $steps = $get('steps_data') ?? [];
                        foreach ($steps as &$step) {
                            if (($step['step_group_key'] ?? null) === ($group['key'] ?? null)) {
                                $step['step_group_key'] = null;
                            }
                        }
                        $set('steps_data', $steps);
                    }))
                ->extraItemActions([
                    Action::make('addGroupStep')->label(__('cooking.add_step'))->icon('heroicon-m-plus')
                        ->action(function (array $arguments, Repeater $component, Get $get, Set $set): void {
                            $steps = $get('steps_data') ?? [];
                            if (count($steps) >= 100) {
                                return;
                            }
                            $group = $component->getRawItemState($arguments['item']);
                            $steps[(string) Str::uuid()] = ['id' => null, 'step_group_key' => $group['key'],
                                'instruction_en' => '', 'instruction_el' => '', 'duration_minutes' => null,
                                'media_items' => [], 'revision_media' => []];
                            $set('steps_data', $steps);
                        }),
                ]),
        ];
    }

    public static function stepsRepeater(bool $withMedia = true, bool $revisionMedia = true): Repeater
    {
        return Repeater::make('steps_data')
            ->label(__('studio.fields.cooking_steps'))
            ->minItems(1)
            ->maxItems(100)
            ->reorderable()
            ->collapsible()
            ->itemNumbers()
            ->columns(2)
            ->schema([
                Hidden::make('id'),
                Select::make('step_group_key')->label(__('cooking.group'))->placeholder(__('cooking.ungrouped'))->dehydratedWhenHidden()
                    ->visible(fn (): bool => (bool) config('cooking.step_groups_enabled'))
                    ->options(fn (Get $get): array => collect($get('../../step_groups_data') ?? [])
                        ->mapWithKeys(fn (array $group): array => [$group['key'] => $group['translations'][app()->getLocale()]['title'] ?? __('cooking.group')])->all()),
                Textarea::make('instruction_en')
                    ->label(__('studio.fields.instruction_en'))
                    ->required()
                    ->rows(3)
                    ->maxLength(5000)
                    ->hintAction(self::translateAction('instruction_en', 'en', targetPath: 'instruction_el')),
                Textarea::make('instruction_el')
                    ->label(__('studio.fields.instruction_el'))
                    ->required()
                    ->rows(3)
                    ->maxLength(5000)
                    ->hintAction(self::translateAction('instruction_el', 'el', targetPath: 'instruction_en')),
                TextInput::make('duration_minutes')->label(__('studio.fields.duration_minutes'))->numeric()->integer()->minValue(1)->maxValue(32000),
                ...(! $withMedia && $revisionMedia ? [self::revisionStepMediaRepeater()] : []),
                ...($withMedia ? [
                    Repeater::make('media_items')
                        ->label(__('studio.fields.step_photographs'))
                        ->maxItems(3)
                        ->reorderable()
                        ->collapsible()
                        ->addActionLabel(__('studio.fields.add_photograph'))
                        ->columns(2)
                        ->schema([
                            Hidden::make('media_id'),
                            Html::make(fn (Get $get, ?Recipe $record): HtmlString|string => self::stepImagePreview($get, $record))
                                ->columnSpanFull(),
                            FileUpload::make('upload')
                                ->label(__('studio.fields.photograph'))
                                ->image()
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                ->maxSize(10240)
                                ->rule(Rule::dimensions()->maxWidth(6000)->maxHeight(6000))
                                ->storeFiles(false)
                                ->required(fn (Get $get): bool => blank($get('media_id')))
                                ->helperText(__('studio.fields.images_are_resized_to_1920px_and_optimized_as_webp'))
                                ->columnSpanFull(),
                            Section::make(__('studio.fields.english'))
                                ->columns(1)
                                ->schema([
                                    TextInput::make('alt_text_en')
                                        ->label(__('studio.fields.english_alt_text'))
                                        ->required()
                                        ->maxLength(255)
                                        ->hintAction(self::translateAction('alt_text_en', 'en', targetPath: 'alt_text_el')),
                                    Textarea::make('caption_en')
                                        ->label(__('studio.fields.english_caption'))
                                        ->maxLength(1000)
                                        ->hintAction(self::translateAction('caption_en', 'en', targetPath: 'caption_el')),
                                ]),
                            Section::make(__('studio.fields.greek'))
                                ->columns(1)
                                ->schema([
                                    TextInput::make('alt_text_el')
                                        ->label(__('studio.fields.greek_alt_text'))
                                        ->required()
                                        ->maxLength(255)
                                        ->hintAction(self::translateAction('alt_text_el', 'el', targetPath: 'alt_text_en')),
                                    Textarea::make('caption_el')
                                        ->label(__('studio.fields.greek_caption'))
                                        ->maxLength(1000)
                                        ->hintAction(self::translateAction('caption_el', 'el', targetPath: 'caption_en')),
                                ]),
                        ])
                        ->columnSpanFull(),
                    Section::make(__('studio.fields.optional_step_video'))
                        ->columns(2)
                        ->schema([
                            TextInput::make('video_url')
                                ->label(__('studio.fields.youtube_or_vimeo_url'))
                                ->url()
                                ->maxLength(2048)
                                ->rules([
                                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                        if (filled($value) && (! is_string($value) || TrustedVideoUrl::embedUrl($value) === null)) {
                                            $fail(__('site.video_url_invalid'));
                                        }
                                    },
                                ])
                                ->helperText(__('studio.fields.paste_a_video_url_from_youtube_or_vimeo'))
                                ->columnSpanFull(),
                            Section::make(__('studio.fields.english'))
                                ->columns(1)
                                ->schema([
                                    TextInput::make('video_alt_text_en')
                                        ->label(__('studio.fields.english_video_title'))
                                        ->maxLength(255)
                                        ->hintAction(self::translateAction('video_alt_text_en', 'en', targetPath: 'video_alt_text_el')),
                                    Textarea::make('video_caption_en')
                                        ->label(__('studio.fields.english_video_caption'))
                                        ->maxLength(1000)
                                        ->hintAction(self::translateAction('video_caption_en', 'en', targetPath: 'video_caption_el')),
                                ]),
                            Section::make(__('studio.fields.greek'))
                                ->columns(1)
                                ->schema([
                                    TextInput::make('video_alt_text_el')
                                        ->label(__('studio.fields.greek_video_title'))
                                        ->maxLength(255)
                                        ->hintAction(self::translateAction('video_alt_text_el', 'el', targetPath: 'video_alt_text_en')),
                                    Textarea::make('video_caption_el')
                                        ->label(__('studio.fields.greek_video_caption'))
                                        ->maxLength(1000)
                                        ->hintAction(self::translateAction('video_caption_el', 'el', targetPath: 'video_caption_en')),
                                ]),
                        ])
                        ->columnSpanFull(),
                ] : []),
            ]);
    }

    public static function revisionMediaRepeater(): Repeater
    {
        return Repeater::make('media_data')->label(__('studio.fields.revision_photographs'))->addable(false)->reorderable()->schema([
            Hidden::make('media_id'),
            Select::make('role')->options(['cover' => __('studio.cover'), 'gallery' => __('studio.gallery')])->required(),
        ])->itemLabel(fn (array $state, $record): string => self::mediaLabel($state, $record));
    }

    private static function revisionStepMediaRepeater(): Repeater
    {
        return Repeater::make('revision_media')->label(__('studio.fields.revision_step_media'))->addable(false)->reorderable()->schema([
            Hidden::make('media_id'), Hidden::make('kind'),
        ])->itemLabel(fn (array $state, $record): string => self::mediaLabel($state, $record));
    }

    private static function mediaLabel(array $state, mixed $record): string
    {
        $allowed = $record instanceof RecipeRevision
            ? collect(app(RecipeRevisionSnapshotter::class)->mediaReferences($record->payload))->pluck('media_id')->contains((int) ($state['media_id'] ?? 0))
            : ($record instanceof Recipe && $record->media()->whereKey($state['media_id'] ?? 0)->exists());
        if (! $allowed) {
            return __('studio.unknown_media');
        }
        $media = Media::with('translations')->find($state['media_id'] ?? null);

        return $media?->translate(app()->getLocale())?->alt_text ?? 'Media '.($state['media_id'] ?? '');
    }

    public static function imageUploadFields(): array
    {
        return [
            FileUpload::make('file')->label(__('studio.fields.image'))->required()->image()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(10240)
                ->rule(Rule::dimensions()->maxWidth(6000)->maxHeight(6000))->storeFiles(false),
            TextInput::make('alt_text_en')->label(__('studio.fields.english_alt_text'))->required()->maxLength(255),
            Textarea::make('caption_en')->label(__('studio.fields.english_caption'))->maxLength(1000),
            TextInput::make('alt_text_el')->label(__('studio.fields.greek_alt_text'))->required()->maxLength(255),
            Textarea::make('caption_el')->label(__('studio.fields.greek_caption'))->maxLength(1000),
        ];
    }

    private static function stepImagePreview(Get $get, ?Recipe $record): HtmlString|string
    {
        $mediaId = $get('media_id');

        if ($record === null || ! is_numeric($mediaId)) {
            return '';
        }

        $media = Media::query()
            ->whereKey((int) $mediaId)
            ->where('media_type', Media::TYPE_IMAGE)
            ->whereHas('recipeSteps', fn (Builder $steps) => $steps
                ->where('recipe_steps.recipe_id', $record->getKey())
                ->where('recipe_step_media.kind', 'image'))
            ->with('translations')
            ->first();

        if ($media === null) {
            return '';
        }

        return new HtmlString(
            '<img src="'.e($media->publicUrl()).'" alt="'.e($media->translate()?->alt_text ?? '').'" loading="lazy" decoding="async" style="max-width: 20rem; height: auto;">',
        );
    }

    /**
     * Builds an action that translates the field's content into the other language
     * and writes the result to the counterpart field.
     */
    private static function translateAction(string $sourcePath, string $sourceLocale, bool $isHtml = false, ?string $targetPath = null): Action
    {
        $targetLocale = $sourceLocale === 'en' ? 'el' : 'en';
        $label = __('studio.translate_'.$targetLocale);

        return Action::make('translate_'.str_replace('.', '_', $sourcePath))
            ->label($label)
            ->icon('heroicon-m-language')
            ->link()
            ->action(function (Get $get, Set $set) use ($sourcePath, $sourceLocale, $targetLocale, $isHtml, $targetPath): void {
                $text = $get($sourcePath);
                if ($isHtml && is_array($text)) {
                    $text = RichContentRenderer::make($text)->toHtml();
                }

                if (! is_string($text) || blank(strip_tags($text))) {
                    Notification::make()->title(__('studio.nothing_to_translate'))->warning()->send();

                    return;
                }

                $translated = app(GoogleTranslationService::class)->translate($text, $sourceLocale, $targetLocale, $isHtml);

                if ($translated === null) {
                    Notification::make()->title(__('studio.translation_failed'))->body(__('studio.translation_unavailable'))->danger()->send();

                    return;
                }

                $set($targetPath ?? str_replace($sourceLocale.'.', $targetLocale.'.', $sourcePath), $translated);
            });
    }

    private static function categoryLabel(Category $category): string
    {
        $name = $category->translate(app()->getLocale())?->name ?? $category->code;

        if ($category->parent !== null) {
            $name = ($category->parent->translate(app()->getLocale())?->name ?? $category->parent->code).' › '.$name;
        }

        return $category->isEffectivelyActive() ? $name : $name.' '.__('studio.inactive');
    }

    private static function ingredientLabel(?Ingredient $ingredient): ?string
    {
        return $ingredient?->translate(app()->getLocale())?->name ?? $ingredient?->code;
    }
}
