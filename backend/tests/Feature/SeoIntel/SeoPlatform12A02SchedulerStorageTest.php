<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeoPlatform12A02SchedulerStorageTest extends TestCase
{
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
        DB::purge('seo_intel');
        DB::connection('seo_intel')->getPdo();
    }

    protected function tearDown(): void
    {
        DB::purge('seo_intel');

        parent::tearDown();
    }

    public function test_fresh_sqlite_migration_is_expand_only_and_backward_compatible(): void
    {
        $legacy = $this->legacyMigration();
        $legacy->up();

        $legacyColumns = $this->schema()->getColumnListing('seo_council_runs');
        DB::connection('seo_intel')->table('seo_council_runs')->insert($this->legacyRun());

        $migration = $this->schedulerMigration();
        $migration->up();
        $migration->up();

        $this->assertSame($legacyColumns, $this->schema()->getColumnListing('seo_council_runs'));
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_council_runs')->count());
        $this->assertFalse((bool) config('seo_council.scheduler_enabled', false));

        $expectedColumns = [
            'seo_council_schedule_deliveries' => [
                'delivery_id', 'slot_key', 'scheduled_for', 'catalog_version', 'catalog_hash',
                'mission_id', 'mission_request_hash', 'mission_request_json', 'idempotency_key',
                'attempt', 'status', 'terminal_receipt_reference',
            ],
            'seo_council_scheduler_leases' => [
                'lease_key', 'owner_token_hash', 'fencing_token', 'lease_expires_at',
            ],
            'seo_council_schedule_receipts' => [
                'schedule_receipt_id', 'slot_key', 'catalog_version', 'catalog_hash',
                'scheduled_for', 'started_at', 'ended_at', 'mission_planned_count',
                'mission_dispatched_count', 'mission_terminal_count', 'mission_succeeded_count',
                'mission_held_count', 'mission_failed_count', 'status', 'receipt_json', 'receipt_hash',
            ],
        ];

        foreach ($expectedColumns as $table => $columns) {
            $this->assertTrue($this->schema()->hasTable($table));
            $this->assertSame([], array_values(array_diff($columns, $this->schema()->getColumnListing($table))));
            $this->assertSame(0, DB::connection('seo_intel')->table($table)->count());
        }
    }

    public function test_duplicate_catalog_mission_slot_and_idempotency_are_rejected(): void
    {
        $this->schedulerMigration()->up();
        $first = $this->delivery();
        DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->insert($first);

        $duplicateSlot = $first;
        $duplicateSlot['delivery_id'] = str_repeat('d', 64);
        $duplicateSlot['idempotency_key'] = 'platform12:delivery:duplicate-slot';
        $this->assertUniqueConstraintViolation(fn () => DB::connection('seo_intel')
            ->table('seo_council_schedule_deliveries')
            ->insert($duplicateSlot));

        $duplicateIdempotency = $first;
        $duplicateIdempotency['delivery_id'] = str_repeat('e', 64);
        $duplicateIdempotency['slot_key'] = 'daily:2026-09-05';
        $this->assertUniqueConstraintViolation(fn () => DB::connection('seo_intel')
            ->table('seo_council_schedule_deliveries')
            ->insert($duplicateIdempotency));

        $this->assertSame(1, DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->count());
    }

    public function test_down_keeps_irreplaceable_scheduler_evidence_readable(): void
    {
        $migration = $this->schedulerMigration();
        $migration->up();

        DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->insert($this->delivery());
        DB::connection('seo_intel')->table('seo_council_scheduler_leases')->insert([
            'lease_key' => 'platform12:daily',
            'owner_token_hash' => str_repeat('f', 64),
            'fencing_token' => 7,
            'lease_expires_at' => '2026-09-04 01:05:00',
            'created_at' => '2026-09-04 01:00:00',
            'updated_at' => '2026-09-04 01:00:00',
        ]);
        DB::connection('seo_intel')->table('seo_council_schedule_receipts')->insert([
            'schedule_receipt_id' => str_repeat('b', 64),
            'slot_key' => 'daily:2026-09-04',
            'catalog_version' => 'seo.platform12_mission_catalog.v1',
            'catalog_hash' => str_repeat('c', 64),
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
            'receipt_hash' => str_repeat('a', 64),
            'created_at' => '2026-09-04 01:00:02',
        ]);

        $migration->down();

        foreach (['seo_council_schedule_deliveries', 'seo_council_scheduler_leases', 'seo_council_schedule_receipts'] as $table) {
            $this->assertTrue($this->schema()->hasTable($table));
            $this->assertSame(1, DB::connection('seo_intel')->table($table)->count());
        }
    }

    private function schema(): \Illuminate\Database\Schema\Builder
    {
        return DB::connection('seo_intel')->getSchemaBuilder();
    }

    private function legacyMigration(): object
    {
        return require database_path('migrations/seo_intel/2026_08_29_030000_create_seo_council_runtime_tables.php');
    }

    private function schedulerMigration(): object
    {
        return require database_path('migrations/seo_intel/2026_09_04_010000_create_seo_council_scheduler_storage.php');
    }

    /** @return array<string, mixed> */
    private function legacyRun(): array
    {
        return [
            'run_id' => str_repeat('1', 64),
            'idempotency_key' => 'platform12:legacy-run',
            'request_hash' => str_repeat('2', 64),
            'registry_hash' => str_repeat('3', 64),
            'binding_hash' => str_repeat('4', 64),
            'evidence_hash' => str_repeat('5', 64),
            'policy_version' => 'v1',
            'policy_hash' => str_repeat('6', 64),
            'status' => 'POLICY_HOLD',
            'receipt_version' => 1,
            'receipt_hash' => str_repeat('7', 64),
            'receipt_json' => json_encode(['status' => 'POLICY_HOLD'], JSON_THROW_ON_ERROR),
            'created_at' => '2026-09-04 01:00:00',
            'updated_at' => '2026-09-04 01:00:00',
        ];
    }

    /** @return array<string, mixed> */
    private function delivery(): array
    {
        return [
            'delivery_id' => str_repeat('a', 64),
            'slot_key' => 'daily:2026-09-04',
            'scheduled_for' => '2026-09-04 01:00:00',
            'catalog_version' => 'seo.platform12_mission_catalog.v1',
            'catalog_hash' => str_repeat('c', 64),
            'mission_id' => 'platform12:daily-foundation',
            'mission_request_hash' => str_repeat('b', 64),
            'mission_request_json' => json_encode(['mission_id' => 'platform12:daily-foundation'], JSON_THROW_ON_ERROR),
            'idempotency_key' => 'platform12:delivery:daily:2026-09-04',
            'attempt' => 1,
            'status' => 'PLANNED',
            'created_at' => '2026-09-04 01:00:00',
            'updated_at' => '2026-09-04 01:00:00',
        ];
    }

    private function assertUniqueConstraintViolation(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a durable scheduler uniqueness violation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
