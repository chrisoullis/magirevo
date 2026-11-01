<?php

namespace App\Filament\Resources\UserEntitlements\Pages;

use App\Filament\Resources\UserEntitlements\UserEntitlementResource;
use Filament\Resources\Pages\ListRecords;

class ListUserEntitlements extends ListRecords
{
    protected static string $resource = UserEntitlementResource::class;
}
