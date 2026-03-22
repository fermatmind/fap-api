<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\Attempt;
use App\Models\UnifiedAccessProjection;
use App\Services\Report\Pdf\ReportPdfDocumentService;
use App\Services\Storage\UnifiedAccessProjectionBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UnifiedAccessProjectionBackfillTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStoragePath;

    private string $originalStoragePath;

    private string $originalLocalRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->originalLocalRoot = (string) config('filesystems.disks.local.root');
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-access-projection-backfill-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        $this->app->useStoragePath($this->originalStoragePath);
        config()->set('filesystems.disks.local.root', $this->originalLocalRoot);
        Storage::forgetDisk('local');

        if (is_dir($this->isolatedStoragePath)) {
            File::deleteDirectory($this->isolatedStoragePath);
        }

        parent::tearDown();
    }

    public function test_backfill_replays_access_projection_from_historical_signals(): void
    {
        config()->set('storage_rollout.access_projection_dual_write_enabled', false);

        $attemptId = 'attempt-access-'.Str::lower(Str::random(8));
        $attempt = Attempt::query()->create([
            'id' => $attemptId,
            'anon_id' => 'anon-access',
            'scale_code' => 'MBTI',
            'scale_version' => 'v1',
            'question_count' => 1,
            'answers_summary_json' => [
                'meta' => [
                    'pack_release_manifest_hash' => 'nohash',
                ],
            ],
            'client_platform' => 'web',
            'client_version' => null,
            'channel' => 'organic',
            'referrer' => null,
            'started_at' => now()->subHour(),
            'submitted_at' => now()->subMinutes(5),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subMinutes(5),
        ]);

        DB::table('report_snapshots')->insert([
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'order_no' => 'order-'.$attemptId,
            'scale_code' => 'MBTI',
            'pack_id' => 'MBTI',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'spec-v1',
            'report_engine_version' => 'engine-v1',
            'snapshot_version' => 'v1',
            'report_json' => json_encode(['attempt_id' => $attemptId, 'report' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'report_free_json' => json_encode(['attempt_id' => $attemptId, 'variant' => 'free'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'report_full_json' => json_encode(['attempt_id' => $attemptId, 'variant' => 'full'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(4),
        ]);

        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'user_id' => null,
            'benefit_code' => 'MBTI_UNLOCK',
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'status' => 'active',
            'expires_at' => null,
            'benefit_type' => 'unlock',
            'benefit_ref' => 'ref-'.$attemptId,
            'source_order_id' => (string) Str::uuid(),
            'source_event_id' => (string) Str::uuid(),
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);

        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId,
            'order_no' => 'order-'.$attemptId,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'anon-access',
            'sku' => 'MBTI_UNLOCK',
            'item_sku' => 'MBTI_UNLOCK',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_total' => 9900,
            'amount_cents' => 9900,
            'currency' => 'USD',
            'status' => 'paid',
            'provider' => 'stub',
            'external_trade_no' => 'trade-'.$attemptId,
            'paid_at' => now()->subMinutes(3),
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);

        DB::table('payment_events')->insert([
            'id' => (string) Str::uuid(),
            'provider' => 'stub',
            'provider_event_id' => 'evt-'.$attemptId,
            'order_id' => $orderId,
            'event_type' => 'payment_succeeded',
            'order_no' => 'order-'.$attemptId,
            'payload_json' => json_encode(['order_no' => 'order-'.$attemptId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'received_at' => now()->subMinutes(3),
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);

        DB::table('shares')->insert([
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'anon_id' => 'anon-access',
            'scale_code' => 'MBTI',
            'scale_version' => 'v1',
            'content_package_version' => 'v1',
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        $pdfService = app(ReportPdfDocumentService::class);
        $pdfPath = $pdfService->resolveArtifactPath($attempt, 'free', null);
        File::ensureDirectoryExists(dirname(storage_path('app/private/'.$pdfPath)));
        File::put(storage_path('app/private/'.$pdfPath), '%PDF-1.4 access backfill');

        $service = app(UnifiedAccessProjectionBackfillService::class);
        $plan = $service->buildPlan(['attempt_id' => $attemptId]);
        $this->assertSame(1, $plan['attempt_count']);
        $this->assertSame(1, $plan['access_ready_count']);
        $this->assertSame(1, $plan['report_ready_count']);
        $this->assertSame(1, $plan['pdf_ready_count']);
        $this->assertSame(1, $plan['grant_count']);
        $this->assertSame(1, $plan['order_count']);
        $this->assertSame(1, $plan['payment_event_count']);
        $this->assertSame(1, $plan['share_count']);
        $this->assertSame(1, $plan['report_snapshot_count']);

        $execute = $service->executeBackfill(['attempt_id' => $attemptId]);
        $this->assertSame(1, $execute['attempt_receipts_inserted']);
        $this->assertSame(0, $execute['attempt_receipts_reused']);

        $projection = UnifiedAccessProjection::query()->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($projection);
        $this->assertSame('ready', $projection->access_state);
        $this->assertSame('ready', $projection->report_state);
        $this->assertSame('ready', $projection->pdf_state);
        $this->assertSame('benefit_grant_active', $projection->reason_code);
        $this->assertSame(1, (int) $projection->projection_version);
        $this->assertSame(['report' => true, 'pdf' => true, 'share' => true, 'payment' => true, 'unlock' => true], $projection->actions_json);
        $this->assertSame($attemptId, data_get($projection->payload_json, 'attempt_id'));
        $this->assertDatabaseHas('attempt_receipts', [
            'attempt_id' => $attemptId,
            'receipt_type' => 'access_projection_refreshed',
        ]);
    }
}
