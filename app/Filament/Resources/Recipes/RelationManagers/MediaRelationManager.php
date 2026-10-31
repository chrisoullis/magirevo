<?php

namespace App\Filament\Resources\Recipes\RelationManagers;

use App\Actions\RemoveRecipeMedia;
use App\Actions\StoreRecipeMedia;
use App\Actions\SyncLegacyRecipeFields;
use App\Models\Media;
use App\Models\Recipe;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static ?string $title = 'Images';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('update', $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('file_path')
            ->modifyQueryUsing(fn ($query) => $query->with('translations'))
            ->columns([
                ImageColumn::make('preview')
                    ->state(fn (Media $record): string => Storage::disk($record->disk)->url($record->file_path))
                    ->height(64),
                TextColumn::make('role')
                    ->state(fn (Media $record): string => $record->pivot->role)
                    ->badge()
                    ->color(fn (string $state): string => $state === 'cover' ? 'success' : 'gray'),
                TextColumn::make('alt_en')->label('Alt text (EN)')->state(fn (Media $record): ?string => $record->getTranslation('en', false)?->alt_text),
                TextColumn::make('alt_el')->label('Alt text (EL)')->state(fn (Media $record): ?string => $record->getTranslation('el', false)?->alt_text),
            ])
            ->defaultSort('recipe_media.sort_order')
            ->headerActions([
                Action::make('upload')
                    ->label('Upload image')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Image')
                            ->required()
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(10240)
                            ->rule(Rule::dimensions()->maxWidth(6000)->maxHeight(6000))
                            ->storeFiles(false),
                        Select::make('role')
                            ->options(['cover' => 'Cover', 'gallery' => 'Gallery'])
                            ->default('gallery')
                            ->required(),
                        TextInput::make('alt_text_en')->label('English alt text')->required()->maxLength(255),
                        Textarea::make('caption_en')->label('English caption')->maxLength(1000),
                        TextInput::make('alt_text_el')->label('Greek alt text')->required()->maxLength(255),
                        Textarea::make('caption_el')->label('Greek caption')->maxLength(1000),
                    ])
                    ->action(function (array $data, StoreRecipeMedia $storeRecipeMedia): void {
                        /** @var Recipe $recipe */
                        $recipe = $this->getOwnerRecord();

                        DB::transaction(function () use ($recipe, $data, $storeRecipeMedia): void {
                            if ($data['role'] === 'cover') {
                                $recipe->media()->wherePivot('role', 'cover')->updateExistingPivot(
                                    $recipe->media()->wherePivot('role', 'cover')->pluck('media.id')->all(),
                                    ['role' => 'gallery'],
                                );
                            }

                            $storeRecipeMedia->handle($recipe, $data['file'], [
                                'en' => ['alt_text' => $data['alt_text_en'], 'caption' => $data['caption_en'] ?? null],
                                'el' => ['alt_text' => $data['alt_text_el'], 'caption' => $data['caption_el'] ?? null],
                            ], $data['role']);
                        });

                        app(SyncLegacyRecipeFields::class)->handle($recipe);
                    }),
            ])
            ->recordActions([
                Action::make('makeCover')
                    ->label('Make cover')
                    ->visible(fn (Media $record): bool => $record->pivot->role !== 'cover')
                    ->action(function (Media $record): void {
                        /** @var Recipe $recipe */
                        $recipe = $this->getOwnerRecord();

                        DB::transaction(function () use ($recipe, $record): void {
                            $currentCovers = $recipe->media()->wherePivot('role', 'cover')->pluck('media.id')->all();

                            if ($currentCovers !== []) {
                                $recipe->media()->updateExistingPivot($currentCovers, ['role' => 'gallery']);
                            }

                            $recipe->media()->updateExistingPivot($record->id, ['role' => 'cover']);
                        });

                        app(SyncLegacyRecipeFields::class)->handle($recipe);
                        Notification::make()->success()->title('Cover image updated')->send();
                    }),
                Action::make('editText')
                    ->label('Edit text')
                    ->fillForm(fn (Media $record): array => [
                        'alt_text_en' => $record->getTranslation('en', false)?->alt_text,
                        'caption_en' => $record->getTranslation('en', false)?->caption,
                        'alt_text_el' => $record->getTranslation('el', false)?->alt_text,
                        'caption_el' => $record->getTranslation('el', false)?->caption,
                    ])
                    ->schema([
                        TextInput::make('alt_text_en')->label('English alt text')->required()->maxLength(255),
                        Textarea::make('caption_en')->label('English caption')->maxLength(1000),
                        TextInput::make('alt_text_el')->label('Greek alt text')->required()->maxLength(255),
                        Textarea::make('caption_el')->label('Greek caption')->maxLength(1000),
                    ])
                    ->action(function (Media $record, array $data): void {
                        $record->fill([
                            'en' => ['alt_text' => $data['alt_text_en'], 'caption' => $data['caption_en'] ?? null],
                            'el' => ['alt_text' => $data['alt_text_el'], 'caption' => $data['caption_el'] ?? null],
                        ])->save();
                    }),
                Action::make('remove')
                    ->label('Remove')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Media $record, RemoveRecipeMedia $removeRecipeMedia): void {
                        /** @var Recipe $recipe */
                        $recipe = $this->getOwnerRecord();

                        $removeRecipeMedia->handle($recipe, $record);
                        app(SyncLegacyRecipeFields::class)->handle($recipe);
                    }),
            ]);
    }
}
