<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Attempt;
use App\Models\Result;
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
        $this->assertStringContainsString(
            '"unlock_source":"rewarded_ad"',
            (string) DB::table('benefit_grants')->where('attempt_id', $attemptId)->value('meta_json')
        );

        $this->withHeaders($headers)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/rewarded-ad-sessions/'.$sessionId.'/complete', [
                'ad_unit_id' => self::AD_UNIT_ID,
                'is_ended' => true,
            ])
            ->assertOk()
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('session.status', 'completed');
        $this->assertSame(1, DB::table('benefit_grants')->where('attempt_id', $attemptId)->where('status', 'active')->count());
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
            'question_count' => 60,
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

    /** @return array<string,string> */
    private function headersFor(string $anonId): array
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

        return [
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
            'X-Channel' => 'wechat_miniapp',
        ];
    }
}
