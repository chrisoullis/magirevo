<?php

namespace App\Filament\Resources\Ingredients\Pages;

use App\Filament\Resources\Ingredients\IngredientResource;
use App\Models\Ingredient;
use App\Services\IngredientTextImport;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;

class ListIngredients extends ListRecords
{
    protected static string $resource = IngredientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('importText')->label('Import ingredient text')->visible(fn (): bool => auth()->user()?->can('create', Ingredient::class) ?? false)
                ->modalDescription('Import up to 100 JSON records. Preview all changes before applying. Images, units, aliases and dietary evidence are excluded.')
                ->steps([
                    Step::make('JSON records')->schema([
                        Textarea::make('payload')->label('Ingredient records (JSON array)')->required()->json()->maxLength(200000)->rows(14)
                            ->helperText('[{"code":"example","group_code":"vegetables","en":{"name":"Example"},"el":{"name":"Παράδειγμα"}}]'),
                    ])->afterValidation(function (Get $get, Set $set, IngredientTextImport $import): void {
                        $preview = $import->preview(auth()->user(), $get('payload'));
                        $set('preview', json_encode($preview['changes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        $set('token', $preview['token']);
                    }),
                    Step::make('Review changes')->schema([
                        Textarea::make('preview')->label('Proposed changes')->disabled()->dehydrated(false)->rows(20),
                        Hidden::make('token')->required(),
                    ]),
                ])->action(function (array $data, IngredientTextImport $import): void {
                    $count = $import->apply(auth()->user(), $data['payload'], $data['token'] ?? '');
                    Notification::make()->success()->title("Imported {$count} ingredient records")->send();
                }),
        ];
    }
}
