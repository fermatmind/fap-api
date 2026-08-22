<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Commerce\EntitlementManager;
use App\Services\Commerce\RewardedAdUnlockService;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RewardedAdUnlockContractTest extends TestCase
{
    use RefreshDatabase;

    private const AD_UNIT_ID = 'adunit-c14fa8b4fc08bbc1';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        (new ScaleRegistrySeeder)->run();
        config()->set('report_unlock.providers.rewarded_ad.available', true);
        config()->set('report_unlock.rollout_scales', ['MBTI']);
        config()->set('report_unlock.supported_locales', ['zh-CN']);
    }

    public function test_only_owner_can_create_a_pending_session_and_it_never_grants_access(): void
    {
        $attemptId = $this->createAttempt('anon_rewarded_owner');
        $ownerHeaders = $this->headersFor('anon_rewarded_owner');

        $this->withHeaders($this->headersFor('anon_rewarded_foreign'))
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions', ['ad_unit_id' => self::AD_UNIT_ID])
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');

        $created = $this->withHeaders($ownerHeaders)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions', ['ad_unit_id' => self::AD_UNIT_ID])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('idempotent', false)
            ->assertJsonPath('session.status', 'pending')
            ->assertJsonPath('session.trust_mode', 'client_is_ended_residual_risk_accepted');
        $this->assertIsString($created->json('session.id'));
        $this->assertSame(0, DB::table('benefit_grants')->where('attempt_id', $attemptId)->count());

        $this->withHeaders($ownerHeaders)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions', ['ad_unit_id' => self::AD_UNIT_ID])
            ->assertOk()
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('session.id', $created->json('session.id'));
    }

    public function test_complete_rejects_missing_incomplete_and_cross_attempt_sessions_without_a_grant(): void
    {
        $attemptId = $this->createAttempt('anon_rewarded_replay');
        $otherAttemptId = $this->createAttempt('anon_rewarded_replay');
        $headers = $this->headersFor('anon_rewarded_replay');

        $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions/'.Str::uuid().'/complete', [
                'ad_unit_id' => self::AD_UNIT_ID,
                'is_ended' => true,
            ])
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'REWARDED_AD_SESSION_NOT_FOUND');

        $sessionId = (string) $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions', ['ad_unit_id' => self::AD_UNIT_ID])
            ->assertOk()
            ->json('session.id');

        $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions/'.$sessionId.'/complete', [
                'ad_unit_id' => self::AD_UNIT_ID,
                'is_ended' => false,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'REWARDED_AD_INCOMPLETE');
        $this->assertSame(0, DB::table('benefit_grants')->where('attempt_id', $attemptId)->count());

        $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$otherAttemptId.'/rewarded-ad-sessions/'.$sessionId.'/complete', [
                'ad_unit_id' => self::AD_UNIT_ID,
                'is_ended' => true,
            ])
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'REWARDED_AD_SESSION_NOT_FOUND');
        $this->assertSame(0, DB::table('benefit_grants')->where('attempt_id', $attemptId)->count());
    }

    public function test_create_does_not_reuse_a_pending_session_for_a_different_ad_unit(): void
    {
        $anonId = 'anon_rewarded_ad_unit_switch';
        $attemptId = $this->createAttempt($anonId);
        $headers = $this->headersFor($anonId);
        $firstSessionId = (string) $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions', ['ad_unit_id' => self::AD_UNIT_ID])
            ->assertOk()
            ->json('session.id');

        $secondSessionId = (string) $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions', ['ad_unit_id' => 'adunit-c14fa8b4fc08bbc2'])
            ->assertOk()
            ->assertJsonPath('idempotent', false)
            ->json('session.id');

        $this->assertNotSame($firstSessionId, $secondSessionId);
        $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions/'.$secondSessionId.'/complete', [
                'ad_unit_id' => 'adunit-c14fa8b4fc08bbc2',
                'is_ended' => true,
            ])
            ->assertOk()
            ->assertJsonPath('report_access.access_level', 'full');
    }

    public function test_complete_is_idempotent_and_refreshes_the_unified_report_access_projection(): void
    {
        $attemptId = $this->createAttempt('anon_rewarded_complete');
        $headers = $this->headersFor('anon_rewarded_complete');
        $sessionId = (string) $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions', ['ad_unit_id' => self::AD_UNIT_ID])
            ->assertOk()
            ->json('session.id');

        $completed = $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions/'.$sessionId.'/complete', [
                'ad_unit_id' => self::AD_UNIT_ID,
                'is_ended' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('session.status', 'completed')
            ->assertJsonPath('report_access.access_level', 'full')
            ->assertJsonPath('report_access.full_report_entitlement_v1.unlock_source', 'rewarded_ad');
        $this->assertNotEmpty($completed->json('grant_id'));
        $this->assertSame(1, DB::table('benefit_grants')->where('attempt_id', $attemptId)->where('status', 'active')->count());
        $grantMeta = json_decode(
            (string) DB::table('benefit_grants')->where('attempt_id', $attemptId)->value('meta_json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame('rewarded_ad', $grantMeta['unlock_source'] ?? null);

        $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions/'.$sessionId.'/complete', [
                'ad_unit_id' => self::AD_UNIT_ID,
                'is_ended' => true,
            ])
            ->assertOk()
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('session.status', 'completed');
        $this->assertSame(1, DB::table('benefit_grants')->where('attempt_id', $attemptId)->where('status', 'active')->count());

        $service = app(RewardedAdUnlockService::class);
        $sessionKey = (string) (new \ReflectionMethod($service, 'sessionKey'))->invoke($service, $sessionId);
        $interruptedSession = (array) Cache::get($sessionKey);
        $interruptedSession['status'] = 'pending';
        unset($interruptedSession['completed_at'], $interruptedSession['grant_id']);
        Cache::put($sessionKey, $interruptedSession, now()->addMinutes(5));

        $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions/'.$sessionId.'/complete', [
                'ad_unit_id' => self::AD_UNIT_ID,
                'is_ended' => true,
            ])
            ->assertOk()
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('session.status', 'completed')
            ->assertJsonPath('report_access.access_level', 'full');
        $this->assertSame(1, DB::table('benefit_grants')->where('attempt_id', $attemptId)->where('status', 'active')->count());
    }

    public function test_big_five_incomplete_ad_stays_locked_and_completed_ad_grants_only_big_five_access(): void
    {
        config()->set('report_unlock.big5_rollout.mode', 'allowlist_only');
        config()->set('report_unlock.big5_rollout.allowed_anon_ids', ['anon_big5_rewarded']);
        $attemptId = $this->createAttempt('anon_big5_rewarded', 'BIG5_OCEAN');
        $headers = $this->headersFor('anon_big5_rewarded');

        $sessionId = (string) $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions', ['ad_unit_id' => self::AD_UNIT_ID])
            ->assertOk()
            ->json('session.id');

        $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions/'.$sessionId.'/complete', [
                'ad_unit_id' => self::AD_UNIT_ID,
                'is_ended' => false,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'REWARDED_AD_INCOMPLETE');
        $this->assertSame(0, DB::table('benefit_grants')->where('attempt_id', $attemptId)->count());

        $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions/'.$sessionId.'/complete', [
                'ad_unit_id' => self::AD_UNIT_ID,
                'is_ended' => true,
            ])
            ->assertOk()
            ->assertJsonPath('report_access.access_level', 'full');
        $this->assertSame('BIG5_FULL_REPORT', DB::table('benefit_grants')->where('attempt_id', $attemptId)->value('benefit_code'));
        $this->assertSame(0, DB::table('benefit_grants')->where('attempt_id', $attemptId)->where('benefit_code', 'MBTI_REPORT_FULL')->count());
    }

    public function test_completion_creates_a_new_rewarded_grant_after_a_historical_grant_was_revoked(): void
    {
        $anonId = 'anon_rewarded_revoked';
        $attemptId = $this->createAttempt($anonId);
        $this->insertHistoricalRevokedGrant($attemptId, $anonId);
        $headers = $this->headersFor($anonId);
        $sessionId = (string) $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions', ['ad_unit_id' => self::AD_UNIT_ID])
            ->assertOk()
            ->json('session.id');

        $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions/'.$sessionId.'/complete', [
                'ad_unit_id' => self::AD_UNIT_ID,
                'is_ended' => true,
            ])
            ->assertOk()
            ->assertJsonPath('report_access.access_level', 'full')
            ->assertJsonPath('report_access.full_report_entitlement_v1.unlock_source', 'rewarded_ad');

        $this->assertSame(1, DB::table('benefit_grants')->where('attempt_id', $attemptId)->where('status', 'revoked')->count());
        $this->assertSame(1, DB::table('benefit_grants')->where('attempt_id', $attemptId)->where('status', 'active')->count());
        $this->assertSame(2, DB::table('benefit_grants')->where('attempt_id', $attemptId)->count());
    }

    public function test_completion_is_serialized_per_attempt_before_any_grant_is_created(): void
    {
        $anonId = 'anon_rewarded_locked';
        $attemptId = $this->createAttempt($anonId);
        $headers = $this->headersFor($anonId);
        $sessionId = (string) $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions', ['ad_unit_id' => self::AD_UNIT_ID])
            ->assertOk()
            ->json('session.id');

        $service = app(RewardedAdUnlockService::class);
        $keyMethod = new \ReflectionMethod($service, 'attemptCompletionLockKey');
        $lockKey = (string) $keyMethod->invoke($service, Attempt::query()->findOrFail($attemptId));
        $lock = Cache::lock($lockKey, 15);
        $this->assertTrue($lock->get());

        try {
            $this->withHeaders($headers)
                ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions/'.$sessionId.'/complete', [
                    'ad_unit_id' => self::AD_UNIT_ID,
                    'is_ended' => true,
                ])
                ->assertStatus(409)
                ->assertJsonPath('error_code', 'REWARDED_AD_SESSION_IN_PROGRESS');
            $this->assertSame(0, DB::table('benefit_grants')->where('attempt_id', $attemptId)->count());
        } finally {
            $lock->release();
        }
    }

    public function test_completion_creates_a_current_actor_grant_after_an_attempt_is_claimed(): void
    {
        $oldAnonId = 'anon_rewarded_before_claim';
        $currentAnonId = 'anon_rewarded_after_claim';
        $userId = '144';
        $attemptId = $this->createAttempt($oldAnonId);
        $this->insertHistoricalActiveGrant($attemptId, $oldAnonId);
        DB::table('users')->insert([
            'id' => (int) $userId,
            'name' => 'Rewarded Ad Claimed Owner',
            'email' => 'rewarded-ad-claimed-owner@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('attempts')->where('id', $attemptId)->update(['user_id' => $userId]);
        $headers = $this->headersFor($currentAnonId, $userId);

        $sessionId = (string) $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions', ['ad_unit_id' => self::AD_UNIT_ID])
            ->assertOk()
            ->json('session.id');

        $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions/'.$sessionId.'/complete', [
                'ad_unit_id' => self::AD_UNIT_ID,
                'is_ended' => true,
            ])
            ->assertOk()
            ->assertJsonPath('report_access.access_level', 'full')
            ->assertJsonPath('report_access.full_report_entitlement_v1.unlock_source', 'rewarded_ad');

        $this->assertSame(2, DB::table('benefit_grants')->where('attempt_id', $attemptId)->where('status', 'active')->count());
        $this->assertSame(1, DB::table('benefit_grants')->where('attempt_id', $attemptId)->where('user_id', $userId)->count());
    }

    public function test_refund_retry_does_not_revoke_a_later_rewarded_ad_grant(): void
    {
        $anonId = 'anon_rewarded_refund_retry';
        $attemptId = $this->createAttempt($anonId);
        $orderNo = 'ord_rewarded_refund_retry';
        $this->insertRefundedOrder($orderNo, $attemptId, $anonId);
        app(EntitlementManager::class)->grantAttemptUnlock(
            0,
            null,
            $anonId,
            'MBTI_REPORT_FULL',
            $attemptId,
            $orderNo,
            'attempt',
            null,
            [],
            ['unlock_source' => 'self_purchase'],
        );
        $firstRefund = app(EntitlementManager::class)->revokeByOrderNo(0, $orderNo);
        $this->assertSame(1, (int) ($firstRefund['revoked'] ?? 0));
        $this->insertFulfilledGiftRequest(
            (string) DB::table('orders')->where('order_no', $orderNo)->value('id'),
            $attemptId,
            $anonId,
        );

        $headers = $this->headersFor($anonId);
        $sessionId = (string) $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions', ['ad_unit_id' => self::AD_UNIT_ID])
            ->assertOk()
            ->json('session.id');
        $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions/'.$sessionId.'/complete', [
                'ad_unit_id' => self::AD_UNIT_ID,
                'is_ended' => true,
            ])
            ->assertOk()
            ->assertJsonPath('report_access.access_level', 'full');

        $retryRefund = app(EntitlementManager::class)->revokeByOrderNo(0, $orderNo);
        $this->assertSame(0, (int) ($retryRefund['revoked'] ?? -1));
        $this->assertTrue((bool) ($retryRefund['idempotent'] ?? false));
        $this->assertSame('refunded', (string) DB::table('report_gift_requests')
            ->where('purchased_order_id', DB::table('orders')->where('order_no', $orderNo)->value('id'))
            ->value('status'));
        $this->assertSame(1, DB::table('benefit_grants')
            ->where('attempt_id', $attemptId)
            ->where('status', 'active')
            ->whereNull('order_no')
            ->count());
    }

    public function test_expired_session_and_disabled_or_iq_unlocks_fail_closed(): void
    {
        $attemptId = $this->createAttempt('anon_rewarded_expired');
        $headers = $this->headersFor('anon_rewarded_expired');
        $sessionId = (string) $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions', ['ad_unit_id' => self::AD_UNIT_ID])
            ->assertOk()
            ->json('session.id');

        $this->travel(11)->minutes();
        try {
            $this->withHeaders($headers)
                ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions/'.$sessionId.'/complete', [
                    'ad_unit_id' => self::AD_UNIT_ID,
                    'is_ended' => true,
                ])
                ->assertStatus(404)
                ->assertJsonPath('error_code', 'REWARDED_AD_SESSION_NOT_FOUND');
        } finally {
            $this->travelBack();
        }

        config()->set('report_unlock.providers.rewarded_ad.available', false);
        $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions', ['ad_unit_id' => self::AD_UNIT_ID])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'REWARDED_AD_UNAVAILABLE');

        config()->set('report_unlock.providers.rewarded_ad.available', true);
        $iqAttemptId = $this->createAttempt('anon_rewarded_iq', 'IQ_RAVEN');
        $this->withHeaders($this->headersFor('anon_rewarded_iq'))
            ->postJson('/api/v0.3/attempts/'.$iqAttemptId.'/rewarded-ad-sessions', ['ad_unit_id' => self::AD_UNIT_ID])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'IQ_REWARDED_AD_DISABLED');
    }

    public function test_audit_log_uses_fingerprints_without_raw_attempt_actor_or_ad_unit_values(): void
    {
        $attemptId = $this->createAttempt('anon_rewarded_redaction');
        $headers = $this->headersFor('anon_rewarded_redaction');
        Log::spy();

        $sessionId = (string) $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions', ['ad_unit_id' => self::AD_UNIT_ID])
            ->assertOk()
            ->json('session.id');
        $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions/'.$sessionId.'/complete', [
                'ad_unit_id' => self::AD_UNIT_ID,
                'is_ended' => true,
            ])
            ->assertOk();

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $event, array $context) use ($attemptId): bool {
                return $event === 'REWARDED_AD_CLIENT_COMPLETION_AUDIT'
                    && ! array_key_exists('attempt_id', $context)
                    && ! array_key_exists('anon_id', $context)
                    && ! array_key_exists('ad_unit_id', $context)
                    && ! in_array($attemptId, $context, true)
                    && ! in_array(self::AD_UNIT_ID, $context, true)
                    && str_starts_with((string) ($context['attempt_fingerprint'] ?? ''), 'sha256:')
                    && str_starts_with((string) ($context['ad_unit_fingerprint'] ?? ''), 'sha256:');
            })
            ->twice();
    }

    private function createAttempt(string $anonId, string $scaleCode = 'MBTI'): string
    {
        $attempt = Attempt::query()->create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => $scaleCode === 'BIG5_OCEAN' ? 120 : 60,
            'answers_summary_json' => ['stage' => 'seed'],
            'client_platform' => 'test',
            'channel' => 'wechat_miniapp',
            'started_at' => now()->subMinutes(3),
            'submitted_at' => now(),
            'pack_id' => $scaleCode,
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => strtolower($scaleCode).'_spec_v1',
        ]);
        Result::query()->create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => (string) $attempt->id,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'type_code' => $scaleCode === 'MBTI' ? 'INTJ-A' : 'IQ',
            'scores_json' => [],
            'scores_pct' => [],
            'axis_states' => [],
            'pack_id' => $scaleCode,
            'dir_version' => 'v1',
            'scoring_spec_version' => strtolower($scaleCode).'_spec_v1',
            'report_engine_version' => 'v1',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return (string) $attempt->id;
    }

    private function insertHistoricalRevokedGrant(string $attemptId, string $anonId): void
    {
        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'user_id' => $anonId,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'order_no' => 'historical-refunded-order',
            'status' => 'revoked',
            'benefit_ref' => $anonId,
            'benefit_type' => 'report_unlock',
            'source_order_id' => (string) Str::uuid(),
            'source_event_id' => null,
            'meta_json' => json_encode(['unlock_source' => 'self_purchase']),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
    }

    private function insertHistoricalActiveGrant(string $attemptId, string $anonId): void
    {
        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'user_id' => $anonId,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'order_no' => 'historical-active-order',
            'status' => 'active',
            'benefit_ref' => $anonId,
            'benefit_type' => 'report_unlock',
            'source_order_id' => (string) Str::uuid(),
            'source_event_id' => null,
            'meta_json' => json_encode(['unlock_source' => 'self_purchase']),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
    }

    private function insertRefundedOrder(string $orderNo, string $attemptId, string $anonId): void
    {
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => $anonId,
            'sku' => 'MBTI_REPORT_FULL',
            'item_sku' => 'MBTI_REPORT_FULL',
            'effective_sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 499,
            'amount_total' => 499,
            'amount_refunded' => 499,
            'currency' => 'CNY',
            'status' => 'refunded',
            'payment_state' => 'refunded',
            'provider' => 'billing',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
    }

    private function insertFulfilledGiftRequest(string $orderId, string $attemptId, string $anonId): void
    {
        DB::table('report_gift_requests')->insert([
            'id' => (string) Str::uuid(),
            'public_token_hash' => hash('sha256', (string) Str::uuid()),
            'org_id' => 0,
            'target_attempt_id' => $attemptId,
            'recipient_user_id' => null,
            'recipient_anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'sku' => 'MBTI_REPORT_FULL',
            'status' => 'fulfilled',
            'expires_at' => now()->addDay(),
            'purchased_order_id' => $orderId,
            'purchased_by_user_id' => null,
            'purchased_by_anon_id' => 'anon_rewarded_gift_payer',
            'fulfilled_at' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
    }

    /** @return array<string,string> */
    private function headersFor(string $anonId, ?string $userId = null): array
    {
        $token = 'fm_'.(string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
            'X-Channel' => 'wechat_miniapp',
        ];
    }
}
