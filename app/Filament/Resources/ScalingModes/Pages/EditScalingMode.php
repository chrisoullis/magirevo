<?php

namespace App\Filament\Resources\ScalingModes\Pages;

use App\Filament\Resources\ScalingModes\ScalingModeResource;
use App\Filament\Support\DeletionGuard;
use App\Filament\Support\FillsTranslatedFormData;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditScalingMode extends EditRecord
{
    use FillsTranslatedFormData;

    protected static string $resource = ScalingModeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->before(DeletionGuard::single()),
        ];
    }
}
