<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Lookup codes are stored on other tables, so they must never change once created.
 */
trait HasImmutableCode
{
    protected static function bootHasImmutableCode(): void
    {
        static::updating(function ($model): void {
            if ($model->isDirty('code')) {
                throw new LogicException(class_basename($model).' codes are immutable.');
            }
        });
    }
}
