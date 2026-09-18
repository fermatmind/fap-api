<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AccessTestStatisticsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_converges_after_a_partial_or_repeated_run(): void
    {
        $migration = require database_path(
            'migrations/2026_09_18_130000_create_access_test_statistics_tables.php'
        );

        $migration->up();

        $this->assertTrue(Schema::hasColumns('attempts', [
            'analytics_start_ip_hash',
            'analytics_submit_ip_hash',
            'analytics_rule_version',
        ]));
        $this->assertTrue(Schema::hasColumns('events', [
            'analytics_ip_hash',
            'analytics_rule_version',
        ]));
        $this->assertTrue(Schema::hasTable('analytics_access_test_daily'));

        DB::table('analytics_access_test_daily')->insert([
            'day' => '2026-09-18',
            'source_version' => 'access_test_statistics.v1',
        ]);

        $this->assertDatabaseHas('analytics_access_test_daily', [
            'day' => '2026-09-18',
            'last_successful_refresh_at' => null,
        ]);
    }
}
