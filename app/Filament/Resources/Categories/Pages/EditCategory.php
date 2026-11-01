<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Support\DeletionGuard;
use App\Filament\Support\FillsTranslatedFormData;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCategory extends EditRecord
{
    use FillsTranslatedFormData {
        mutateFormDataBeforeFill as private fillTranslatedFormData;
    }
    use HandlesCategoryImage;

    protected static string $resource = CategoryResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->fillImageFormData($this->fillTranslatedFormData($data), $this->getRecord());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->extractImageData($data);
    }

    protected function afterSave(): void
    {
        $this->applyImageData($this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()->before(DeletionGuard::single()),
        ];
    }
}
