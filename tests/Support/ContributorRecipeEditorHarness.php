<?php

namespace Tests\Support;

use App\Services\Recipes\RecipeEditorFields;
use App\Services\Recipes\RecipeEditorService;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;

/** Exercises shared forms outside an admin resource, without adding a production route. */
class ContributorRecipeEditorHarness extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use RestrictsFileUploadsToSchemaComponents;

    public array $data = [];

    public ?int $savedRecipeId = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            ...RecipeEditorFields::translationFields('en'),
            ...RecipeEditorFields::translationFields('el'),
            ...RecipeEditorFields::detailFields(),
            RecipeEditorFields::groupsRepeater(),
            RecipeEditorFields::ingredientsRepeater(),
            ...RecipeEditorFields::stepGroupFields(),
            RecipeEditorFields::stepsRepeater(),
        ]);
    }

    public function save(): void
    {
        $this->savedRecipeId = app(RecipeEditorService::class)->create(auth()->user(), $this->form->getState())->id;
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
