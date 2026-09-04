<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Platform12\Platform12SchedulerStore;
use App\Services\SeoCouncil\Platform12\Platform12SchedulerVersionVector;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class SeoPlatform12A03SchedulerFencingTest extends TestCase
{
    private const LEASE_KEY = 'platform12:daily:primary';

    private const OWNER_A = 'owner-a-token-0000000000000001';

    private const OWNER_B = 'owner-b-token-0000000000000002';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.seo_intel', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('seo_council.connection', 'seo_intel');
        config()->set('seo_council.scheduler_enabled', false);
        DB::purge('seo_intel');
        DB::connection('seo_intel')->getPdo();

        $storage = require database_path('migrations/seo_intel/2026_09_04_010000_create_seo_council_scheduler_storage.php');
        $storage->up();
        $fencing = require database_path('migrations/seo_intel/2026_09_04_020000_expand_seo_council_scheduler_fencing.php');
        $fencing->up();
    }

    protected function tearDown(): void
    {
        DB::purge('seo_intel');
        DB::purge('missing_lock_store');

        parent::tearDown();
    }

    public function test_two_nodes_share_one_atomic_lease_and_takeovers_increment_the_fence(): void
    {
        $nodeA = app()->make(Platform12SchedulerStore::class);
        $nodeB = app()->make(Platform12SchedulerStore::class);

        $first = $nodeA->acquire(self::LEASE_KEY, self::OWNER_A, 60);
        $blocked = $nodeB->acquire(self::LEASE_KEY, self::OWNER_B, 60);
        $renewed = $nodeA->renew(self::LEASE_KEY, self::OWNER_A, 1, 60);
        $released = $nodeA->release(self::LEASE_KEY, self::OWNER_A, 1);
        $takeover = $nodeB->acquire(self::LEASE_KEY, self::OWNER_B, 60);

        $this->assertSame('LEASE_ACQUIRED', $first['status']);
        $this->assertSame(1, $first['fencing_token']);
        $this->assertSame('LOCK_HELD', $blocked['status']);
        $this->assertFalse($blocked['acquired']);
        $this->assertSame('LEASE_RENEWED', $renewed['status']);
        $this->assertSame('LEASE_RELEASED', $released['status']);
        $this->assertSame('LEASE_ACQUIRED', $takeover['status']);
        $this->assertSame(2, $takeover['fencing_token']);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_council_scheduler_leases')->count());
    }

    public function test_fencing_expansion_is_backward_compatible_and_scheduler_stays_disabled(): void
    {
        $schema = DB::connection('seo_intel')->getSchemaBuilder();

        foreach (['lease_key', 'fencing_token', 'version_vector_hash', 'version_vector_json', 'terminal_receipt_hash'] as $column) {
            $this->assertTrue($schema->hasColumn('seo_council_schedule_deliveries', $column), $column);
        }
        $this->assertFalse((bool) config('seo_council.scheduler_enabled'));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->count());
    }

    public function test_owner_crash_takeover_rejects_late_completion_and_replays_one_terminal_receipt(): void
    {
        $store = app()->make(Platform12SchedulerStore::class);
        $vector = $this->vector('a');
        $delivery = $this->delivery('a', 'daily:2026-09-04');

        $reserved = $store->reserveDelivery($delivery, $vector);
        $firstLease = $store->acquire(self::LEASE_KEY, self::OWNER_A, 60);
        $claimed = $store->claimDelivery(
            $delivery['delivery_id'],
            self::LEASE_KEY,
            self::OWNER_A,
            (int) $firstLease['fencing_token'],
            $vector,
        );
        $alternateLease = $store->acquire('platform12:daily:alternate', self::OWNER_B, 60);
        $wrongLease = $store->completeDelivery(
            $delivery['delivery_id'],
            'platform12:daily:alternate',
            self::OWNER_B,
            (int) $alternateLease['fencing_token'],
            'receipt:wrong-lease',
            str_repeat('7', 64),
        );
        DB::connection('seo_intel')->table('seo_council_scheduler_leases')
            ->where('lease_key', self::LEASE_KEY)
            ->update(['lease_expires_at' => '2000-01-01 00:00:00']);

        $takeover = $store->acquire(self::LEASE_KEY, self::OWNER_B, 60);
        $mismatched = $this->vector('a');
        $mismatched['evidence_hash'] = str_repeat('f', 64);
        $held = $store->recoverStaleDelivery(
            $delivery['delivery_id'],
            self::LEASE_KEY,
            self::OWNER_B,
            (int) $takeover['fencing_token'],
            $mismatched,
        );
        $recovered = $store->recoverStaleDelivery(
            $delivery['delivery_id'],
            self::LEASE_KEY,
            self::OWNER_B,
            (int) $takeover['fencing_token'],
            $vector,
        );

        $receiptHash = str_repeat('9', 64);
        $late = $store->completeDelivery(
            $delivery['delivery_id'],
            self::LEASE_KEY,
            self::OWNER_A,
            (int) $firstLease['fencing_token'],
            'receipt:late-owner',
            str_repeat('8', 64),
        );
        $completed = $store->completeDelivery(
            $delivery['delivery_id'],
            self::LEASE_KEY,
            self::OWNER_B,
            (int) $takeover['fencing_token'],
            'receipt:platform12:daily:2026-09-04',
            $receiptHash,
        );
        $replay = $store->completeDelivery(
            $delivery['delivery_id'],
            self::LEASE_KEY,
            self::OWNER_B,
            (int) $takeover['fencing_token'],
            'receipt:platform12:daily:2026-09-04',
            $receiptHash,
        );

        $this->assertSame('DELIVERY_RESERVED', $reserved['status']);
        $this->assertSame('DELIVERY_CLAIMED', $claimed['status']);
        $this->assertSame('STALE_FENCE', $wrongLease['status']);
        $this->assertFalse($wrongLease['terminal_committed']);
        $this->assertSame(2, $takeover['fencing_token']);
        $this->assertSame('VERSION_VECTOR_HOLD', $held['status']);
        $this->assertSame('DELIVERY_RECOVERED', $recovered['status']);
        $this->assertSame('STALE_FENCE', $late['status']);
        $this->assertSame('TERMINAL_COMMITTED', $completed['status']);
        $this->assertTrue($completed['terminal_committed']);
        $this->assertSame('TERMINAL_REPLAY', $replay['status']);
        $this->assertFalse($replay['terminal_committed']);

        $row = DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->first();
        $this->assertSame('CLOSED', $row->status);
        $this->assertSame(2, (int) $row->attempt);
        $this->assertSame(2, (int) $row->fencing_token);
        $this->assertSame($receiptHash, $row->terminal_receipt_hash);
    }

    public function test_duplicate_delivery_replays_only_identical_request_and_never_duplicates_storage(): void
    {
        $store = app()->make(Platform12SchedulerStore::class);
        $delivery = $this->delivery('b', 'daily:2026-09-05');
        $vector = $this->vector('b');

        $first = $store->reserveDelivery($delivery, $vector);
        $replay = $store->reserveDelivery($delivery, $vector);
        $conflicting = $delivery;
        $conflicting['mission_request_hash'] = str_repeat('c', 64);
        $conflict = $store->reserveDelivery($conflicting, $vector);

        $this->assertSame('DELIVERY_RESERVED', $first['status']);
        $this->assertSame('DELIVERY_REPLAY', $replay['status']);
        $this->assertTrue($replay['accepted']);
        $this->assertSame('DUPLICATE_DELIVERY_HOLD', $conflict['status']);
        $this->assertFalse($conflict['accepted']);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->count());
    }

    public function test_future_lease_timestamp_fails_closed_instead_of_blocking_forever(): void
    {
        $store = app()->make(Platform12SchedulerStore::class);
        $lease = $store->acquire(self::LEASE_KEY, self::OWNER_A, 60);
        DB::connection('seo_intel')->table('seo_council_scheduler_leases')
            ->where('lease_key', self::LEASE_KEY)
            ->update(['lease_expires_at' => '2099-01-01 00:00:00']);

        $takeover = $store->acquire(self::LEASE_KEY, self::OWNER_B, 60);
        $renewal = $store->renew(self::LEASE_KEY, self::OWNER_A, (int) $lease['fencing_token'], 60);

        $this->assertSame('CLOCK_DRIFT_HOLD', $takeover['status']);
        $this->assertFalse($takeover['acquired']);
        $this->assertSame('CLOCK_DRIFT_HOLD', $renewal['status']);
    }

    public function test_shared_lock_store_unavailability_is_sanitized_and_fail_closed(): void
    {
        config()->set('database.connections.missing_lock_store', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('seo_council.connection', 'missing_lock_store');
        DB::purge('missing_lock_store');

        $decision = app()->make(Platform12SchedulerStore::class)
            ->acquire(self::LEASE_KEY, self::OWNER_A, 60);

        $this->assertSame('LOCK_STORE_UNAVAILABLE', $decision['status']);
        $this->assertFalse($decision['acquired']);
        $this->assertArrayNotHasKey('exception', $decision);
        $this->assertArrayNotHasKey('message', $decision);
    }

    public function test_low_priority_backpressure_covers_open_slot_queue_limit_and_dependency_hold(): void
    {
        $store = app()->make(Platform12SchedulerStore::class);
        $first = $this->delivery('c', 'daily:2026-09-06');
        $store->reserveDelivery($first, $this->vector('c'));

        $openSlot = $store->applyBackpressure(
            $first['delivery_id'],
            'low',
            'daily:2026-09-05',
            64,
            true,
        );
        $this->assertSame('BACKPRESSURE_HOLD', $openSlot['status']);
        $this->assertSame('PREVIOUS_SLOT_OPEN', $openSlot['reason']);

        $this->closeSlot('daily:2026-09-05');
        DB::connection('seo_intel')->table('seo_council_schedule_deliveries')
            ->where('delivery_id', $first['delivery_id'])
            ->update(['status' => 'PLANNED']);
        $second = $this->delivery('d', 'daily:2026-09-07');
        $second['mission_id'] = 'seo.platform12.daily.second';
        $second['idempotency_key'] = 'platform12:delivery:d';
        $store->reserveDelivery($second, $this->vector('d'));

        $queueHold = $store->applyBackpressure($first['delivery_id'], 'normal', null, 1, true);
        $dependencyHold = $store->applyBackpressure($second['delivery_id'], 'low', null, 64, false);
        $highPriority = $store->applyBackpressure($second['delivery_id'], 'high', null, 64, false);

        $this->assertSame('BACKPRESSURE_HOLD', $queueHold['status']);
        $this->assertSame('QUEUE_LIMIT_EXCEEDED', $queueHold['reason']);
        $this->assertSame('BACKPRESSURE_HOLD', $dependencyHold['status']);
        $this->assertSame('DEPENDENCY_UNAVAILABLE', $dependencyHold['reason']);
        $this->assertSame('DEPENDENCY_HOLD', $highPriority['status']);
        $this->assertFalse((bool) config('seo_council.scheduler_enabled'));
    }

    public function test_version_vector_is_order_independent_but_rejects_missing_or_extra_authority(): void
    {
        $service = app()->make(Platform12SchedulerVersionVector::class);
        $vector = $this->vector('e');
        $reversed = array_reverse($vector, true);

        $this->assertSame($service->hash($vector), $service->hash($reversed));

        unset($vector['schema_hash']);
        $vector['binding_hash'] = str_repeat('e', 64);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SCHEDULER_VERSION_VECTOR_INVALID');
        $service->hash($vector);
    }

    /** @return array<string, mixed> */
    private function delivery(string $seed, string $slotKey): array
    {
        return [
            'delivery_id' => str_repeat($seed, 64),
            'slot_key' => $slotKey,
            'scheduled_for' => '2026-09-04 01:00:00',
            'catalog_version' => 'seo.platform12_mission_catalog.v1',
            'catalog_hash' => str_repeat('1', 64),
            'mission_id' => 'seo.platform12.daily.foundation',
            'mission_request_hash' => str_repeat('2', 64),
            'mission_request' => ['mission_id' => 'seo.platform12.daily.foundation'],
            'idempotency_key' => 'platform12:delivery:'.$seed,
        ];
    }

    /** @return array<string, string> */
    private function vector(string $seed): array
    {
        return [
            'catalog_hash' => str_repeat($seed, 64),
            'policy_hash' => str_repeat('2', 64),
            'role_hash' => str_repeat('3', 64),
            'tool_hash' => str_repeat('4', 64),
            'schema_hash' => str_repeat('5', 64),
            'evidence_hash' => str_repeat('6', 64),
        ];
    }

    private function closeSlot(string $slotKey): void
    {
        DB::connection('seo_intel')->table('seo_council_schedule_receipts')->insert([
            'schedule_receipt_id' => str_repeat('7', 64),
            'slot_key' => $slotKey,
            'catalog_version' => 'seo.platform12_mission_catalog.v1',
            'catalog_hash' => str_repeat('1', 64),
            'scheduled_for' => '2026-09-04 01:00:00',
            'started_at' => '2026-09-04 01:00:01',
            'ended_at' => '2026-09-04 01:00:02',
            'mission_planned_count' => 1,
            'mission_dispatched_count' => 1,
            'mission_terminal_count' => 1,
            'mission_succeeded_count' => 1,
            'mission_held_count' => 0,
            'mission_failed_count' => 0,
            'status' => 'CLOSED',
            'receipt_json' => json_encode(['status' => 'CLOSED'], JSON_THROW_ON_ERROR),
            'receipt_hash' => str_repeat('8', 64),
            'created_at' => '2026-09-04 01:00:02',
        ]);
    }
}
