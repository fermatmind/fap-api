<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Services\Commerce\WechatMiniVirtualPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LocalReportSessionPaymentContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_session_is_generic_idempotent_and_contains_no_assessment_payload(): void
    {
        $anonId = 'anon_local_report_payment';
        $token = $this->issueAnonToken($anonId);
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ];
        $clientRef = (string) Str::uuid();

        $first = $this->withHeaders($headers)
            ->postJson('/api/v0.3/local-report-sessions', ['client_ref' => $clientRef])
            ->assertOk()
            ->assertJsonPath('product.sku', 'WEAPP_LOCAL_REPORT_FULL_199')
            ->assertJsonPath('product.price_cents', 199)
            ->json();
        $second = $this->withHeaders($headers)
            ->postJson('/api/v0.3/local-report-sessions', ['client_ref' => $clientRef])
            ->assertOk()
            ->json();

        $this->assertSame($first['attempt_id'], $second['attempt_id']);
        $attempt = DB::table('attempts')->where('id', $first['attempt_id'])->first();
        $this->assertNotNull($attempt);
        $this->assertSame('LOCAL_REPORT', (string) $attempt->scale_code);
        $this->assertSame(0, (int) $attempt->question_count);
        $this->assertSame('[]', (string) $attempt->answers_summary_json);
        $this->assertStringNotContainsString($clientRef, (string) $attempt->answers_digest);
        $this->assertFalse((bool) ($first['granted'] ?? true));

        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'user_id' => null,
            'attempt_id' => $first['attempt_id'],
            'benefit_code' => 'WEAPP_LOCAL_REPORT_FULL',
            'scope' => 'attempt',
            'status' => 'active',
            'benefit_type' => 'report_unlock',
            'benefit_ref' => $first['attempt_id'],
            'source_order_id' => (string) Str::uuid(),
            'source_event_id' => null,
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->withHeaders($headers)
            ->postJson('/api/v0.3/local-report-sessions', ['client_ref' => $clientRef])
            ->assertOk()
            ->assertJsonPath('attempt_id', $first['attempt_id'])
            ->assertJsonPath('granted', true);

        $this->withHeaders($headers)->postJson('/api/v0.3/local-report-sessions', [
            'client_ref' => (string) Str::uuid(),
            'scale_code' => 'DARK_TRIAD',
            'answers' => ['q1' => 5],
            'result' => ['title' => 'sensitive'],
        ])->assertUnprocessable();
    }

    public function test_local_session_uses_shared_199_virtual_product_contract(): void
    {
        config()->set('payments.providers.wechat_mini_virtual.enabled', true);
        config()->set('report_unlock.providers.wechat_mini_virtual.available', true);
        config()->set('payments.wechat_mini_virtual.app_id', 'wx-test-app');
        config()->set('payments.wechat_mini_virtual.app_secret', 'wx-test-secret');
        config()->set('payments.wechat_mini_virtual.offer_id', 'offer-test');
        config()->set('payments.wechat_mini_virtual.app_key', 'app-key');
        config()->set('payments.wechat_mini_virtual.product_id', 'FullReport199');

        $attemptId = (string) Str::uuid();
        DB::table('attempts')->insert([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => 'anon_local_contract',
            'scale_code' => 'LOCAL_REPORT',
            'scale_version' => 'commerce.v1',
            'question_count' => 0,
            'answers_summary_json' => '[]',
            'client_platform' => 'wechat-miniprogram',
            'answers_digest' => hash('sha256', 'opaque'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eligibility = app(WechatMiniVirtualPaymentService::class)->validateOrderEligibility(
            0,
            null,
            'anon_local_contract',
            $attemptId,
            'WEAPP_LOCAL_REPORT_FULL_199',
            1,
        );

        $this->assertTrue((bool) ($eligibility['ok'] ?? false));
        $this->assertSame(199, (int) DB::table('skus')->where('sku', 'WEAPP_LOCAL_REPORT_FULL_199')->value('price_cents'));
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();
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
}
