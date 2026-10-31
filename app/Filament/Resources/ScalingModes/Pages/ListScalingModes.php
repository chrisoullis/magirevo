<?php

namespace App\Filament\Resources\ScalingModes\Pages;

use App\Filament\Resources\ScalingModes\ScalingModeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListScalingModes extends ListRecords
{
    protected static string $resource = ScalingModeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
