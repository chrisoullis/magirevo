<?php

namespace Tests;

use Database\Seeders\DifficultySeeder;
use Database\Seeders\ScalingModeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Recipes reference lookup codes by foreign key, and migrations no longer insert them.
        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            $this->seed([DifficultySeeder::class, ScalingModeSeeder::class]);
        }
    }
}
