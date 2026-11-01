<?php

namespace App\Filament\Resources\Recipes\Actions;

use App\Models\Recipe;
use App\Services\RecipePublishingService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Approve and reject, shared by the recipe list and the edit page. Both only call the workflow service,
 * which authorizes the reviewer and enforces the rules.
 */
class ReviewActions
{
    public static function approve(): Action
    {
        return Action::make('approve')
            ->label('Approve and publish')
            ->color('success')
            ->authorize('approve')
            ->visible(fn (Recipe $record): bool => ! $record->trashed() && $record->status === 'pending')
            ->requiresConfirmation()
            ->modalDescription('Publishes the recipe publicly. English and Greek content, ingredients, steps and a cover image are required.')
            ->action(fn (Recipe $record, $livewire) => self::run('Recipe approved', $livewire, fn () => app(RecipePublishingService::class)->approve($record, auth()->user())));
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->color('danger')
            ->authorize('reject')
            ->visible(fn (Recipe $record): bool => ! $record->trashed() && $record->status === 'pending')
            ->schema([Textarea::make('rejection_reason')->label('Reason (shown to the author)')->required()->maxLength(1000)])
            ->action(fn (Recipe $record, array $data, $livewire) => self::run('Recipe rejected', $livewire, fn () => app(RecipePublishingService::class)->reject($record, auth()->user(), $data['rejection_reason'])));
    }

    private static function run(string $success, mixed $livewire, Closure $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('Review failed')
                ->body(collect($exception->errors())->flatten()->implode("\n"))
                ->send();

            return;
        }

        if (method_exists($livewire, 'refreshFormData')) {
            $livewire->refreshFormData(['status', 'visibility', 'published_at']);
        }

        Notification::make()->success()->title($success)->send();
    }
}
