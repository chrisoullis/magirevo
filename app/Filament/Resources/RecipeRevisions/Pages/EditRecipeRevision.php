<?php

namespace App\Filament\Resources\RecipeRevisions\Pages;

use App\Filament\Resources\RecipeRevisions\RecipeRevisionResource;
use App\Filament\Resources\RecipeRevisions\Schemas\RecipeRevisionForm;
use App\Models\RecipeRevision;
use App\Services\RecipeRevisionService;
use App\Services\Recipes\RecipeEditorFields;
use App\Services\Recipes\RecipeEditorMedia;
use App\Services\Recipes\RecipeEditorService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/**
 * Edits the payload of a draft revision. The published recipe is not touched; saving goes through the service so
 * ownership, the draft status and the audit trail are enforced in one place.
 */
class EditRecipeRevision extends EditRecord
{
    #[Locked]
    public string $editorVersion = '';

    protected static string $resource = RecipeRevisionResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var RecipeRevision $revision */
        $revision = $this->getRecord();

        $this->editorVersion = app(RecipeRevisionService::class)->draftVersion($revision);

        return RecipeRevisionForm::fromPayload($revision->payload);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $revision = app(RecipeEditorService::class)->saveRevision(auth()->user(), $record, $data, $this->editorVersion);
        $this->editorVersion = app(RecipeRevisionService::class)->draftVersion($revision);

        return $revision;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('uploadRevisionImage')->label('Add revision photograph')
                ->authorize(fn (): bool => auth()->user()->can('edit', $this->getRecord()))
                ->schema([
                    ...RecipeEditorFields::imageUploadFields(),
                    Select::make('role')->options(['cover' => 'Cover', 'gallery' => 'Gallery', 'step' => 'Step'])->default('gallery')->required(),
                    Select::make('step_index')->label('Step (for step photographs)')->options(fn (): array => collect($this->getRecord()->payload['steps'])->mapWithKeys(fn ($step, int $index): array => [$index => (string) ($index + 1)])->all()),
                    Toggle::make('rights_confirmed')->label('I have permission to use this photograph')->accepted(),
                ])
                ->action(function (array $data): void {
                    // Save visible fields first so a media action never discards unsaved text or ordering.
                    $record = $this->handleRecordUpdate($this->getRecord(), $this->form->getState());
                    $revision = app(RecipeEditorMedia::class)->upload(auth()->user(), $record, $data['file'], [
                        'en' => ['alt_text' => $data['alt_text_en'], 'caption' => $data['caption_en'] ?? null],
                        'el' => ['alt_text' => $data['alt_text_el'], 'caption' => $data['caption_el'] ?? null],
                    ], $data['role'], $this->editorVersion, (bool) $data['rights_confirmed'], isset($data['step_index']) ? (int) $data['step_index'] : null);
                    $this->editorVersion = app(RecipeRevisionService::class)->draftVersion($revision);
                    $this->getRecord()->refresh();
                    $this->fillForm();
                    Notification::make()->success()->title('Revision photograph saved')->send();
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return RecipeRevisionResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
