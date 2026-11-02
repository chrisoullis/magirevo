<?php

namespace App\Filament\Resources\Recipes\Pages;

use App\Enums\RecipeRevisionStatus;
use App\Enums\RecipeVisibility;
use App\Filament\Resources\RecipeRevisions\RecipeRevisionResource;
use App\Filament\Resources\Recipes\Actions\ReviewActions;
use App\Filament\Resources\Recipes\Pages\Concerns\SavesRecipeContent;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Services\RecipeAccessService;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditRecipe extends EditRecord
{
    use SavesRecipeContent;

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

        foreach (config('translatable.locales') as $locale) {
            $translation = $recipe->getTranslation($locale, false);

            $data[$locale] = $translation?->only(['title', 'slug', 'summary', 'introduction', 'seo_title', 'seo_description']) ?? [];
        }

        $data['steps_data'] = $recipe->steps()->with(['translations', 'media.translations'])->get()
            ->map(function ($step): array {
                $images = $step->media
                    ->where('pivot.kind', 'image')
                    ->where('media_type', Media::TYPE_IMAGE);
                $video = $step->media->first(fn (Media $media): bool => $media->pivot->kind === 'video' && $media->media_type === Media::TYPE_VIDEO);

                return [
                    'id' => $step->id,
                    'instruction_en' => $step->getTranslation('en', false)?->instruction,
                    'instruction_el' => $step->getTranslation('el', false)?->instruction,
                    'duration_minutes' => $step->duration_minutes,
                    'media_items' => $images->map(fn (Media $media): array => [
                        'media_id' => $media->id,
                        'alt_text_en' => $media->getTranslation('en', false)?->alt_text,
                        'caption_en' => $media->getTranslation('en', false)?->caption,
                        'alt_text_el' => $media->getTranslation('el', false)?->alt_text,
                        'caption_el' => $media->getTranslation('el', false)?->caption,
                    ])->values()->all(),
                    'video_url' => $video?->url,
                    'video_alt_text_en' => $video?->getTranslation('en', false)?->alt_text,
                    'video_caption_en' => $video?->getTranslation('en', false)?->caption,
                    'video_alt_text_el' => $video?->getTranslation('el', false)?->alt_text,
                    'video_caption_el' => $video?->getTranslation('el', false)?->caption,
                ];
            })->all();

        $data['ingredients_data'] = $recipe->recipeIngredients()->with('translations')->orderBy('sort_order')->get()
            ->map(fn ($line): array => [
                'id' => $line->id,
                'ingredient_id' => $line->ingredient_id,
                'quantity' => $line->quantity !== null ? (float) $line->quantity : null,
                'quantity_max' => $line->quantity_max !== null ? (float) $line->quantity_max : null,
                'unit_id' => $line->unit_id,
                'scaling_mode' => $line->scaling_mode,
                'is_optional' => $line->is_optional,
                'quantity_text_en' => $line->getTranslation('en', false)?->quantity_text,
                'quantity_text_el' => $line->getTranslation('el', false)?->quantity_text,
                'preparation_note_en' => $line->getTranslation('en', false)?->preparation_note,
                'preparation_note_el' => $line->getTranslation('el', false)?->preparation_note,
            ])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $data = $this->extractRecipeContent($data);

        return DB::transaction(function () use ($record, $data): Model {
            $record->update($data);

            return $record;
        });
    }

    protected function afterSave(): void
    {
        $this->persistRecipeContent($this->getRecord());
    }
}
