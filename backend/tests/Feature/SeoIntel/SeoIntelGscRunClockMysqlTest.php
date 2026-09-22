<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoIntel\GscRunStartTime;
use App\Services\SeoIntel\OpsDashboard\SeoDashboardApiReadService;
use App\Services\SeoIntel\OpsDashboard\SeoSchedulerReceiptReadService;
use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SeoIntelGscRunClockMysqlTest extends TestCase
{
    private bool $ownsTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        $socket = getenv('SEO_TEST_MYSQL_SOCKET');
        $database = getenv('SEO_TEST_MYSQL_DATABASE');
        if (! is_string($socket) || $socket === '' || ! is_string($database) || $database === '') {
            $this->markTestSkipped('Requires an isolated local MySQL database and socket.');
        }
        $this->assertMatchesRegularExpression('/^seo_operations_[a-z0-9_]+_test$/D', $database);
        config(['database.connections.seo_intel' => [
            'driver' => 'mysql', 'unix_socket' => $socket, 'database' => $database,
            'username' => 'root', 'password' => '', 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ], 'seo_intel.connection' => 'seo_intel']);
        DB::purge('seo_intel');
        foreach (['seo_gsc_sync_runs', 'seo_gsc_data_quality_queue', 'seo_gsc_daily'] as $table) {
            $this->assertFalse(Schema::connection('seo_intel')->hasTable($table), 'Requires a database without existing GSC tables.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->ownsTables) {
            Schema::connection('seo_intel')->drop('seo_gsc_sync_runs');
            Schema::connection('seo_intel')->drop('seo_gsc_data_quality_queue');
        }
        DB::purge('seo_intel');
        parent::tearDown();
    }

    public static function clockModes(): array
    {
        return ['utc implicit' => ['+00:00', 0], 'utc explicit' => ['+00:00', 1],
            'beijing implicit' => ['+08:00', 0], 'beijing explicit' => ['+08:00', 1]];
    }

    #[DataProvider('clockModes')]
    public function test_expand_preserves_history_and_new_run_start_in_every_clock_mode(string $timezone, int $explicitDefaults): void
    {
        $connection = DB::connection('seo_intel');
        $connection->statement('SET SESSION time_zone = ?', [$timezone]);
        $connection->statement('SET SESSION explicit_defaults_for_timestamp = '.$explicitDefaults);
        (require database_path('migrations/seo_intel/2026_08_23_140000_expand_gsc_read_models.php'))->up();
        $this->ownsTables = true;
        (require database_path('migrations/seo_intel/2026_08_25_050000_expand_gsc_sync_run_receipts.php'))->up();
        $table = $connection->table('seo_gsc_sync_runs');
        $old = $this->runRow('00000000-0000-4000-8000-000000000001', '2026-09-20 10:00:00');
        $table->insert($old);
        (clone $table)->where('sync_run_uid', $old['sync_run_uid'])->update([
            'status' => 'success', 'finished_at' => '2026-09-20 10:01:00',
            'receipt_json' => json_encode(['schema_version' => 'seo.gsc_refresh_receipt.v2', 'status' => 'success', 'history' => true]),
        ]);
        $oldStored = (array) (clone $table)->where('sync_run_uid', $old['sync_run_uid'])->first();
        $historyHash = app(SeoRegistryHasher::class)->hash($oldStored);
        $latest = GscRunStartTime::latest(clone $table, ['status']);
        $this->assertSame($old['created_at'], $latest->run_started_at_utc);
        $this->assertSame('success', $latest->status);
        if ($explicitDefaults === 0) {
            $this->assertNotSame($oldStored['created_at'], $oldStored['started_at']);
        } else {
            $this->assertSame($oldStored['created_at'], $oldStored['started_at']);
        }

        $expand = require database_path('migrations/seo_intel/2026_09_22_160000_expand_gsc_run_utc_start.php');
        $expand->up();
        $expand->up();
        SchemaBaseline::clearCache();
        $after = (array) (clone $table)->where('sync_run_uid', $old['sync_run_uid'])->first();
        $this->assertNull($after['started_at_utc']);
        unset($after['started_at_utc']);
        $this->assertSame($historyHash, app(SeoRegistryHasher::class)->hash($after));

        $new = $this->runRow('00000000-0000-4000-8000-000000000002', '2026-09-21 11:00:00');
        $new['started_at_utc'] = '2026-09-21 11:00:00.123456';
        $table->insert($new);
        foreach ([['status' => 'success', 'finished_at' => '2026-09-21 11:01:00'],
            ['receipt_json' => json_encode(['status' => 'success', 'receipt_hash' => str_repeat('a', 64)])]] as $update) {
            (clone $table)->where('sync_run_uid', $new['sync_run_uid'])->update($update);
            $this->assertSame($new['started_at_utc'], (clone $table)->where('sync_run_uid', $new['sync_run_uid'])->value('started_at_utc'));
        }
        $connection->statement('SET SESSION time_zone = ?', [$timezone === '+00:00' ? '+08:00' : '+00:00']);
        $this->assertSame($new['started_at_utc'], (clone $table)->where('sync_run_uid', $new['sync_run_uid'])->value('started_at_utc'));
        $connection->statement('SET SESSION time_zone = ?', [$timezone]);

        // An old release still writes successfully after expansion and after
        // application rollback; its later failed attempt must beat old success.
        $expand->down();
        $oldWriter = $this->runRow('00000000-0000-4000-8000-000000000003', '2026-09-22 12:00:00');
        $table->insert($oldWriter);
        $this->assertSame('running', GscRunStartTime::latest(clone $table, ['status'])->status);
        (clone $table)->where('sync_run_uid', $oldWriter['sync_run_uid'])->update(['status' => 'failed', 'failure_code' => 'gsc_http_503']);
        $this->assertSame('failed', GscRunStartTime::latest(clone $table, ['status'])->status);
        $this->assertSame('sync_failed', (new SeoDashboardApiReadService('seo_intel'))->gscSyncState()['state']);
        $this->assertSame('failed', (new SeoSchedulerReceiptReadService)->read()['gsc']['status']);
        $this->assertSame(3, $table->count());
        (clone $table)->where('sync_run_uid', $new['sync_run_uid'])->update(['started_at_utc' => null, 'created_at' => $oldWriter['created_at']]);
        $this->expectExceptionMessage('GSC_RUN_START_AMBIGUOUS');
        GscRunStartTime::latest(clone $table, ['status']);
    }

    private function runRow(string $uid, string $at): array
    {
        return ['sync_run_uid' => $uid, 'window_days' => 90, 'start_date' => '2026-06-21', 'end_date' => '2026-09-18',
            'search_types_json' => '["web"]', 'trigger_mode' => 'scheduled', 'status' => 'running',
            'started_at' => $at, 'created_at' => $at, 'updated_at' => $at];
    }
}
