<?php

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignedBillingWebhook;
use Tests\TestCase;

class ReportSnapshotB2BTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr17SimpleScoreDemoSeeder())->run();
        (new Pr19CommerceSeeder())->run();

        $row = DB::table('scales_registry')
            ->where('org_id', 0)
            ->where('code', 'SIMPLE_SCORE_DEMO')
            ->first();

        if ($row) {
            $commercial = $row->commercial_json ?? null;
            if (is_string($commercial)) {
                $decoded = json_decode($commercial, true);
                $commercial = is_array($decoded) ? $decoded : null;
            }
            if (!is_array($commercial)) {
                $commercial = [];
            }

            $commercial['credit_benefit_code'] = 'MBTI_CREDIT';

            $payload = $commercial;
            if (is_array($payload)) {
                $payload = json_encode($payload, JSON_UNESCAPED_UNICODE);
            }

            DB::table('scales_registry')
                ->where('org_id', 0)
                ->where('code', 'SIMPLE_SCORE_DEMO')
                ->update([
                    'commercial_json' => $payload,
                    'updated_at' => now(),
                ]);
        }

        Cache::flush();
    }

    private function seedOrgWithToken(): array
    {
        $userId = 9003;
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'B2B User',
            'email' => 'b2b_user@example.com',
            'password' => 'secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orgId = 303;
        DB::table('organizations')->insert([
            'id' => $orgId,
            'name' => 'B2B Org',
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

    private function createSimpleScoreAttempt(int $orgId, string $token): string
    {
        $start = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
        ], [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        $answers = [
            ['question_id' => 'SS-001', 'code' => '5'],
            ['question_id' => 'SS-002', 'code' => '4'],
            ['question_id' => 'SS-003', 'code' => '3'],
            ['question_id' => 'SS-004', 'code' => '2'],
            ['question_id' => 'SS-005', 'code' => '1'],
        ];

        $submit = $this->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 120000,
        ], [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
        ]);
        $submit->assertStatus(200);

        return $attemptId;
    }

    public function test_snapshot_created_by_credit_consume_and_idempotent(): void
    {
        $this->seedScales();
        [$orgId, $userId, $token] = $this->seedOrgWithToken();

        $orderNo = 'ord_b2b_1';
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => $orgId,
            'user_id' => (string) $userId,
            'anon_id' => 'anon_' . $userId,
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => null,
            'amount_cents' => 4990,
            'currency' => 'USD',
            'status' => 'created',
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'amount_total' => 4990,
            'amount_refunded' => 0,
            'item_sku' => 'MBTI_CREDIT',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
        ]);

        $payload = [
            'provider_event_id' => 'evt_b2b_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_b2b_1',
            'amount_cents' => 4990,
            'currency' => 'USD',
        ];

        $webhook = $this->postSignedBillingWebhook($payload, [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
        ]);
        $webhook->assertStatus(200);
        $webhook->assertJson(['ok' => true]);

        $attemptId = $this->createSimpleScoreAttempt($orgId, $token);

        $this->assertSame(1, DB::table('report_snapshots')->count());
        $this->assertSame(1, DB::table('benefit_consumptions')->count());
        $this->assertSame(1, DB::table('benefit_wallet_ledgers')->where('reason', 'consume')->count());

        $dupSubmit = $this->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => [
                ['question_id' => 'SS-001', 'code' => '5'],
                ['question_id' => 'SS-002', 'code' => '4'],
                ['question_id' => 'SS-003', 'code' => '3'],
                ['question_id' => 'SS-004', 'code' => '2'],
                ['question_id' => 'SS-005', 'code' => '1'],
            ],
            'duration_ms' => 120000,
        ], [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
        ]);
        $dupSubmit->assertStatus(200);
        $dupSubmit->assertJson([
            'ok' => true,
            'idempotent' => true,
        ]);

        $this->assertSame(1, DB::table('report_snapshots')->count());
        $this->assertSame(1, DB::table('benefit_consumptions')->count());
        $this->assertSame(1, DB::table('benefit_wallet_ledgers')->where('reason', 'consume')->count());

        $report = $this->getJson("/api/v0.3/attempts/{$attemptId}/report", [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
        ]);
        $report->assertStatus(200);
        $report->assertJson([
            'ok' => true,
            'locked' => false,
            'access_level' => 'full',
        ]);
    }
}
