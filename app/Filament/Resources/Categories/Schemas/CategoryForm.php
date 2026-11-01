<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Models\Category;
use App\Services\CategoryHierarchyService;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Category')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('General')->columns(2)->schema(self::generalFields()),
                        Tab::make('Image')->columns(2)->schema(self::imageFields()),
                        Tab::make('English')->schema(self::translationFields('en')),
                        Tab::make('Ελληνικά')->schema(self::translationFields('el')),
                    ]),
            ]);
    }

    /**
     * @return list<TextInput|Select|Toggle>
     */
    private static function generalFields(): array
    {
        return [
            TextInput::make('code')
                ->required()
                ->maxLength(100)
                ->regex('/^[a-z0-9]+(?:_[a-z0-9]+)*$/')
                ->helperText('Stable identifier in lowercase snake_case, e.g. main_dishes. It cannot be changed after creation.')
                ->unique(ignoreRecord: true)
                ->disabled(fn (string $operation): bool => $operation !== 'create')
                ->dehydrated(fn (string $operation): bool => $operation === 'create'),
            Select::make('parent_id')
                ->label('Parent category')
                ->placeholder('None (top-level category)')
                ->options(fn (?Category $record): array => self::parentOptions($record))
                ->searchable()
                ->live()
                ->afterStateUpdated(function (Set $set, ?string $state, string $operation): void {
                    if ($operation === 'create') {
                        $set('sort_order', app(CategoryHierarchyService::class)->nextSortOrder(blank($state) ? null : (int) $state));
                    }
                })
                ->disabled(fn (?Category $record): bool => $record?->children()->exists() ?? false)
                ->helperText(fn (?Category $record): string => $record?->children()->exists()
                    ? 'This category has subcategories, so it must stay a top-level category.'
                    : 'Only top-level categories can be parents.')
                ->rules([
                    fn (?Category $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        $message = app(CategoryHierarchyService::class)
                            ->parentViolation($record?->getKey(), filled($value) ? (int) $value : null);

                        if ($message !== null) {
                            $fail($message);
                        }
                    },
                ]),
            TextInput::make('sort_order')
                ->helperText('Orders categories among those with the same parent. Lower numbers come first.')
                ->numeric()
                ->integer()
                ->required()
                ->default(fn (): int => app(CategoryHierarchyService::class)->nextSortOrder(null)),
            Toggle::make('is_active')
                ->label('Active')
                ->helperText('Inactive categories are hidden on the website, along with their subcategories.')
                ->default(true)
                ->inline(false),
        ];
    }

    /**
     * Transient "image" state, applied through SetCategoryImage by the create/edit pages.
     *
     * @return list<Component>
     */
    private static function imageFields(): array
    {
        $hasImage = fn (Get $get, ?Category $record): bool => filled($get('image.file')) || filled($get('image.url')) || filled($record?->media_id);

        return [
            ImageEntry::make('current_image')
                ->label('Current image')
                ->state(fn (?Category $record): ?string => $record?->media?->publicUrl())
                ->visible(fn (?Category $record): bool => filled($record?->media_id))
                ->columnSpanFull(),
            FileUpload::make('image.file')
                ->label('Upload image')
                ->helperText('JPG, PNG or WebP, up to 10 MB. Uploading replaces the current image.')
                ->image()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(10240)
                ->rule(Rule::dimensions()->maxWidth(6000)->maxHeight(6000))
                ->storeFiles(false)
                ->live()
                ->columnSpanFull(),
            TextInput::make('image.url')
                ->label('Or remote image URL (https)')
                ->url()
                ->maxLength(2048)
                ->live(onBlur: true),
            TextInput::make('image.credit')->label('Credit / licence')->maxLength(255)->visible(fn (Get $get): bool => filled($get('image.url'))),
            TextInput::make('image.credit_url')->label('Credit link')->url()->maxLength(2048)->visible(fn (Get $get): bool => filled($get('image.url'))),
            TextInput::make('image.alt_text_en')->label('English alt text')->maxLength(255)->required($hasImage),
            TextInput::make('image.alt_text_el')->label('Greek alt text')->maxLength(255)->required($hasImage),
            Textarea::make('image.caption_en')->label('English caption')->rows(2)->maxLength(1000),
            Textarea::make('image.caption_el')->label('Greek caption')->rows(2)->maxLength(1000),
            Toggle::make('image.remove')
                ->label('Remove current image')
                ->helperText('The file is deleted only if no recipe or other category uses it.')
                ->visible(fn (?Category $record): bool => filled($record?->media_id))
                ->dehydratedWhenHidden(false),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function parentOptions(?Category $record): array
    {
        return Category::query()
            ->roots()
            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
            ->with('translations')
            ->ordered()
            ->get()
            ->mapWithKeys(fn (Category $category): array => [
                $category->getKey() => ($category->getTranslation('en', false)?->name ?? $category->code).($category->is_active ? '' : ' (inactive)'),
            ])
            ->all();
    }

    /**
     * @return list<TextInput|Textarea>
     */
    private static function translationFields(string $locale): array
    {
        return [
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
                ->helperText('Suggested from the name. Changing the name later does not change an existing slug.')
                ->required()
                ->maxLength(180)
                ->regex('/^[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*$/u')
                ->unique(
                    table: 'category_translations',
                    column: 'slug',
                    ignorable: fn (?Model $record) => $record?->getTranslation($locale, false),
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('locale', $locale),
                ),
            Textarea::make("{$locale}.description")->label('Description')->rows(3),
        ];
    }
}
