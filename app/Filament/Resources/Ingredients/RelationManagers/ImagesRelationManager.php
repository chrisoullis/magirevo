<?php

namespace App\Filament\Resources\Ingredients\RelationManagers;

use App\Models\IngredientMedia;
use App\Models\Media;
use App\Models\RecipeRevisionMedia;
use App\Services\IngredientImageService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'imageAssignments';

    protected static ?string $title = 'Photographs';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['media.translations', 'thumbnail', 'rightsConfirmedBy']))
            ->defaultSort('sort_order')->columns([
                ImageColumn::make('preview')->state(fn (IngredientMedia $record): string => ($record->thumbnail ?? $record->media)->publicUrl())->height(64)
                    ->extraImgAttributes(fn (IngredientMedia $record): array => ['loading' => 'lazy', 'alt' => $record->media->translate(app()->getLocale())?->alt_text ?? '']),
                TextColumn::make('role')->badge(),
                TextColumn::make('alt_en')->state(fn (IngredientMedia $record): ?string => $record->media->translate('en')?->alt_text),
                TextColumn::make('alt_el')->state(fn (IngredientMedia $record): ?string => $record->media->translate('el')?->alt_text),
                TextColumn::make('rights_basis')->label('Usage rights'),
                TextColumn::make('rights_reference')->limit(70),
                TextColumn::make('rightsConfirmedBy.name')->label('Confirmed by'),
            ])->headerActions([
                Action::make('upload')->label('Upload photograph')->visible(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->schema([
                        FileUpload::make('file')->required()->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(10240)
                            ->rule(Rule::dimensions()->maxWidth(6000)->maxHeight(6000))->storeFiles(false),
                        ...self::assignmentFields(),
                    ])->action(fn (array $data, IngredientImageService $service) => $service->upload(auth()->user(), $this->getOwnerRecord(), $data['file'], $data)),
                Action::make('assign')->label('Copy from ingredient photo library')->visible(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->schema([
                        Select::make('media_id')->label('Ingredient photograph')->searchable()->required()
                            ->options(fn (): array => Media::query()->where('source', 'local')->where('disk', 'local')->where('media_type', Media::TYPE_IMAGE)
                                ->where('file_path', 'like', 'ingredient-media/%')->whereHas('ingredientImages')->whereDoesntHave('recipes')->whereDoesntHave('recipeSteps')
                                ->whereDoesntHave('categories')->whereNotIn('id', RecipeRevisionMedia::query()->select('media_id'))->with('translations')->orderByDesc('id')->limit(100)->get()
                                ->mapWithKeys(fn (Media $media): array => [$media->id => ($media->translate(app()->getLocale())?->alt_text ?? 'Photograph').' (#'.$media->id.')'])->all())
                            ->helperText('Copies a dedicated ingredient image. Confirm that your usage rights also cover this assignment.'),
                        ...self::assignmentFields(),
                    ])->action(fn (array $data, IngredientImageService $service) => $service->assign(auth()->user(), $this->getOwnerRecord(), Media::query()->findOrFail($data['media_id']), $data)),
            ])->recordActions([
                Action::make('primary')->label('Make primary')->visible(fn (IngredientMedia $record): bool => $record->role !== 'primary' && Gate::allows('update', $this->getOwnerRecord()))
                    ->action(fn (IngredientMedia $record, IngredientImageService $service) => $service->promote(auth()->user(), $this->getOwnerRecord(), $record->id)),
                Action::make('editText')->label('Edit alt text / captions')->visible(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->schema(self::textFields())->fillForm(fn (IngredientMedia $record): array => [
                        'alt_text_en' => $record->media->translate('en')?->alt_text, 'alt_text_el' => $record->media->translate('el')?->alt_text,
                        'caption_en' => $record->media->translate('en')?->caption, 'caption_el' => $record->media->translate('el')?->caption,
                    ])->action(fn (IngredientMedia $record, array $data, IngredientImageService $service) => $service->updateText(auth()->user(), $this->getOwnerRecord(), $record->id, $data)),
                Action::make('remove')->label('Remove photograph')->color('danger')->requiresConfirmation()->visible(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->action(fn (IngredientMedia $record, IngredientImageService $service) => $service->remove(auth()->user(), $this->getOwnerRecord(), $record->id)),
            ]);
    }

    /** @return array<int, mixed> */
    private static function assignmentFields(): array
    {
        return [
            Select::make('role')->options(['primary' => 'Primary photograph', 'gallery' => 'Additional photograph'])->default('primary')->required(),
            Select::make('rights_basis')->label('Authorized usage basis')->options(['own_work' => 'Own photograph', 'licensed' => 'Licence', 'permission' => 'Written permission', 'public_domain' => 'Public domain'])->required(),
            Textarea::make('rights_reference')->label('Rights reference / evidence')->required()->maxLength(2000)->helperText('Record the creator, licence or written permission reference.'),
            ...self::textFields(),
        ];
    }

    /** @return array<int, mixed> */
    private static function textFields(): array
    {
        return [TextInput::make('alt_text_en')->label('English alt text')->required()->maxLength(255), TextInput::make('alt_text_el')->label('Greek alt text')->required()->maxLength(255), Textarea::make('caption_en')->label('English caption')->maxLength(1000), Textarea::make('caption_el')->label('Greek caption')->maxLength(1000)];
    }
}
