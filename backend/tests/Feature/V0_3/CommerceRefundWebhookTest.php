<?php

namespace Tests\Feature\V0_3;

use App\Jobs\GenerateReportSnapshotJob;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\ReportSnapshotStore;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignedBillingWebhook;
use Tests\TestCase;

class CommerceRefundWebhookTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();
    }

    private function seedOrgWithToken(): array
    {
        $userId = 9301;
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Refund User',
            'email' => 'refund_user@example.com',
            'password' => 'secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orgId = 9301;
        DB::table('organizations')->insert([
            'id' => $orgId,
            'name' => 'Refund Org',
            'owner_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organization_members')->insert([
            'org_id' => $orgId,
            'user_id' => $userId,
            'role' => 'owner',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'anon_id' => 'anon_' . $userId,
            'user_id' => $userId,
            'expires_at' => now()->addDays(1),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$orgId, $userId, $token];
    }

    private function createMbtiAttemptWithResult(int $orgId, int $userId): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => $orgId,
            'user_id' => $userId,
            'anon_id' => 'anon_refund',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.2.1-TEST',
            'content_package_version' => 'v0.2.1-TEST',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => [
                'EI' => 50,
                'SN' => 50,
                'TF' => 50,
                'JP' => 50,
                'AT' => 50,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'clear',
            ],
            'content_package_version' => 'v0.2.1-TEST',
            'result_json' => [
                'raw_score' => 0,
                'final_score' => 0,
                'breakdown_json' => [],
                'type_code' => 'INTJ-A',
                'axis_scores_json' => [
                    'scores_pct' => [
                        'EI' => 50,
                        'SN' => 50,
                        'TF' => 50,
                        'JP' => 50,
                        'AT' => 50,
                    ],
                    'axis_states' => [
                        'EI' => 'clear',
                        'SN' => 'clear',
                        'TF' => 'clear',
                        'JP' => 'clear',
                        'AT' => 'clear',
                    ],
                ],
            ],
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.2.1-TEST',
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    public function test_refund_revokes_entitlement_and_locks_report(): void
    {
        $this->seedScales();
        [$orgId, $userId, $token] = $this->seedOrgWithToken();

        $attemptId = $this->createMbtiAttemptWithResult($orgId, $userId);

        $orderNo = 'ord_refund_1';
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => $orgId,
            'user_id' => (string) $userId,
            'anon_id' => 'anon_' . $userId,
            'sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 990,
            'currency' => 'USD',
            'status' => 'created',
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'amount_total' => 990,
            'amount_refunded' => 0,
            'item_sku' => 'MBTI_REPORT_FULL',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
        ]);

        $payload = [
            'provider_event_id' => 'evt_refund_paid',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_refund_1',
            'amount_cents' => 990,
            'currency' => 'USD',
        ];

        $paid = $this->postSignedBillingWebhook($payload, [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
        ]);
        $paid->assertStatus(200);
        $paid->assertJson(['ok' => true]);

        $pending = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($pending);
        $this->assertSame('pending', (string) ($pending->status ?? ''));

        $job = new GenerateReportSnapshotJob($orgId, $attemptId, 'payment', $orderNo);
        $job->handle(app(ReportSnapshotStore::class));

        $report = $this->getJson("/api/v0.3/attempts/{$attemptId}/report", [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
        ]);
        $report->assertStatus(200);
        $report->assertJson([
            'ok' => true,
            'locked' => false,
        ]);

        $refundPayload = [
            'provider_event_id' => 'evt_refund_1',
            'order_no' => $orderNo,
            'event_type' => 'refund_succeeded',
            'refund_amount_cents' => 990,
            'refund_reason' => 'requested_by_customer',
        ];

        $refund = $this->postSignedBillingWebhook($refundPayload, [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
        ]);
        $refund->assertStatus(200);
        $refund->assertJson(['ok' => true]);

        $reportAfter = $this->getJson("/api/v0.3/attempts/{$attemptId}/report", [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
        ]);
        $reportAfter->assertStatus(200);
        $reportAfter->assertJson([
            'ok' => true,
            'locked' => true,
        ]);
    }
}
