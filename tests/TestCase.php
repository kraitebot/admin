<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Admin shares its DB with the kraite ecosystem; schema is owned by
     * kraitebot/core. Limit test migrations to admin's three scaffold
     * migrations so SQLite in-memory tests don't try to run MySQL-only
     * core migrations.
     */
    protected function migrateFreshUsing()
    {
        return [
            '--path' => 'database/migrations',
            '--realpath' => false,
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
        ];
    }
}
