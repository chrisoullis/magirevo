<?php

namespace App\Filament\Resources\ContributorRequests\Pages;

use App\Filament\Resources\ContributorRequests\ContributorRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListContributorRequests extends ListRecords
{
    protected static string $resource = ContributorRequestResource::class;
}
