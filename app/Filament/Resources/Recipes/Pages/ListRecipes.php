<?php

namespace App\Filament\Resources\Recipes\Pages;

use App\Filament\Resources\Recipes\RecipeResource;
use App\Support\QuotaUsageNotice;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListRecipes extends ListRecords
{
    protected static string $resource = RecipeResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return app(QuotaUsageNotice::class)->for(auth()->user());
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
