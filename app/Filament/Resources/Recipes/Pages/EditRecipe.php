<?php

namespace App\Filament\Resources\Recipes\Pages;

use App\Filament\Resources\Recipes\Pages\Concerns\SavesRecipeContent;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Models\Recipe;
use App\Services\RecipePublishingService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditRecipe extends EditRecord
{
    use SavesRecipeContent;

    protected static string $resource = RecipeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->workflowAction('submitForReview', 'Submit for review', 'warning', ['draft'], fn (RecipePublishingService $service, Recipe $recipe) => $service->submitForReview($recipe)),
            $this->workflowAction('publish', 'Publish', 'success', ['draft', 'pending'], fn (RecipePublishingService $service, Recipe $recipe) => $service->publishWithCompletenessChecks($recipe), 'Saved changes only: save the form first.'),
            $this->workflowAction('unpublish', 'Unpublish', 'gray', ['published'], fn (RecipePublishingService $service, Recipe $recipe) => $service->unpublish($recipe), null, 'publish'),
            $this->workflowAction('archive', 'Archive', 'gray', ['draft', 'published'], fn (RecipePublishingService $service, Recipe $recipe) => $service->archive($recipe)),
            $this->workflowAction('restoreToDraft', 'Restore to draft', 'gray', ['archived'], fn (RecipePublishingService $service, Recipe $recipe) => $service->restoreToDraft($recipe), null, 'archive'),
            DeleteAction::make(),
            RestoreAction::make(),
        ];
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

                $this->refreshFormData(['status', 'published_at']);

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

        $data['steps_data'] = $recipe->steps()->with('translations')->get()
            ->map(fn ($step): array => [
                'id' => $step->id,
                'instruction_en' => $step->getTranslation('en', false)?->instruction,
                'instruction_el' => $step->getTranslation('el', false)?->instruction,
                'duration_minutes' => $step->duration_minutes,
            ])->all();

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
