<?php

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Event\Facade;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

/**
 * Opt-in runner for release checks against an already migrated test database.
 * Skip RefreshDatabase's migrate:fresh, retaining its per-test begin/rollback transactions.
 * Explicit reset commands in test bodies/teardown are not intercepted: never include those test classes.
 */
require __DIR__.'/../../vendor/autoload.php';

RefreshDatabaseState::$migrated = true;

Facade::instance()->registerSubscriber(new class implements PreparationStartedSubscriber
{
    public function notify(PreparationStarted $event): void
    {
        RefreshDatabaseState::$migrated = true;
    }
});
