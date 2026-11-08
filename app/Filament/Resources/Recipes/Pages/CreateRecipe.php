<?php

namespace App\Filament\Resources\Recipes\Pages;

use App\Filament\Resources\Recipes\RecipeResource;
use App\Services\Recipes\RecipeEditorService;
use App\Support\QuotaUsageNotice;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateRecipe extends CreateRecord
{
    protected static string $resource = RecipeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(RecipeEditorService::class)->create(auth()->user(), $data);
        } catch (ValidationException $exception) {
            if (! array_key_exists('quota', $exception->errors())) {
                throw $exception;
            }

            Notification::make()->danger()->title(__('quota.blocked_title'))->body(collect($exception->errors())->flatten()->implode("\n"))->send();

            $this->halt();
        }
    }

    public function getSubheading(): string|Htmlable|null
    {
        return app(QuotaUsageNotice::class)->for(auth()->user());
    }
}
