<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Platform12\Platform12SchedulerStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SeoPlatform12SchedulerClockMysqlTest extends TestCase
{
    private const TABLES = ['seo_council_schedule_deliveries', 'seo_council_scheduler_leases', 'seo_council_schedule_receipts'];

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
            'options' => [\PDO::MYSQL_ATTR_FOUND_ROWS => false],
        ], 'seo_council.connection' => 'seo_intel', 'seo_council.scheduler_enabled' => false]);
        DB::purge('seo_intel');
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::connection('seo_intel')->hasTable($table), 'Requires a database without existing scheduler tables.');
        }
        (require database_path('migrations/seo_intel/2026_09_04_010000_create_seo_council_scheduler_storage.php'))->up();
        $this->ownsTables = true;
        (require database_path('migrations/seo_intel/2026_09_04_020000_expand_seo_council_scheduler_fencing.php'))->up();
        CarbonImmutable::setTestNow('2026-09-22T14:00:00Z');
    }

    protected function tearDown(): void
    {
        if ($this->ownsTables) {
            foreach (self::TABLES as $table) {
                Schema::connection('seo_intel')->drop($table);
            }
        }
        DB::purge('seo_intel');
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function timezones(): array
    {
        return ['UTC' => ['+00:00'], 'Beijing' => ['+08:00']];
    }

    #[DataProvider('timezones')]
    public function test_events_are_utc_while_existing_lease_fencing_uses_database_clock(string $timezone): void
    {
        $connection = DB::connection('seo_intel');
        $connection->statement('SET SESSION time_zone = ?', [$timezone]);
        $connection->statement('SET timestamp = 1790085600');
        $store = app(Platform12SchedulerStore::class);
        $leaseKey = 'clock:mysql:lease';
        $ownerA = str_repeat('a', 32);
        $ownerB = str_repeat('b', 32);
        $lease = $store->acquire($leaseKey, $ownerA, 180);
        $this->assertSame('LEASE_ACQUIRED', $lease['status']);
        $this->assertSame('database_session', $lease['lease_clock']);
        $this->assertSame('LOCK_HELD', $store->acquire($leaseKey, $ownerB, 180)['status']);
        $this->assertSame('LEASE_RENEWED', $store->renew($leaseKey, $ownerA, 1, 180)['status']);
        $this->assertSame('LEASE_RENEWED', $store->renew($leaseKey, $ownerA, 1, 180)['status'], 'An unchanged renewal is valid under changed-row semantics.');
        $this->assertSame('STALE_FENCE', $store->renew($leaseKey, $ownerB, 1, 180)['status']);
        $ttl = (int) $connection->selectOne('SELECT TIMESTAMPDIFF(SECOND, CURRENT_TIMESTAMP, lease_expires_at) AS ttl FROM seo_council_scheduler_leases')->ttl;
        $this->assertGreaterThan(170, $ttl);
        $this->assertLessThanOrEqual(180, $ttl);
        $this->assertSame(1, $this->backlogCount());
        $connection->table('seo_council_scheduler_leases')->update(['lease_expires_at' => DB::raw('DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE)')]);
        $this->assertSame(0, $this->backlogCount(), 'Expired Beijing-clock leases cannot appear active for another eight hours.');
        $this->assertSame('LEASE_ACQUIRED', $store->acquire($leaseKey, $ownerA, 180)['status']);

        $vector = array_fill_keys(['catalog_hash', 'policy_hash', 'role_hash', 'tool_hash', 'schema_hash', 'evidence_hash'], str_repeat('1', 64));
        $delivery = ['delivery_id' => str_repeat('c', 64), 'slot_key' => 'clock:mysql:delivery',
            'scheduled_for' => '2026-09-22T14:00:00Z', 'catalog_version' => 'clock.v1',
            'catalog_hash' => str_repeat('1', 64), 'mission_id' => 'seo.platform12.daily.foundation',
            'mission_request_hash' => str_repeat('2', 64), 'mission_request' => ['mission_id' => 'seo.platform12.daily.foundation'],
            'idempotency_key' => 'clock:mysql:delivery'];
        $this->assertTrue($store->reserveDelivery($delivery, $vector)['accepted']);
        $this->assertSame('DELIVERY_CLAIMED', $store->claimDelivery($delivery['delivery_id'], $leaseKey, $ownerA, 2, $vector)['status']);
        $this->assertEventTime();
        $store->release($leaseKey, $ownerA, 2);
        $this->assertSame(3, $store->acquire($leaseKey, $ownerB, 180)['fencing_token']);
        $this->assertSame('STALE_FENCE', $store->claimDelivery($delivery['delivery_id'], $leaseKey, $ownerA, 2, $vector)['status']);
        $this->assertSame('DELIVERY_RECOVERED', $store->recoverStaleDelivery($delivery['delivery_id'], $leaseKey, $ownerB, 3, $vector)['status']);
        $this->assertEventTime();

        $failed = $store->completeDelivery($delivery['delivery_id'], $leaseKey, $ownerB, 3, 'clock:receipt', str_repeat('d', 64), 'CLOSED',
            function ($database): void {
                $database->table('seo_council_scheduler_leases')->update(['lease_expires_at' => DB::raw('DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE)')]);
            }, $vector);
        $this->assertFalse($failed['accepted']);
        $this->assertSame('RECOVERED', $connection->table('seo_council_schedule_deliveries')->value('status'));
        $this->assertNull($connection->table('seo_council_schedule_deliveries')->value('terminal_receipt_hash'));
        $closed = $store->completeDelivery($delivery['delivery_id'], $leaseKey, $ownerB, 3, 'clock:receipt', str_repeat('d', 64), 'CLOSED', null, $vector);
        $this->assertSame('TERMINAL_COMMITTED', $closed['status']);
        $this->assertEventTime();
        $this->assertSame('TERMINAL_REPLAY', $store->completeDelivery($delivery['delivery_id'], $leaseKey, $ownerB, 3, 'clock:receipt', str_repeat('d', 64))['status']);
        $this->assertSame(str_repeat('d', 64), $connection->table('seo_council_schedule_deliveries')->value('terminal_receipt_hash'));
        $this->assertSame(2, (int) $connection->table('seo_council_schedule_deliveries')->value('attempt'));
        $this->assertFalse((bool) config('seo_council.scheduler_enabled'));
    }

    private function assertEventTime(): void
    {
        $row = DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->first();
        $this->assertSame('2026-09-22 14:00:00', $row->created_at);
        $this->assertSame('2026-09-22 14:00:00', $row->updated_at);
    }

    private function backlogCount(): int
    {
        return DB::connection('seo_intel')->table('seo_council_scheduler_leases')
            ->whereRaw('lease_expires_at > CURRENT_TIMESTAMP')->count();
    }
}
