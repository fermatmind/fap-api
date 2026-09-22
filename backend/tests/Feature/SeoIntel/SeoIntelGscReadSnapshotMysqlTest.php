<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\GscReadSnapshot;
use App\Services\SeoIntel\GscRunCloseoutSummarizer;
use App\Services\SeoIntel\OpsDashboard\SeoIssueClusterReadService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class SeoIntelGscReadSnapshotMysqlTest extends TestCase
{
    private bool $ownsTable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $socket = getenv('SEO_TEST_MYSQL_SOCKET');
        $database = getenv('SEO_TEST_MYSQL_DATABASE');
        if (! is_string($socket) || $socket === '' || ! is_string($database) || $database === '') {
            $this->markTestSkipped('Requires an isolated local MySQL database and socket.');
        }
        $this->assertMatchesRegularExpression('/^seo_operations_[a-z0-9_]+_test$/D', $database);
        foreach (['gsc_snapshot_test', 'gsc_snapshot_writer'] as $name) {
            config(['database.connections.'.$name => [
                'driver' => 'mysql', 'unix_socket' => $socket, 'database' => $database,
                'username' => 'root', 'password' => '', 'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
            ]]);
            DB::purge($name);
            DB::connection($name)->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        $schema = Schema::connection('gsc_snapshot_test');
        $this->assertFalse($schema->hasTable('seo_gsc_daily'), 'The test database must not contain an existing GSC table.');
        $schema->create('seo_gsc_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64);
            $table->text('canonical_url')->nullable();
            $table->char('query_hash', 64);
            $table->string('source_engine');
            $table->string('device')->nullable();
            $table->string('country')->nullable();
            $table->string('search_type');
            $table->unsignedInteger('clicks');
            $table->unsignedInteger('impressions');
            $table->unsignedInteger('average_position_milli')->nullable();
            $table->json('metadata_json')->nullable();
        });
        $this->ownsTable = true;
        $row = ['report_date' => '2026-09-18', 'canonical_url_hash' => str_repeat('a', 64),
            'query_hash' => str_repeat('b', 64), 'source_engine' => 'google', 'search_type' => 'web',
            'clicks' => 1, 'impressions' => 10, 'average_position_milli' => 2000,
            'metadata_json' => json_encode(['data_origin' => 'live_gsc_api'])];
        foreach (array_chunk(array_fill(0, 5001, $row), 500) as $batch) {
            DB::connection('gsc_snapshot_writer')->table('seo_gsc_daily')->insert($batch);
        }
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-22T14:00:00Z'));
    }

    protected function tearDown(): void
    {
        if ($this->ownsTable) {
            $connection = DB::connection('gsc_snapshot_test');
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            Schema::connection('gsc_snapshot_test')->drop('seo_gsc_daily');
        }
        DB::purge('gsc_snapshot_test');
        DB::purge('gsc_snapshot_writer');
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_metric_pages_and_distinct_count_use_one_snapshot_during_concurrent_update(): void
    {
        $changed = false;
        DB::listen(function (QueryExecuted $event) use (&$changed): void {
            if (! $changed && $event->connectionName === 'gsc_snapshot_test' && str_contains($event->sql, 'natural_keys')) {
                $changed = true;
                DB::connection('gsc_snapshot_writer')->table('seo_gsc_daily')->where('id', 5001)
                    ->update(['clicks' => 99, 'query_hash' => str_repeat('c', 64)]);
            }
        });
        $read = fn (): array => app(GscRunCloseoutSummarizer::class)->readModelSnapshot(
            DB::connection('gsc_snapshot_test'), CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-18'), ['web'],
        );
        $snapshot = $read();
        $this->assertTrue($changed);
        $this->assertSame(5001, $snapshot['row_count']);
        $this->assertSame(1, $snapshot['natural_unique_key_count']);
        $this->assertSame(5001, $snapshot['metrics']['clicks']);
        $later = $read();
        $this->assertSame(2, $later['natural_unique_key_count']);
        $this->assertSame(5099, $later['metrics']['clicks']);
        $this->assertSessionUnchanged();
    }

    public function test_all_history_quality_and_totals_do_not_mix_concurrent_pages(): void
    {
        $changed = false;
        DB::listen(function (QueryExecuted $event) use (&$changed): void {
            if (! $changed && $event->connectionName === 'gsc_snapshot_test' && str_contains($event->sql, 'limit 5000')) {
                $changed = true;
                DB::connection('gsc_snapshot_writer')->table('seo_gsc_daily')->where('id', 5001)
                    ->update(['clicks' => 99, 'metadata_json' => null]);
            }
        });
        $reader = new SeoIssueClusterReadService('gsc_snapshot_test');
        $method = new ReflectionMethod($reader, 'gscContext');
        $snapshot = $method->invoke($reader);
        $this->assertTrue($changed);
        $this->assertTrue($snapshot['quality_passed']);
        $this->assertSame(5001, $snapshot['metrics'][str_repeat('a', 64)]['clicks']);
        $this->assertSame(['quality_passed' => false, 'metrics' => []], $method->invoke($reader));
        $this->assertSessionUnchanged();
    }

    public function test_nested_reads_share_snapshot_and_failures_release_it_without_enabling_writes(): void
    {
        $connection = DB::connection('gsc_snapshot_test');
        $this->assertSame([5001], GscReadSnapshot::read($connection, fn (): array => GscReadSnapshot::read($connection, fn (): array => [$connection->table('seo_gsc_daily')->count()])));
        try {
            GscReadSnapshot::read($connection, function () use ($connection): array {
                $connection->table('seo_gsc_daily')->where('id', 1)->update(['clicks' => 2]);

                return [];
            });
            $this->fail('Snapshot transactions must reject writes.');
        } catch (QueryException $exception) {
            $this->assertSame(1792, $exception->errorInfo[1]);
        }
        $this->assertSessionUnchanged();
        $connection->beginTransaction();
        try {
            GscReadSnapshot::read($connection, fn (): array => []);
            $this->fail('An unknown outer transaction cannot guarantee a snapshot.');
        } catch (RuntimeException $exception) {
            $this->assertSame('GSC_READ_SNAPSHOT_REQUIRES_OWN_TRANSACTION', $exception->getMessage());
            $this->assertSame(1, $connection->transactionLevel());
        } finally {
            $connection->rollBack();
        }
        $connection->table('seo_gsc_daily')->where('id', 1)->update(['clicks' => 2]);
        $this->assertSame(2, (int) $connection->table('seo_gsc_daily')->where('id', 1)->value('clicks'));
    }

    private function assertSessionUnchanged(): void
    {
        $connection = DB::connection('gsc_snapshot_test');
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame('READ-COMMITTED', $connection->selectOne('SELECT @@session.transaction_isolation AS isolation_level')->isolation_level);
        $this->assertSame(0, (int) $connection->selectOne('SELECT @@session.transaction_read_only AS read_only')->read_only);
    }
}
