<?php

namespace App\Filament\Resources\Recipes\Pages;

use App\Enums\RecipeRevisionStatus;
use App\Enums\RecipeVisibility;
use App\Filament\Resources\RecipeRevisions\RecipeRevisionResource;
use App\Filament\Resources\Recipes\Actions\ReviewActions;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\RecipeRevisionAudit;
use App\Services\RecipeAccessService;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use App\Services\Recipes\RecipeEditorData;
use App\Services\Recipes\RecipeEditorService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class EditRecipe extends EditRecord
{
    #[Locked]
    public string $editorVersion = '';

    protected static string $resource = RecipeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->startRevisionAction(),
            $this->workflowAction('submitForReview', 'Submit for review', 'warning', ['draft', 'published'], fn (RecipePublishingService $service, Recipe $recipe) => $service->submit(auth()->user(), $recipe), 'English and Greek content, ingredients, steps and a cover image are required. You cannot edit the recipe while it is in review.'),
            ReviewActions::approve(),
            ReviewActions::reject(),
            $this->workflowAction('withdraw', 'Withdraw to edit', 'gray', ['pending', 'published'], fn (RecipePublishingService $service, Recipe $recipe) => $service->withdraw(auth()->user(), $recipe), 'Takes the recipe out of review or off the public site so you can edit it. It must be reviewed again before it is public.'),
            $this->workflowAction('publish', 'Publish', 'success', ['draft', 'pending'], fn (RecipePublishingService $service, Recipe $recipe) => $service->publishWithCompletenessChecks($recipe), 'Saved changes only: save the form first.'),
            $this->workflowAction('unpublish', 'Unpublish', 'gray', ['published'], fn (RecipePublishingService $service, Recipe $recipe) => $service->unpublish($recipe), null, 'publish'),
            $this->workflowAction('archive', 'Archive', 'gray', ['draft', 'published'], fn (RecipePublishingService $service, Recipe $recipe) => $service->archive($recipe)),
            $this->workflowAction('restoreToDraft', 'Restore to draft', 'gray', ['archived'], fn (RecipePublishingService $service, Recipe $recipe) => $service->restoreToDraft($recipe), null, 'archive'),
            $this->visibilityAction('makePrivate', 'Make private', RecipeVisibility::Private),
            $this->visibilityAction('makeShared', 'Make shared', RecipeVisibility::Shared),
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    private function isLiveRecipe(): bool
    {
        return app(RecipeRevisionService::class)->isLive($this->getRecord());
    }

    /**
     * Public recipes are only changed through revisions, so this opens (or continues) the revision draft instead.
     */
    private function startRevisionAction(): Action
    {
        return Action::make('startRevision')
            ->label(fn (): string => app(RecipeRevisionService::class)->openRevision($this->getRecord()) ? 'Continue revision' : 'Start revision draft')
            ->color('primary')
            ->visible(fn (Recipe $record): bool => app(RecipeRevisionService::class)->isLive($record) && (auth()->user()?->can('createDraft', [RecipeRevision::class, $record]) ?? false))
            ->action(function (Recipe $record): void {
                $service = app(RecipeRevisionService::class);

                try {
                    $revision = $service->openRevision($record) ?? $service->createDraft(auth()->user(), $record);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Revision could not be started')->body(collect($exception->errors())->flatten()->implode("\n"))->send();

                    return;
                }

                $this->redirect(RecipeRevisionResource::getUrl($revision->status === RecipeRevisionStatus::Draft ? 'edit' : 'view', ['record' => $revision]));
            });
    }

    protected function beforeSave(): void
    {
        if ($this->isLiveRecipe()) {
            Notification::make()->danger()->title('This recipe is public')->body('Public recipes are changed through a revision draft, which is reviewed before it replaces the published version.')->persistent()->send();

            $this->halt();
        }
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        app(RecipeAccessService::class)->recordModerationAccess(auth()->user(), $this->getRecord(), 'panel');
    }

    private function visibilityAction(string $name, string $label, RecipeVisibility $to): Action
    {
        return Action::make($name)
            ->label($label)
            ->color('gray')
            ->authorize(fn (Recipe $record): bool => Gate::allows('changeVisibility', [$record, $to]))
            ->visible(fn (Recipe $record): bool => ! $record->trashed() && $record->visibility !== $to)
            ->requiresConfirmation()
            ->action(function (Recipe $record) use ($to, $label): void {
                app(RecipePublishingService::class)->changeVisibility($record, $to);

                $this->refreshFormData(['visibility']);

                Notification::make()->success()->title("{$label}: done")->send();
            });
    }

    /**
     * @param  list<string>  $fromStatuses
     * @param  ?string  $ability  Policy ability to check; defaults to the action name.
     */
    private function workflowAction(string $name, string $label, string $color, array $fromStatuses, callable $transition, ?string $description = null, ?string $ability = null): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->authorize($ability ?? $name)
            ->visible(fn (Recipe $record): bool => ! $record->trashed() && in_array($record->status, $fromStatuses, true))
            ->requiresConfirmation()
            ->modalDescription($description)
            ->action(function (Recipe $record) use ($transition, $label): void {
                try {
                    $transition(app(RecipePublishingService::class), $record);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title("{$label} failed")
                        ->body(collect($exception->errors())->flatten()->implode("\n"))
                        ->send();

                    return;
                }

                $this->refreshFormData(['status', 'visibility', 'published_at']);

                Notification::make()->success()->title("{$label}: done")->send();
            });
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Recipe $recipe */
        $recipe = $this->getRecord();

        $this->editorVersion = app(RecipeEditorService::class)->version($recipe);

        return [...$data, ...RecipeEditorData::fromRecipe($recipe)];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $updated = app(RecipeEditorService::class)->update(auth()->user(), $record, $data, $this->editorVersion);
        $this->editorVersion = app(RecipeEditorService::class)->version($updated);
        if ($record->derived_from_recipe_id !== null) {
            RecipeRevisionAudit::query()->create(['recipe_id' => $record->id, 'actor_id' => auth()->id(), 'action' => 'variation_edited']);
        }

        return $updated;
    }
}
