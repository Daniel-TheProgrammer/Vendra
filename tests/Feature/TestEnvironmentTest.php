<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the suite itself: tests refresh the database they run against, so
 * they must never be able to reach a development or production one.
 */
final class TestEnvironmentTest extends TestCase
{
    #[Test]
    public function the_suite_runs_isolated_from_development_data(): void
    {
        self::assertSame('testing', $this->app->environment());
        self::assertSame('vendra_test', config('database.connections.pgsql.database'));
        self::assertSame('array', config('cache.default'));
        self::assertSame('sync', config('queue.default'));
        self::assertSame('array', config('mail.default'));
    }

    #[Test]
    public function the_database_connection_really_is_the_test_database(): void
    {
        self::assertSame('vendra_test', DB::selectOne('SELECT current_database() AS name')?->name);
    }
}
