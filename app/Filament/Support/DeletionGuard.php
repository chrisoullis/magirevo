<?php

namespace App\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Blocks deleting records that recipes still depend on. Models expose isInUse().
 */
class DeletionGuard
{
    public static function single(): Closure
    {
        return function (Action $action, Model $record): void {
            if ($record->isInUse()) {
                self::reject($action);
            }
        };
    }

    public static function bulk(): Closure
    {
        return function (Action $action, Collection $records): void {
            if ($records->contains(fn (Model $record): bool => $record->isInUse())) {
                self::reject($action);
            }
        };
    }

    private static function reject(Action $action): void
    {
        Notification::make()
            ->danger()
            ->title('Cannot delete a record that is in use')
            ->body('Other records still use it. Deactivate it instead, or remove those references first.')
            ->send();

        $action->cancel();
    }
}
