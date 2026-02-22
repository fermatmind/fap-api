<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Jobs\GenerateBigFiveReportPdfJob;
use App\Jobs\GenerateReportSnapshotJob;
use App\Models\Attempt;
use App\Models\Result;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\SignedBillingWebhook;
use Tests\TestCase;

final class BigFiveUnlockDeliveryPipelineTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    private function seedCommerce(): void
    {
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();
    }

    private function createUserWithEmail(string $email): string
    {
        $userId = random_int(100000, 999999);
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'BigFive Buyer',
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) $userId;
    }

    private function createBigFiveAttemptWithResult(string $anonId, ?string $userId): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'user_id' => $userId,
            'anon_id' => $anonId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 120,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => ['domains_mean' => ['O' => 3.0, 'C' => 3.0, 'E' => 3.0, 'A' => 3.0, 'N' => 3.0]],
            'scores_pct' => ['O' => 50, 'C' => 50, 'E' => 50, 'A' => 50, 'N' => 50],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'normed_json' => [
                    'norms' => ['status' => 'CALIBRATED', 'group_id' => 'zh-CN_prod_all_18-60'],
                    'quality' => ['level' => 'A'],
                ],
            ],
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    private function createOrder(
        string $orderNo,
        string $sku,
        string $attemptId,
        string $anonId,
        int $amountCents,
        ?string $userId
    ): void {
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => $userId,
            'anon_id' => $anonId,
            'sku' => $sku,
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => $amountCents,
            'currency' => 'CNY',
            'status' => 'created',
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'amount_total' => $amountCents,
            'amount_refunded' => 0,
            'item_sku' => $sku,
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
        ]);
    }

    public function test_big5_unlock_webhook_queues_pdf_job_and_email_outbox(): void
    {
        $this->seedCommerce();
        Queue::fake();

        $userId = $this->createUserWithEmail('buyer+big5@example.com');
        $anonId = 'anon_big5_delivery';
        $attemptId = $this->createBigFiveAttemptWithResult($anonId, $userId);
        $orderNo = 'ord_big5_delivery_1';
        $this->createOrder($orderNo, 'SKU_BIG5_FULL_REPORT_299', $attemptId, $anonId, 299, $userId);

        $payload = [
            'provider_event_id' => 'evt_big5_delivery_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_big5_delivery_1',
            'amount_cents' => 299,
            'currency' => 'CNY',
        ];

        $response = $this->postSignedBillingWebhook($payload, ['X-Org-Id' => '0']);
        $response->assertStatus(200);
        $response->assertJson(['ok' => true]);

        Queue::assertPushed(GenerateReportSnapshotJob::class, function (GenerateReportSnapshotJob $job) use ($attemptId): bool {
            return $job->attemptId === $attemptId
                && $job->orgId === 0
                && $job->triggerSource === 'payment';
        });
        Queue::assertPushed(GenerateBigFiveReportPdfJob::class, function (GenerateBigFiveReportPdfJob $job) use ($attemptId, $orderNo): bool {
            return $job->attemptId === $attemptId
                && $job->orgId === 0
                && $job->triggerSource === 'payment_unlock'
                && $job->orderNo === $orderNo;
        });

        $row = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', 'payment_success')
            ->orderByDesc('created_at')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('pending', (string) ($row->status ?? ''));
        $this->assertSame('buyer+big5@example.com', (string) ($row->email ?? ''));

        $payloadJson = json_decode((string) ($row->payload_json ?? '{}'), true);
        $this->assertIsArray($payloadJson);
        $this->assertSame("/api/v0.3/attempts/{$attemptId}/report", (string) ($payloadJson['report_url'] ?? ''));
        $this->assertSame("/api/v0.3/attempts/{$attemptId}/report.pdf", (string) ($payloadJson['report_pdf_url'] ?? ''));

        if (Schema::hasColumn('email_outbox', 'template_key')) {
            $this->assertContains((string) ($row->template_key ?? ''), ['', 'payment_success']);
        }
    }
}
