<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/** For command contracts whose --allow-testing boundary explicitly requires SQLite. */
trait UsesIsolatedSqliteDatabase
{
    use RefreshDatabase;

    private ?array $sqliteFixtureState = null;

    protected function beforeRefreshingDatabase(): void
    {
        $this->sqliteFixtureState = [
            'default' => config('database.default'),
            'sqlite' => config('database.connections.sqlite'),
            'migrated' => RefreshDatabaseState::$migrated,
            'memory' => RefreshDatabaseState::$inMemoryConnections,
        ];
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        ]);
        DB::purge('sqlite');
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
    }

    protected function afterRefreshingDatabase(): void
    {
        // Restore after RefreshDatabase has rolled back its SQLite transaction.
        $this->beforeApplicationDestroyed(function (): void {
            DB::purge('sqlite');
            config([
                'database.default' => $this->sqliteFixtureState['default'],
                'database.connections.sqlite' => $this->sqliteFixtureState['sqlite'],
            ]);
            RefreshDatabaseState::$migrated = $this->sqliteFixtureState['migrated'];
            RefreshDatabaseState::$inMemoryConnections = $this->sqliteFixtureState['memory'];
        });
    }
}
