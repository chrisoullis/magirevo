<?php

namespace App\Filament\Resources\RecipeRevisions\Pages;

use App\Filament\Resources\RecipeRevisions\RecipeRevisionResource;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Services\RecipeRevisionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ListRecipeRevisions extends ListRecords
{
    protected static string $resource = RecipeRevisionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startRevision')
                ->label('Start a revision')
                ->schema([
                    Select::make('recipe_id')->label('Public recipe')->required()->searchable()
                        ->options(fn (): array => Recipe::query()->with('translations')->where('status', 'published')->where('visibility', 'public')
                            ->get()
                            ->filter(fn (Recipe $recipe): bool => Gate::allows('createDraft', [RecipeRevision::class, $recipe]))
                            ->mapWithKeys(fn (Recipe $recipe): array => [$recipe->getKey() => (string) ($recipe->displayTranslation(app()->getLocale())?->title ?? $recipe->getKey())])
                            ->all()),
                ])
                ->action(function (array $data): void {
                    $recipe = Recipe::query()->find($data['recipe_id']);

                    try {
                        abort_unless($recipe !== null, 404);
                        $service = app(RecipeRevisionService::class);
                        $revision = $service->openRevision($recipe) ?? $service->createDraft(auth()->user(), $recipe);
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title('Revision could not be started')->body(collect($exception->errors())->flatten()->implode("\n"))->send();

                        return;
                    }

                    $this->redirect(RecipeRevisionResource::getUrl($revision->status->value === 'draft' ? 'edit' : 'view', ['record' => $revision]));
                }),
        ];
    }
}
