<?php

namespace Tests\Feature;

use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class HighIdorOwnership404Test extends TestCase
{
    use RefreshDatabase;

    private const ANON_A = 'pr60_anon_a';
    private const ANON_B = 'pr60_anon_b';

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr17SimpleScoreDemoSeeder())->run();
    }

    private function defaultAnswers(): array
    {
        return [
            ['question_id' => 'SS-001', 'code' => '5'],
            ['question_id' => 'SS-002', 'code' => '4'],
            ['question_id' => 'SS-003', 'code' => '3'],
            ['question_id' => 'SS-004', 'code' => '2'],
            ['question_id' => 'SS-005', 'code' => '1'],
        ];
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

    private function createSubmittedAttemptForAnonA(): string
    {
        $anonToken = $this->issueAnonToken(self::ANON_A);

        $start = $this->withHeaders([
            'X-Anon-Id' => self::ANON_A,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'anon_id' => self::ANON_A,
        ]);
        $start->assertStatus(200);

        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $submit = $this->withHeaders([
            'X-Anon-Id' => self::ANON_A,
            'Authorization' => 'Bearer ' . $anonToken,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $this->defaultAnswers(),
            'duration_ms' => 120000,
        ]);
        $submit->assertStatus(200);
        $this->flushHeaders();

        return $attemptId;
    }

    private function insertOrderForAnonA(string $orderNo): void
    {
        $now = now();
        $row = [
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => self::ANON_A,
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => null,
            'amount_cents' => 1990,
            'currency' => 'USD',
            'status' => 'created',
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('orders', 'amount_total')) {
            $row['amount_total'] = 1990;
        }
        if (Schema::hasColumn('orders', 'amount_refunded')) {
            $row['amount_refunded'] = 0;
        }
        if (Schema::hasColumn('orders', 'item_sku')) {
            $row['item_sku'] = 'MBTI_CREDIT';
        }
        if (Schema::hasColumn('orders', 'provider_order_id')) {
            $row['provider_order_id'] = null;
        }
        if (Schema::hasColumn('orders', 'device_id')) {
            $row['device_id'] = null;
        }
        if (Schema::hasColumn('orders', 'request_id')) {
            $row['request_id'] = null;
        }
        if (Schema::hasColumn('orders', 'created_ip')) {
            $row['created_ip'] = null;
        }
        if (Schema::hasColumn('orders', 'fulfilled_at')) {
            $row['fulfilled_at'] = null;
        }
        if (Schema::hasColumn('orders', 'refunded_at')) {
            $row['refunded_at'] = null;
        }
        if (Schema::hasColumn('orders', 'refund_amount_cents')) {
            $row['refund_amount_cents'] = null;
        }
        if (Schema::hasColumn('orders', 'refund_reason')) {
            $row['refund_reason'] = null;
        }

        DB::table('orders')->insert($row);
    }

    public function test_anon_b_cannot_access_anon_a_v03_attempt_endpoints(): void
    {
        $this->seedScales();
        $attemptId = $this->createSubmittedAttemptForAnonA();
        $anonBToken = $this->issueAnonToken(self::ANON_B);

        $this->withHeaders(['X-Anon-Id' => self::ANON_B])
            ->getJson("/api/v0.3/attempts/{$attemptId}/result")
            ->assertStatus(404);

        $this->withHeaders(['X-Anon-Id' => self::ANON_B])
            ->getJson("/api/v0.3/attempts/{$attemptId}/report")
            ->assertStatus(404);

        $this->withHeaders([
            'X-Anon-Id' => self::ANON_B,
            'Authorization' => 'Bearer ' . $anonBToken,
        ])
            ->postJson('/api/v0.3/attempts/submit', [
                'attempt_id' => $attemptId,
                'answers' => $this->defaultAnswers(),
                'duration_ms' => 120000,
            ])
            ->assertStatus(404);
    }

    public function test_header_only_anon_identity_cannot_read_v03_attempt_without_token_binding(): void
    {
        $this->seedScales();
        $attemptId = $this->createSubmittedAttemptForAnonA();

        $this->withHeaders(['X-Anon-Id' => self::ANON_A])
            ->getJson("/api/v0.3/attempts/{$attemptId}/result")
            ->assertStatus(404);

        $this->withHeaders(['X-Anon-Id' => self::ANON_A])
            ->getJson("/api/v0.3/attempts/{$attemptId}/report")
            ->assertStatus(404);
    }

    public function test_header_only_order_read_returns_degraded_payload_without_sensitive_fields(): void
    {
        $orderNo = 'ord_pr60_' . Str::lower(Str::random(10));
        $this->insertOrderForAnonA($orderNo);

        $response = $this->withHeaders(['X-Anon-Id' => self::ANON_B])
            ->getJson("/api/v0.3/orders/{$orderNo}");

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ownership_verified', false)
            ->assertJsonPath('order_no', $orderNo)
            ->assertJsonPath('status', 'pending');

        $response->assertJsonMissingPath('order');
        $response->assertJsonMissingPath('amount_cents');
        $response->assertJsonMissingPath('currency');
    }

}
