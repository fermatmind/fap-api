<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\Analytics\SeoConversionDailyBuilder;
use App\Services\SeoIntel\OpsDashboard\SeoSchedulerReceiptReadService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoSchedulerReceiptReadServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.seo_scheduler_receipt_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'seo_intel.connection' => 'seo_scheduler_receipt_test',
            'app.git_sha' => str_repeat('a', 40),
        ]);
        DB::purge('seo_scheduler_receipt_test');
        Schema::connection('seo_scheduler_receipt_test')->create('seo_gsc_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('trigger_mode', 32);
            $table->string('status', 32);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('receipt_json')->nullable();
        });
    }

    public function test_scheduler_holds_until_both_real_scheduled_receipts_exist(): void
    {
        $this->assertSame('measurement_hold', (new SeoSchedulerReceiptReadService)->read()['state']);

        $this->insertGscReceipt();

        $this->assertSame('measurement_hold', (new SeoSchedulerReceiptReadService)->read()['state']);
    }

    public function test_scheduler_observes_fresh_gsc_and_true_zero_funnel_receipts(): void
    {
        $this->insertGscReceipt();
        $day = CarbonImmutable::now('UTC')->startOfDay();
        app(SeoConversionDailyBuilder::class)->refresh($day, $day, [], false, 'scheduled');

        $result = (new SeoSchedulerReceiptReadService)->read();

        $this->assertTrue((bool) data_get($result, 'gsc.receipt_complete'), json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertTrue((bool) data_get($result, 'public_funnel.receipt_complete'), json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame('production_healthy_observing', $result['state'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame('success', data_get($result, 'gsc.status'));
        $this->assertSame('success', data_get($result, 'public_funnel.status'));
        $this->assertSame('scheduled', data_get($result, 'public_funnel.receipt.trigger_mode'));
        $this->assertSame(0, data_get($result, 'public_funnel.receipt.attempted_rows'));
        $this->assertTrue($result['read_only_gsc']);
        $this->assertFalse($result['search_submission_allowed']);
        $this->assertSame('SEO-PLATFORM-12', $result['slo_handoff']);
    }

    private function insertGscReceipt(): void
    {
        $now = now('UTC');
        DB::connection('seo_scheduler_receipt_test')->table('seo_gsc_sync_runs')->insert([
            'trigger_mode' => 'scheduled',
            'status' => 'success',
            'started_at' => $now,
            'finished_at' => $now,
            'receipt_json' => json_encode([
                'trigger_mode' => 'scheduled',
                'status' => 'success',
                'application_sha' => str_repeat('a', 40),
                'workflow_sha' => str_repeat('a', 40),
                'active_production_sha' => str_repeat('a', 40),
                'property_hash' => str_repeat('b', 64),
                'window_days' => 28,
                'search_types' => ['web'],
                'reporting_timezone' => 'America/Los_Angeles',
                'pages_fetched' => 1,
                'rows_seen' => 1,
                'rows_upserted' => 1,
                'duplicate_natural_keys' => 0,
                'mapped_rows' => 1,
                'unmapped_rows' => 0,
                'data_max_date' => $now->copy()->subDays(3)->toDateString(),
                'data_lag_days' => 3,
                'quality_gate' => ['status' => 'pass'],
                'restricted_egress' => ['status' => 'pass'],
                'read_only_gsc' => true,
                'search_submission_allowed' => false,
            ], JSON_THROW_ON_ERROR),
        ]);
    }
}
