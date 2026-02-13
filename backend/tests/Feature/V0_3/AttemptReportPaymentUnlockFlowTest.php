<?php

namespace Tests\Feature\V0_3;

use App\Jobs\GenerateReportSnapshotJob;
use App\Services\Report\ReportSnapshotStore;
use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignedBillingWebhook;
use Tests\TestCase;

final class AttemptReportPaymentUnlockFlowTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr17SimpleScoreDemoSeeder())->run();
        (new Pr19CommerceSeeder())->run();
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function simpleScoreAnswers(): array
    {
        return [
            ['question_id' => 'SS-001', 'code' => '5'],
            ['question_id' => 'SS-002', 'code' => '4'],
            ['question_id' => 'SS-003', 'code' => '3'],
            ['question_id' => 'SS-004', 'code' => '2'],
            ['question_id' => 'SS-005', 'code' => '1'],
        ];
    }

    public function test_attempt_submit_report_payment_unlock_full_report_flow(): void
    {
        $this->seedScales();

        $anonId = 'anon_pr2_flow';
        $anonToken = $this->issueAnonToken($anonId);
        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $anonToken,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $this->simpleScoreAnswers(),
            'duration_ms' => 120000,
        ]);
        $submit->assertStatus(200);
        $submit->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
        ]);

        $reportBefore = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");
        $reportBefore->assertStatus(200);
        $reportBefore->assertJson([
            'ok' => true,
            'locked' => true,
            'access_level' => 'free',
        ]);

        $beforePayload = $reportBefore->json('report');
        $this->assertIsArray($beforePayload);
        $this->assertArrayNotHasKey('breakdown', $beforePayload);
        $this->assertArrayNotHasKey('answers', $beforePayload);

        $order = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $anonToken,
        ])->postJson('/api/v0.3/orders', [
            'sku' => 'MBTI_REPORT_FULL_199',
            'provider' => 'billing',
            'target_attempt_id' => $attemptId,
        ]);
        $order->assertStatus(200);
        $order->assertJson(['ok' => true]);
        $orderNo = (string) $order->json('order_no');
        $this->assertNotSame('', $orderNo);

        $webhook = $this->postSignedBillingWebhook([
            'provider_event_id' => 'evt_pr2_flow_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_pr2_flow_1',
            'amount_cents' => 199,
            'currency' => 'CNY',
        ]);
        $webhook->assertStatus(200);
        $webhook->assertJson(['ok' => true]);

        $pending = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($pending);
        $this->assertSame('pending', (string) ($pending->status ?? ''));

        $job = new GenerateReportSnapshotJob(0, $attemptId, 'payment', $orderNo);
        $job->handle(app(ReportSnapshotStore::class));

        $reportAfter = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");
        $reportAfter->assertStatus(200);
        $reportAfter->assertJson([
            'ok' => true,
            'locked' => false,
            'access_level' => 'full',
        ]);

        $afterPayload = $reportAfter->json('report');
        $this->assertIsArray($afterPayload);
        $this->assertArrayNotHasKey('breakdown', $afterPayload);
        $this->assertArrayNotHasKey('answers', $afterPayload);

        $this->assertSame(
            1,
            DB::table('report_snapshots')->where('attempt_id', $attemptId)->count()
        );
        $this->assertSame(
            1,
            DB::table('benefit_grants')
                ->where('attempt_id', $attemptId)
                ->where('status', 'active')
                ->count()
        );
    }
}
