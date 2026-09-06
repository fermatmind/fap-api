<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Runtime\ScheduledRuntimeProbeReceiptService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SeoPlatform07ScheduledRuntimeProbeReceiptTest extends TestCase
{
    private const CONNECTION = 'seo_platform_07_scheduler_test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.'.self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge(self::CONNECTION);
        Schema::connection(self::CONNECTION)->create('seo_runtime_probe_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('slot_key')->unique();
            $table->string('trigger_mode');
            $table->string('status');
            $table->timestamp('scheduled_for');
            $table->timestamp('completed_at');
            $table->string('receipt_hash');
            $table->json('crawler_source_receipt_json');
            $table->json('receipt_json');
            $table->timestamps();
        });
        Schema::connection(self::CONNECTION)->create('seo_crawler_log_daily_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('hit_count');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('updated_at');
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect(self::CONNECTION);
        parent::tearDown();
    }

    #[Test]
    public function scheduled_slot_is_idempotent_and_contains_a_sanitized_crawler_source_receipt(): void
    {
        DB::connection(self::CONNECTION)->table('seo_crawler_log_daily_aggregates')->insert([
            'hit_count' => 7,
            'last_seen_at' => '2026-08-26 09:00:00',
            'updated_at' => '2026-08-26 09:01:00',
        ]);
        $service = $this->service();
        $first = $service->record('scheduled', '2026-08-26T09:01:00Z', ['state' => 'success']);
        $second = $service->record('scheduled', '2026-08-26T09:08:00Z', ['state' => 'success']);

        $this->assertSame('success', $first['status']);
        $this->assertSame($first['receipt_hash'], $second['receipt_hash']);
        $this->assertSame(1, DB::connection(self::CONNECTION)->table('seo_runtime_probe_receipts')->count());
        $this->assertTrue(data_get($first, 'crawler_source_receipt.complete'));
        $this->assertSame('last_seen_at', data_get($first, 'crawler_source_receipt.observation_time_basis'));
        $this->assertSame('2026-08-26T09:00:00+00:00', data_get($first, 'crawler_source_receipt.latest_observation_at'));
        $this->assertFalse(data_get($first, 'boundaries.raw_url_emitted'));
        $this->assertFalse(data_get($first, 'boundaries.search_submission_allowed'));
    }

    #[Test]
    public function missing_or_stale_crawler_source_enters_measurement_hold_with_nulls(): void
    {
        Schema::connection(self::CONNECTION)->drop('seo_crawler_log_daily_aggregates');
        $receipt = $this->service()->record('scheduled', '2026-08-26T09:10:00Z');

        $this->assertSame('MEASUREMENT_HOLD', $receipt['status']);
        $this->assertNull(data_get($receipt, 'crawler_source_receipt.hit_count'));
        $this->assertNull(data_get($receipt, 'crawler_source_receipt.latest_observation_at'));
    }

    #[Test]
    public function future_legacy_rows_cannot_mask_a_fresh_utc_observation(): void
    {
        DB::connection(self::CONNECTION)->table('seo_crawler_log_daily_aggregates')->insert([
            [
                'hit_count' => 3,
                'last_seen_at' => '2026-08-26 17:00:00',
                'updated_at' => '2026-08-26 08:00:00',
            ],
            [
                'hit_count' => 2,
                'last_seen_at' => '2026-08-26 08:59:00',
                'updated_at' => '2026-08-26 09:00:00',
            ],
        ]);

        $receipt = $this->service()->record('scheduled', '2026-08-26T09:00:00Z', ['state' => 'success']);

        $this->assertSame('success', $receipt['status']);
        $this->assertTrue(data_get($receipt, 'crawler_source_receipt.complete'));
        $this->assertSame(5, data_get($receipt, 'crawler_source_receipt.hit_count'));
        $this->assertSame('2026-08-26T08:59:00+00:00', data_get($receipt, 'crawler_source_receipt.latest_observation_at'));
        $this->assertSame(1, data_get($receipt, 'crawler_source_receipt.age_minutes'));
    }

    #[Test]
    public function only_three_fresh_consecutive_natural_slots_complete_the_window(): void
    {
        DB::connection(self::CONNECTION)->table('seo_crawler_log_daily_aggregates')->insert([
            'hit_count' => 7,
            'last_seen_at' => '2026-08-26 09:00:00',
            'updated_at' => '2026-08-26 09:00:00',
        ]);
        $service = $this->service();
        $service->record('manual', '2026-08-26T08:55:00Z', ['state' => 'success']);
        $service->record('scheduled', '2026-08-26T09:00:00Z', ['state' => 'success']);
        $service->record('scheduled', '2026-08-26T09:10:00Z', ['state' => 'success']);
        $this->assertSame('MEASUREMENT_HOLD', $service->readWindow('2026-08-26T09:11:00Z')['state']);

        $service->record('scheduled', '2026-08-26T09:20:00Z', ['state' => 'success']);
        $window = $service->readWindow('2026-08-26T09:21:00Z');

        $this->assertSame('complete', $window['state']);
        $this->assertSame(3, $window['slot_count']);
        $this->assertTrue($window['consecutive']);
        $this->assertTrue(data_get($window, 'boundaries.manual_receipts_excluded'));
    }

    #[Test]
    public function scheduler_contract_uses_existing_control_plane_guards(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
        $kernel = file_get_contents(app_path('Console/Kernel.php'));

        $this->assertStringContainsString('seo:runtime-probe-scheduled --trigger=scheduled --json', $bootstrap);
        $this->assertMatchesRegularExpression('/seo:runtime-probe-scheduled[\\s\\S]+everyTenMinutes\(\)[\\s\\S]+withoutOverlapping\(\)[\\s\\S]+onOneServer\(\)/', $bootstrap);
        $this->assertStringNotContainsString('seo:runtime-probe-scheduled', $kernel);
        $this->assertStringNotContainsString('schedule:run', $kernel);

        $process = new Process([PHP_BINARY, 'artisan', 'schedule:list', '--json', '--no-ansi'], base_path());
        $process->mustRun();
        $events = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $event = collect($events)->first(
            fn (array $candidate): bool => str_contains(
                (string) ($candidate['command'] ?? ''),
                'seo:runtime-probe-scheduled --trigger=scheduled --json',
            ),
        );

        $this->assertIsArray($event);
        $this->assertSame('*/10 * * * *', $event['expression'] ?? null);
    }

    #[Test]
    public function expand_only_migration_creates_the_receipt_store_and_never_drops_it(): void
    {
        $source = (string) file_get_contents(database_path('migrations/seo_intel/2026_08_26_090000_create_runtime_probe_receipts.php'));
        $this->assertStringContainsString("->dateTime('scheduled_for')", $source);
        $this->assertStringContainsString("->dateTime('completed_at')", $source);
        $this->assertStringNotContainsString("->timestamp('completed_at')", $source);
        config(['database.connections.seo_intel' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('seo_intel');
        $migration = require database_path('migrations/seo_intel/2026_08_26_090000_create_runtime_probe_receipts.php');

        $migration->up();
        $this->assertTrue(Schema::connection('seo_intel')->hasTable('seo_runtime_probe_receipts'));
        $this->assertTrue(Schema::connection('seo_intel')->hasColumn('seo_runtime_probe_receipts', 'crawler_source_receipt_json'));
        $this->assertSame('datetime', Schema::connection('seo_intel')->getColumnType('seo_runtime_probe_receipts', 'scheduled_for'));
        $this->assertSame('datetime', Schema::connection('seo_intel')->getColumnType('seo_runtime_probe_receipts', 'completed_at'));

        $migration->down();
        $this->assertTrue(Schema::connection('seo_intel')->hasTable('seo_runtime_probe_receipts'));
        DB::disconnect('seo_intel');
    }

    private function service(): ScheduledRuntimeProbeReceiptService
    {
        return new ScheduledRuntimeProbeReceiptService(self::CONNECTION);
    }
}
