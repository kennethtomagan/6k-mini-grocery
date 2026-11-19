<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Guard against wiping a real database: RefreshDatabase drops every table, and this
     * MySQL server also hosts other databases. Tests only ever run on a *_testing database.
     */
    protected function setUpTraits(): array
    {
        $this->ensureTestingDatabase();

        return parent::setUpTraits();
    }

    /**
     * Throws unless the connection's resolved database name (including any DB_URL override)
     * ends in "_testing". Resolving the connection is lazy and runs no query.
     */
    protected function ensureTestingDatabase(?string $connection = null): void
    {
        $connection ??= (string) config('database.default');
        $database = (string) DB::connection($connection)->getDatabaseName();

        if (! str_ends_with($database, '_testing')) {
            throw new RuntimeException("Refusing to run tests against database [{$database}] on connection [{$connection}]. Tests must use a *_testing database — run `php artisan config:clear` and check phpunit.xml.");
        }
    }
}
