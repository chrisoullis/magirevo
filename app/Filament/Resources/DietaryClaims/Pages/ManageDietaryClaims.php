<?php

namespace App\Filament\Resources\DietaryClaims\Pages;

use App\Filament\Resources\DietaryClaims\DietaryClaimResource;
use Filament\Resources\Pages\ManageRecords;

class ManageDietaryClaims extends ManageRecords
{
    protected static string $resource = DietaryClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
