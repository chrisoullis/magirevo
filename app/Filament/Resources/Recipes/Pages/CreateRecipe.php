<?php

namespace App\Filament\Resources\Recipes\Pages;

use App\Filament\Resources\Recipes\Pages\Concerns\SavesRecipeContent;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Models\Recipe;
use App\Services\RecipeQuotaService;
use App\Support\QuotaUsageNotice;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateRecipe extends CreateRecord
{
    use SavesRecipeContent;

    protected static string $resource = RecipeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $data = $this->extractRecipeContent($data);

        try {
            return app(RecipeQuotaService::class)->withinPersonalQuota(auth()->user(), fn (): Recipe => Recipe::query()->create([
                ...$data,
                'author_id' => auth()->id(),
                'status' => 'draft',
            ]));
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title(__('quota.blocked_title'))->body(collect($exception->errors())->flatten()->implode("\n"))->send();

            $this->halt();
        }
    }

    public function getSubheading(): string|Htmlable|null
    {
        return app(QuotaUsageNotice::class)->for(auth()->user());
    }

    protected function afterCreate(): void
    {
        $this->persistRecipeContent($this->getRecord());
    }
}
