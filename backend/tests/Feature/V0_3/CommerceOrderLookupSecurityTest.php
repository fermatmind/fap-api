<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommerceOrderLookupSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const ANON_OWNER = 'lookup_owner';

    public function test_lookup_requires_email_when_identity_missing(): void
    {
        $response = $this->postJson('/api/v0.3/orders/lookup', [
            'order_no' => 'ord_missing_identity',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
    }

    public function test_lookup_with_wrong_email_is_blinded(): void
    {
        $orderNo = 'ord_lookup_'.Str::lower(Str::random(8));
        $this->insertOrderForLookup($orderNo, 'owner@example.com');

        $response = $this->postJson('/api/v0.3/orders/lookup', [
            'order_no' => $orderNo,
            'email' => 'attacker@example.com',
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('error_code', 'ORDER_NOT_FOUND')
            ->assertJsonMissingPath('payment_recovery_token')
            ->assertJsonMissingPath('wait_url')
            ->assertJsonMissingPath('amount_cents')
            ->assertJsonMissingPath('provider')
            ->assertJsonMissingPath('latest_payment_attempt');
    }

    public function test_lookup_with_matching_email_hash_returns_delivery_contract(): void
    {
        $orderNo = 'ord_lookup_'.Str::lower(Str::random(8));
        $userId = $this->createUser('owner@example.com');
        $attemptId = (string) Str::uuid();
        $this->insertAttempt($attemptId, self::ANON_OWNER, (string) $userId);
        $this->insertOrderForLookup($orderNo, 'owner@example.com', $attemptId, 'paid', (string) $userId);

        $response = $this->postJson('/api/v0.3/orders/lookup', [
            'order_no' => $orderNo,
            'email' => 'owner@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('order_no', $orderNo)
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('attempt_id', $attemptId)
            ->assertJsonPath('big5_form_v1.form_code', 'big5_120')
            ->assertJsonPath('big5_form_v1.question_count', 120)
            ->assertJsonPath('big5_form_v1.scale_code', 'BIG5_OCEAN')
            ->assertJsonPath('delivery.can_view_report', true)
            ->assertJsonPath('delivery.report_url', "/api/v0.3/attempts/{$attemptId}/report")
            ->assertJsonPath('delivery.can_download_pdf', true)
            ->assertJsonPath('delivery.report_pdf_url', "/api/v0.3/attempts/{$attemptId}/report.pdf")
            ->assertJsonPath('delivery.can_resend', true)
            ->assertJsonPath('delivery.contact_email_present', true)
            ->assertJsonPath('delivery.last_delivery_email_sent_at', null)
            ->assertJsonPath('delivery.can_request_claim_email', true);
    }

    public function test_lookup_with_matching_email_hash_returns_mbti_access_hub_for_mbti_attempt(): void
    {
        $orderNo = 'ord_lookup_mbti_'.Str::lower(Str::random(8));
        $userId = $this->createUser('owner@example.com');
        $attemptId = (string) Str::uuid();
        $compareInviteId = (string) Str::uuid();
        $this->insertAttempt($attemptId, self::ANON_OWNER, (string) $userId, 'MBTI');
        $this->insertOrderForLookup($orderNo, 'owner@example.com', $attemptId, 'paid', (string) $userId, [
            'attribution' => [
                'share_id' => 'share_001',
                'compare_invite_id' => $compareInviteId,
            ],
        ]);
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

        $response = $this->postJson('/api/v0.3/orders/lookup', [
            'order_no' => $orderNo,
            'email' => 'owner@example.com',
        ]);

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
            ->assertJsonPath('mbti_access_hub_v1.report_access.source', 'report_gate')
            ->assertJsonPath('mbti_access_hub_v1.pdf_access.can_download_pdf', true)
            ->assertJsonPath('mbti_access_hub_v1.pdf_access.source', 'report_gate')
            ->assertJsonPath('mbti_access_hub_v1.recovery.can_lookup_order', true)
            ->assertJsonPath('mbti_access_hub_v1.recovery.can_request_claim_email', true)
            ->assertJsonPath('mbti_access_hub_v1.recovery.can_resend', true)
            ->assertJsonPath('mbti_access_hub_v1.recovery.attempt_id', $attemptId)
            ->assertJsonPath('mbti_access_hub_v1.recovery.share_id', 'share_001')
            ->assertJsonPath('mbti_access_hub_v1.recovery.compare_invite_id', $compareInviteId)
            ->assertJsonPath('mbti_access_hub_v1.workspace_lite.has_entry', true)
            ->assertJsonPath('mbti_access_hub_v1.workspace_lite.entry_kind', 'mbti_history')
            ->assertJsonPath('mbti_access_hub_v1.workspace_lite.attempt_id', $attemptId)
            ->assertJsonPath('exact_result_entry.attempt_id', $attemptId)
            ->assertJsonPath('exact_result_entry.unlock_stage', 'full')
            ->assertJsonPath('exact_result_entry.unlock_source', 'none')
            ->assertJsonPath('exact_result_entry.invite_unlock_v1.unlock_stage', 'full')
            ->assertJsonPath('exact_result_entry.mbti_form_v1.form_code', 'mbti_93')
            ->assertJsonPath('exact_result_entry.ready_to_enter', true)
            ->assertJsonPath('exact_result_entry.actions.page_href', "/result/{$attemptId}");
    }

    public function test_lookup_repairs_exact_result_entry_when_result_exists_and_active_grant_is_present(): void
    {
        $orderNo = 'ord_lookup_mbti_repair_'.Str::lower(Str::random(8));
        $userId = $this->createUser('owner@example.com');
        $attemptId = (string) Str::uuid();
        $this->insertAttempt($attemptId, self::ANON_OWNER, (string) $userId, 'MBTI');
        $this->insertOrderForLookup($orderNo, 'owner@example.com', $attemptId, 'paid', (string) $userId);
        $this->insertResult($attemptId);
        $this->insertActiveGrant($attemptId, $orderNo, (string) $userId);

        $response = $this->postJson('/api/v0.3/orders/lookup', [
            'order_no' => $orderNo,
            'email' => 'owner@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('mbti_access_hub_v1.access_state', 'ready')
            ->assertJsonPath('mbti_access_hub_v1.report_access.can_view_report', true)
            ->assertJsonPath('exact_result_entry.attempt_id', $attemptId)
            ->assertJsonPath('exact_result_entry.access_state', 'ready')
            ->assertJsonPath('exact_result_entry.report_state', 'ready')
            ->assertJsonPath('exact_result_entry.reason_code', 'projection_repaired_from_entitlement')
            ->assertJsonPath('exact_result_entry.ready_to_enter', true)
            ->assertJsonPath('exact_result_entry.actions.page_href', "/result/{$attemptId}");

        $this->assertDatabaseHas('unified_access_projections', [
            'attempt_id' => $attemptId,
            'access_state' => 'ready',
            'report_state' => 'ready',
            'reason_code' => 'projection_repaired_from_entitlement',
        ]);
    }

    public function test_lookup_with_token_owner_works_without_email(): void
    {
        $orderNo = 'ord_lookup_'.Str::lower(Str::random(8));
        $this->insertOrderForLookup($orderNo, 'owner@example.com');

        $token = $this->issueAnonToken(self::ANON_OWNER);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/orders/lookup', [
            'order_no' => $orderNo,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('order_no', $orderNo)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('attempt_id', null)
            ->assertJsonPath('delivery.can_view_report', false)
            ->assertJsonPath('delivery.report_url', null)
            ->assertJsonPath('delivery.can_download_pdf', false)
            ->assertJsonPath('delivery.report_pdf_url', null)
            ->assertJsonPath('delivery.can_resend', false)
            ->assertJsonPath('delivery.contact_email_present', true)
            ->assertJsonPath('delivery.last_delivery_email_sent_at', null)
            ->assertJsonPath('delivery.can_request_claim_email', false);
    }

    private function createUser(string $email): int
    {
        $userId = random_int(100000, 999999);

        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Lookup Owner',
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
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
        ?string $userId = null,
        string $scaleCode = 'BIG5_OCEAN'
    ): void {
        $isMbti = strtoupper($scaleCode) === 'MBTI';

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => 0,
            'user_id' => $userId,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
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

    private function insertActiveGrant(string $attemptId, string $orderNo, string $userId): void
    {
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
            'benefit_ref' => self::ANON_OWNER,
            'benefit_type' => 'report_unlock',
            'source_order_id' => (string) Str::uuid(),
            'source_event_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOrderForLookup(
        string $orderNo,
        string $email,
        ?string $attemptId = null,
        string $status = 'created',
        ?string $userId = null,
        ?array $meta = null
    ): void {
        $now = now();
        $row = [
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => $userId,
            'anon_id' => self::ANON_OWNER,
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 1990,
            'currency' => 'USD',
            'status' => $status,
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => $status === 'paid' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('orders', 'contact_email_hash')) {
            $row['contact_email_hash'] = hash('sha256', mb_strtolower(trim($email), 'UTF-8'));
        }
        if (Schema::hasColumn('orders', 'meta_json')) {
            $row['meta_json'] = $meta !== null
                ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;
        }
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
}
