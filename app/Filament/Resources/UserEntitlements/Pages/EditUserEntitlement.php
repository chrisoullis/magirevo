<?php

namespace App\Filament\Resources\UserEntitlements\Pages;

use App\Filament\Resources\UserEntitlements\UserEntitlementResource;
use App\Services\RecipeQuotaService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUserEntitlement extends EditRecord
{
    protected static string $resource = UserEntitlementResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(RecipeQuotaService::class)->updateEntitlement(
            auth()->user(),
            $record,
            (int) $data['plan_id'],
            filled($data['personal_recipe_limit_override'] ?? null) ? (int) $data['personal_recipe_limit_override'] : null,
            filled($data['sharing_limit_override'] ?? null) ? (int) $data['sharing_limit_override'] : null,
        );
    }
}
