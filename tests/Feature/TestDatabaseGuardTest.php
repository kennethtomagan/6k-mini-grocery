<?php

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

it('runs against the dedicated testing database', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    expect(DB::connection()->getDatabaseName())->toBe('k6_tindahan_testing');
});

it('refuses to run against a database that is not a *_testing database', function () {
    config(['database.connections.guard_probe' => array_merge(config('database.connections.mysql'), ['database' => 'k6_tindahan'])]);

    expect(fn () => $this->ensureTestingDatabase('guard_probe'))->toThrow(RuntimeException::class, 'k6_tindahan');

    DB::purge('guard_probe');
});

it('refuses to run when a connection URL points at a non-testing database', function () {
    config(['database.connections.guard_probe' => array_merge(config('database.connections.mysql'), [
        'database' => 'k6_tindahan_testing',
        'url' => 'mysql://root@127.0.0.1:3306/k6_tindahan',
    ])]);

    expect(fn () => $this->ensureTestingDatabase('guard_probe'))->toThrow(RuntimeException::class, 'k6_tindahan');

    DB::purge('guard_probe');
});

it('leaves the default connection on the testing database after the guard rejects a probe', function () {
    config(['database.connections.guard_probe' => array_merge(config('database.connections.mysql'), ['database' => 'k6_tindahan'])]);

    expect(fn () => $this->ensureTestingDatabase('guard_probe'))->toThrow(RuntimeException::class);
    DB::purge('guard_probe');

    expect(DB::connection()->getDatabaseName())->toBe('k6_tindahan_testing');
});

it('wires the database guard into the test lifecycle', function () {
    expect((new ReflectionMethod(TestCase::class, 'setUpTraits'))->getDeclaringClass()->getName())->toBe(TestCase::class);
});
