<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\Commerce\PaymentRecoveryToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommerceOrderReadFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const ANON_OWNER = 'order_read_owner';

    private const ANON_ATTACKER = 'order_read_attacker';

    public function test_without_identity_returns_404(): void
    {
        $orderNo = 'ord_fallback_'.Str::lower(Str::random(10));
        $this->insertOrderForOwner($orderNo);

        $response = $this->getJson('/api/v0.3/orders/'.$orderNo);

        $response->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_mismatched_token_identity_still_returns_404(): void
    {
        $orderNo = 'ord_fallback_'.Str::lower(Str::random(10));
        $this->insertOrderForOwner($orderNo);

        $token = $this->issueAnonToken(self::ANON_ATTACKER);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/orders/'.$orderNo);

        $response->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_matching_token_identity_returns_full_payload(): void
    {
        config(['app.frontend_url' => 'https://web.example.test']);

        $orderNo = 'ord_fallback_'.Str::lower(Str::random(10));
        $attemptId = (string) Str::uuid();
        $this->insertAttempt($attemptId, self::ANON_OWNER, 'BIG5_OCEAN', 'en');
        $this->insertOrderForOwner($orderNo, $attemptId, 'paid');

        $token = $this->issueAnonToken(self::ANON_OWNER);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/orders/'.$orderNo);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ownership_verified', true)
            ->assertJsonPath('order_no', $orderNo)
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('attempt_id', $attemptId)
            ->assertJsonPath('amount_cents', 1990)
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('order.anon_id', self::ANON_OWNER)
            ->assertJsonPath('result_url', "https://web.example.test/en/result/{$attemptId}")
            ->assertJsonPath('delivery.can_view_report', true)
            ->assertJsonPath('delivery.report_url', "/api/v0.3/attempts/{$attemptId}/report")
            ->assertJsonPath('delivery.can_download_pdf', true)
            ->assertJsonPath('delivery.report_pdf_url', "/api/v0.3/attempts/{$attemptId}/report.pdf")
            ->assertJsonPath('delivery.can_resend', false);

        $this->assertNotSame('', (string) $response->json('payment_recovery_token'));
    }

    public function test_matching_token_identity_returns_mbti_access_hub_for_mbti_attempt(): void
    {
        $orderNo = 'ord_fallback_mbti_'.Str::lower(Str::random(10));
        $attemptId = (string) Str::uuid();
        $this->insertAttempt($attemptId, self::ANON_OWNER, 'MBTI');
        $this->insertOrderForOwner($orderNo, $attemptId, 'paid');
        $this->insertResult($attemptId);
        DB::table('attempts')->where('id', $attemptId)->update([
            'question_count' => 93,
            'answers_summary_json' => json_encode(['stage' => 'seed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'dir_version' => 'MBTI-CN-v0.3-form-93',
            'content_package_version' => 'v0.3-form-93',
            'scoring_spec_version' => '2026.01.mbti_93',
        ]);
        $this->insertProjection($attemptId, [
            'access_state' => 'ready',
            'report_state' => 'ready',
            'pdf_state' => 'ready',
            'reason_code' => 'entitlement_granted',
            'payload_json' => [
                'access_level' => 'full',
                'variant' => 'full',
                'modules_allowed' => ['core_full', 'career', 'relationships'],
                'modules_preview' => [],
            ],
        ]);

        $token = $this->issueAnonToken(self::ANON_OWNER);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/orders/'.$orderNo);

        $response->assertStatus(200)
            ->assertJsonPath('mbti_form_v1.form_code', 'mbti_93')
            ->assertJsonPath('mbti_form_v1.question_count', 93)
            ->assertJsonPath('mbti_access_hub_v1.access_state', 'ready')
            ->assertJsonPath('mbti_access_hub_v1.unlock_stage', 'full')
            ->assertJsonPath('mbti_access_hub_v1.unlock_source', 'none')
            ->assertJsonPath('mbti_access_hub_v1.mbti_form_v1.form_code', 'mbti_93')
            ->assertJsonPath('mbti_access_hub_v1.report_access.can_view_report', true)
            ->assertJsonPath('mbti_access_hub_v1.report_access.attempt_id', $attemptId)
            ->assertJsonPath('mbti_access_hub_v1.report_access.order_no', $orderNo)
            ->assertJsonPath('mbti_access_hub_v1.report_access.report_url', "/api/v0.3/attempts/{$attemptId}/report")
            ->assertJsonPath('mbti_access_hub_v1.report_access.source', 'report_gate')
            ->assertJsonPath('mbti_access_hub_v1.pdf_access.can_download_pdf', true)
            ->assertJsonPath('mbti_access_hub_v1.pdf_access.report_pdf_url', "/api/v0.3/attempts/{$attemptId}/report.pdf")
            ->assertJsonPath('mbti_access_hub_v1.pdf_access.source', 'report_gate')
            ->assertJsonPath('mbti_access_hub_v1.recovery.can_lookup_order', true)
            ->assertJsonPath('mbti_access_hub_v1.recovery.can_request_claim_email', false)
            ->assertJsonPath('mbti_access_hub_v1.recovery.can_resend', false)
            ->assertJsonPath('mbti_access_hub_v1.recovery.attempt_id', $attemptId)
            ->assertJsonPath('mbti_access_hub_v1.workspace_lite.has_entry', true)
            ->assertJsonPath('mbti_access_hub_v1.workspace_lite.entry_kind', 'mbti_history')
            ->assertJsonPath('mbti_access_hub_v1.workspace_lite.attempt_id', $attemptId)
            ->assertJsonPath('mbti_access_hub_v1.exact_result_entry.attempt_id', $attemptId)
            ->assertJsonPath('mbti_access_hub_v1.exact_result_entry.access_state', 'ready')
            ->assertJsonPath('mbti_access_hub_v1.exact_result_entry.report_state', 'ready')
            ->assertJsonPath('mbti_access_hub_v1.exact_result_entry.unlock_stage', 'full')
            ->assertJsonPath('mbti_access_hub_v1.exact_result_entry.unlock_source', 'none')
            ->assertJsonPath('mbti_access_hub_v1.exact_result_entry.invite_unlock_v1.unlock_stage', 'full')
            ->assertJsonPath('mbti_access_hub_v1.exact_result_entry.ready_to_enter', true)
            ->assertJsonPath('mbti_access_hub_v1.exact_result_entry.actions.page_href', "/result/{$attemptId}")
            ->assertJsonPath('mbti_access_hub_v1.exact_result_entry.mbti_form_v1.form_code', 'mbti_93')
            ->assertJsonPath('exact_result_entry.attempt_id', $attemptId)
            ->assertJsonPath('exact_result_entry.unlock_stage', 'full')
            ->assertJsonPath('exact_result_entry.unlock_source', 'none')
            ->assertJsonPath('exact_result_entry.invite_unlock_v1.unlock_stage', 'full')
            ->assertJsonPath('exact_result_entry.mbti_form_v1.form_code', 'mbti_93')
            ->assertJsonPath('exact_result_entry.ready_to_enter', true)
            ->assertJsonPath('exact_result_entry.actions.page_href', "/result/{$attemptId}");
    }

    public function test_paid_mbti_order_exposes_exact_result_entry_without_claiming_ready_before_projection_unlocks(): void
    {
        $orderNo = 'ord_fallback_mbti_pending_'.Str::lower(Str::random(10));
        $attemptId = (string) Str::uuid();
        $this->insertAttempt($attemptId, self::ANON_OWNER, 'MBTI');
        $this->insertOrderForOwner($orderNo, $attemptId, 'paid');

        $token = $this->issueAnonToken(self::ANON_OWNER);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/orders/'.$orderNo);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('grant_state', 'not_started')
            ->assertJsonPath('exact_result_entry.attempt_id', $attemptId)
            ->assertJsonPath('exact_result_entry.access_state', 'pending')
            ->assertJsonPath('exact_result_entry.report_state', 'pending')
            ->assertJsonPath('exact_result_entry.ready_to_enter', false)
            ->assertJsonPath('exact_result_entry.actions.page_href', "/result/{$attemptId}")
            ->assertJsonPath('exact_result_entry.actions.wait_href', "/result/{$attemptId}")
            ->assertJsonPath('mbti_access_hub_v1.access_state', 'pending')
            ->assertJsonPath('mbti_access_hub_v1.report_access.can_view_report', false);
    }

    public function test_paid_mbti_order_repairs_exact_result_entry_when_result_exists_and_active_grant_is_present(): void
    {
        $orderNo = 'ord_fallback_mbti_repair_'.Str::lower(Str::random(10));
        $attemptId = (string) Str::uuid();
        $this->insertAttempt($attemptId, self::ANON_OWNER, 'MBTI');
        $this->insertOrderForOwner($orderNo, $attemptId, 'paid');
        $this->insertResult($attemptId);
        $this->insertActiveGrant($attemptId, $orderNo);

        $token = $this->issueAnonToken(self::ANON_OWNER);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/orders/'.$orderNo);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('grant_state', 'not_started')
            ->assertJsonPath('exact_result_entry.attempt_id', $attemptId)
            ->assertJsonPath('exact_result_entry.access_state', 'ready')
            ->assertJsonPath('exact_result_entry.report_state', 'ready')
            ->assertJsonPath('exact_result_entry.reason_code', 'projection_repaired_from_entitlement')
            ->assertJsonPath('exact_result_entry.ready_to_enter', true)
            ->assertJsonPath('exact_result_entry.actions.page_href', "/result/{$attemptId}")
            ->assertJsonPath('mbti_access_hub_v1.access_state', 'ready')
            ->assertJsonPath('mbti_access_hub_v1.report_access.can_view_report', true);

        $this->assertDatabaseHas('unified_access_projections', [
            'attempt_id' => $attemptId,
            'access_state' => 'ready',
            'report_state' => 'ready',
            'reason_code' => 'projection_repaired_from_entitlement',
        ]);
    }

    public function test_paid_mbti_order_does_not_repair_from_different_order_grant(): void
    {
        $orderNo = 'ord_fallback_mbti_order_'.Str::lower(Str::random(10));
        $otherOrderNo = 'ord_fallback_mbti_other_'.Str::lower(Str::random(10));
        $attemptId = (string) Str::uuid();
        $this->insertAttempt($attemptId, self::ANON_OWNER, 'MBTI');
        $this->insertOrderForOwner($orderNo, $attemptId, 'paid');
        $this->insertResult($attemptId);
        $this->insertActiveGrant($attemptId, $otherOrderNo);

        $token = $this->issueAnonToken(self::ANON_OWNER);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/orders/'.$orderNo);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('exact_result_entry.attempt_id', $attemptId)
            ->assertJsonPath('exact_result_entry.access_state', 'locked')
            ->assertJsonPath('exact_result_entry.report_state', 'ready')
            ->assertJsonPath('exact_result_entry.reason_code', 'projection_missing_result_ready')
            ->assertJsonPath('exact_result_entry.ready_to_enter', false)
            ->assertJsonPath('mbti_access_hub_v1.access_state', 'locked')
            ->assertJsonPath('mbti_access_hub_v1.report_access.can_view_report', false);

        $this->assertDatabaseMissing('unified_access_projections', [
            'attempt_id' => $attemptId,
            'reason_code' => 'projection_repaired_from_entitlement',
        ]);
    }

    public function test_paid_mbti_order_does_not_repair_from_same_order_grant_owned_by_another_actor(): void
    {
        $orderNo = 'ord_fallback_mbti_actor_'.Str::lower(Str::random(10));
        $attemptId = (string) Str::uuid();
        $this->insertAttempt($attemptId, self::ANON_OWNER, 'MBTI');
        $this->insertOrderForOwner($orderNo, $attemptId, 'paid');
        $this->insertResult($attemptId);
        $this->insertActiveGrant($attemptId, $orderNo, self::ANON_ATTACKER, self::ANON_ATTACKER);

        $token = $this->issueAnonToken(self::ANON_OWNER);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/orders/'.$orderNo);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('exact_result_entry.attempt_id', $attemptId)
            ->assertJsonPath('exact_result_entry.access_state', 'locked')
            ->assertJsonPath('exact_result_entry.report_state', 'ready')
            ->assertJsonPath('exact_result_entry.reason_code', 'projection_missing_result_ready')
            ->assertJsonPath('exact_result_entry.ready_to_enter', false)
            ->assertJsonPath('mbti_access_hub_v1.access_state', 'locked')
            ->assertJsonPath('mbti_access_hub_v1.report_access.can_view_report', false);

        $this->assertDatabaseMissing('unified_access_projections', [
            'attempt_id' => $attemptId,
            'reason_code' => 'projection_repaired_from_entitlement',
        ]);
    }

    public function test_valid_payment_recovery_token_reads_pending_order_without_owner_identity(): void
    {
        config([
            'app.frontend_url' => 'https://web.example.test',
            'app.url' => 'http://localhost:8000',
            'payments.providers.alipay.enabled' => true,
        ]);

        $orderNo = 'ord_recovery_pending_'.Str::lower(Str::random(10));
        $attemptId = (string) Str::uuid();
        $this->insertAttempt($attemptId, self::ANON_OWNER, 'BIG5_OCEAN', 'en');
        $this->insertOrderForOwner($orderNo, $attemptId, 'created', 'alipay');

        $token = app(PaymentRecoveryToken::class)->issue($orderNo);
        $response = $this->getJson('/api/v0.3/orders/'.$orderNo.'?payment_recovery_token='.urlencode($token));

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ownership_verified', false)
            ->assertJsonPath('order_no', $orderNo)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('attempt_id', $attemptId)
            ->assertJsonPath('payment_recovery_token', $token)
            ->assertJsonPath('wait_url', "https://web.example.test/en/pay/wait?order_no={$orderNo}")
            ->assertJsonPath('result_url', "https://web.example.test/en/result/{$attemptId}")
            ->assertJsonPath('provider', 'alipay')
            ->assertJsonPath('pay.type', 'html')
            ->assertJsonPath('checkout_url', null)
            ->assertJsonMissingPath('order');

        $this->assertStringNotContainsString('paymentRecoveryToken=', (string) $response->json('pay.value'));
    }

    public function test_valid_payment_recovery_token_reads_paid_order_without_owner_identity(): void
    {
        config(['app.frontend_url' => 'https://web.example.test']);

        $orderNo = 'ord_recovery_paid_'.Str::lower(Str::random(10));
        $attemptId = (string) Str::uuid();
        $this->insertAttempt($attemptId, self::ANON_OWNER, 'BIG5_OCEAN', 'zh-CN');
        $this->insertOrderForOwner($orderNo, $attemptId, 'fulfilled');

        $token = app(PaymentRecoveryToken::class)->issue($orderNo);
        $response = $this->withHeaders([
            'X-Payment-Recovery-Token' => $token,
        ])->getJson('/api/v0.3/orders/'.$orderNo);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ownership_verified', false)
            ->assertJsonPath('order_no', $orderNo)
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('attempt_id', $attemptId)
            ->assertJsonPath('payment_recovery_token', $token)
            ->assertJsonPath('result_url', "https://web.example.test/zh/result/{$attemptId}")
            ->assertJsonPath('pay', null)
            ->assertJsonPath('checkout_url', null)
            ->assertJsonMissingPath('order');
    }

    public function test_expired_payment_recovery_token_is_rejected(): void
    {
        $orderNo = 'ord_recovery_expired_'.Str::lower(Str::random(10));
        $this->insertOrderForOwner($orderNo);

        $token = $this->issuePaymentRecoveryToken($orderNo, time() - 60);
        $response = $this->getJson('/api/v0.3/orders/'.$orderNo.'?payment_recovery_token='.urlencode($token));

        $response->assertStatus(403)
            ->assertJsonPath('error_code', 'PAYMENT_RECOVERY_TOKEN_EXPIRED');
    }

    public function test_tampered_payment_recovery_token_is_rejected(): void
    {
        $orderNo = 'ord_recovery_tampered_'.Str::lower(Str::random(10));
        $this->insertOrderForOwner($orderNo);

        $token = app(PaymentRecoveryToken::class)->issue($orderNo);
        [$payloadSegment, $signatureSegment] = explode('.', $token, 2);
        $tampered = $payloadSegment.'.'
            .(str_starts_with($signatureSegment, 'a') ? 'b' : 'a')
            .substr($signatureSegment, 1);

        $response = $this->getJson('/api/v0.3/orders/'.$orderNo.'?payment_recovery_token='.urlencode($tampered));

        $response->assertStatus(403)
            ->assertJsonPath('error_code', 'PAYMENT_RECOVERY_TOKEN_INVALID');
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();
        $tokenHash = hash('sha256', $token);

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => $tokenHash,
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('auth_tokens')->insert([
            'token_hash' => $tokenHash,
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'meta_json' => null,
            'expires_at' => now()->addDay(),
            'revoked_at' => null,
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function insertAttempt(
        string $attemptId,
        string $anonId,
        string $scaleCode = 'BIG5_OCEAN',
        string $locale = 'zh-CN'
    ): void {
        $isMbti = strtoupper($scaleCode) === 'MBTI';

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => $locale,
            'question_count' => $isMbti ? 144 : 120,
            'answers_summary_json' => json_encode(['stage' => 'seed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'pack_id' => $isMbti ? (string) config('content_packs.default_pack_id') : 'BIG5_OCEAN',
            'dir_version' => $isMbti ? 'MBTI-CN-v0.3' : 'v1',
            'content_package_version' => $isMbti ? 'v0.3' : 'v1',
            'scoring_spec_version' => $isMbti ? '2026.01.mbti_144' : 'big5_spec_2026Q1_v1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertResult(string $attemptId): void
    {
        DB::table('results')->insert([
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'org_id' => 0,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'scores_pct' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'axis_states' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'content_package_version' => 'result-v1',
            'result_json' => json_encode(['summary' => 'seed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => 'result-score-v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array{
     *   access_state:string,
     *   report_state:string,
     *   pdf_state:string,
     *   reason_code:string,
     *   payload_json:array<string,mixed>
     * } $overrides
     */
    private function insertProjection(string $attemptId, array $overrides): void
    {
        DB::table('unified_access_projections')->insert([
            'attempt_id' => $attemptId,
            'access_state' => $overrides['access_state'],
            'report_state' => $overrides['report_state'],
            'pdf_state' => $overrides['pdf_state'],
            'reason_code' => $overrides['reason_code'],
            'projection_version' => 1,
            'actions_json' => json_encode(['report' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload_json' => json_encode($overrides['payload_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'produced_at' => now(),
            'refreshed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertActiveGrant(
        string $attemptId,
        string $orderNo,
        string $userId = self::ANON_OWNER,
        string $benefitRef = self::ANON_OWNER
    ): void {
        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'user_id' => $userId,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'order_no' => $orderNo,
            'status' => 'active',
            'expires_at' => null,
            'benefit_ref' => $benefitRef,
            'benefit_type' => 'report_unlock',
            'source_order_id' => (string) Str::uuid(),
            'source_event_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOrderForOwner(
        string $orderNo,
        ?string $attemptId = null,
        string $status = 'created',
        string $provider = 'billing'
    ): void {
        $now = now();
        $row = [
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => self::ANON_OWNER,
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 1990,
            'currency' => 'USD',
            'status' => $status,
            'provider' => $provider,
            'external_trade_no' => null,
            'paid_at' => $status === 'paid' ? $now : null,
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

    private function issuePaymentRecoveryToken(string $orderNo, int $exp): string
    {
        $payload = [
            'order_no' => $orderNo,
            'purpose' => \App\Models\Order::PAYMENT_RECOVERY_PURPOSE,
            'exp' => $exp,
        ];

        $payloadSegment = $this->base64UrlEncode((string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        $signature = hash_hmac('sha256', $payloadSegment, $this->paymentRecoverySigningKey(), true);

        return $payloadSegment.'.'.$this->base64UrlEncode($signature);
    }

    private function paymentRecoverySigningKey(): string
    {
        $key = (string) config('app.key', '');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
