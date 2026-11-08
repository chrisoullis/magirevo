<?php

namespace App\Filament\Resources\RecipeRevisions\Schemas;

use App\Services\Recipes\RecipeEditorData;
use App\Services\Recipes\RecipeEditorFields;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/** Shared recipe fields edit a draft payload; existing media remains pinned until explicitly replaced. */
class RecipeRevisionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Revision')->columnSpanFull()->tabs([
                Tab::make('English')->schema(RecipeEditorFields::translationFields('en')),
                Tab::make('Ελληνικά')->schema(RecipeEditorFields::translationFields('el')),
                Tab::make('Details')->schema(RecipeEditorFields::detailFields()),
                Tab::make('Ingredients')->schema([
                    RecipeEditorFields::groupsRepeater(),
                    RecipeEditorFields::ingredientsRepeater(),
                ]),
                Tab::make('Media')->schema([RecipeEditorFields::revisionMediaRepeater()]),
                Tab::make('Steps')->schema([...RecipeEditorFields::stepGroupFields(), RecipeEditorFields::stepsRepeater(withMedia: false)]),
            ]),
        ]);
    }

    public static function fromPayload(array $payload): array
    {
        return RecipeEditorData::fromPayload($payload);
    }

    public static function toPayload(array $payload, array $data): array
    {
        return RecipeEditorData::toPayload($payload, $data);
    }
}
