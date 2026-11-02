<?php

namespace App\Filament\Resources\RecipeRevisions\Pages;

use App\Filament\Resources\RecipeRevisions\RecipeRevisionResource;
use App\Filament\Resources\RecipeRevisions\Schemas\RecipeRevisionForm;
use App\Models\RecipeRevision;
use App\Services\RecipeRevisionService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Edits the payload of a draft revision. The published recipe is not touched; saving goes through the service so
 * ownership, the draft status and the audit trail are enforced in one place.
 */
class EditRecipeRevision extends EditRecord
{
    protected static string $resource = RecipeRevisionResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var RecipeRevision $revision */
        $revision = $this->getRecord();

        return RecipeRevisionForm::fromPayload($revision->payload);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(RecipeRevisionService::class)->saveDraft(auth()->user(), $record, RecipeRevisionForm::toPayload($record->payload, $data));
    }

    protected function getRedirectUrl(): string
    {
        return RecipeRevisionResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
