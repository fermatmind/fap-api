<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MbtiPrivateRelationshipAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_relationship_requires_authenticated_participant_access(): void
    {
        $this->seedScales();

        [$inviterAttemptId, $inviterAnonId, $inviterToken] = $this->createOwnerContext('anon_private_inviter', 'INTJ-A');
        $shareId = $this->createShareViaApi($inviterAttemptId, $inviterAnonId, $inviterToken);

        $invite = $this->postJson("/api/v0.3/shares/{$shareId}/compare-invites", [
            'anon_id' => 'scan_probe',
            'entrypoint' => 'share_page',
            'compare_intent' => true,
            'utm_source' => 'share',
        ]);
        $invite->assertOk();

        $inviteId = (string) $invite->json('invite_id');

        $this->withHeaders([
            'Authorization' => 'Bearer invalid',
        ])->getJson("/api/v0.3/me/relationships/mbti/{$inviteId}")
            ->assertUnauthorized();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$inviterToken,
            'X-Anon-Id' => $inviterAnonId,
        ])->getJson("/api/v0.3/me/relationships/mbti/{$inviteId}")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('private_relationship_v1.relationship_scope', 'private_relationship_protected')
            ->assertJsonPath('private_relationship_v1.access_state', 'awaiting_second_subject')
            ->assertJsonPath('private_relationship_v1.participant_role', 'inviter')
            ->assertJsonPath('dyadic_consent_v1.consent_scope', 'private_relationship_protected')
            ->assertJsonPath('dyadic_consent_v1.consent_state', 'pending')
            ->assertJsonPath('dyadic_consent_v1.revocation_state', 'active')
            ->assertJsonPath('dyadic_consent_v1.expiry_state', 'active')
            ->assertJsonPath('dyadic_consent_v1.private_relationship_access_version', 'private.relationship.access.v1')
            ->assertJsonMissingPath('private_relationship_v1.invitee_summary.invitee_user_id');

        [$inviteeAttemptId, $inviteeAnonId, $inviteeToken] = $this->createOwnerContext('anon_private_invitee', 'ENFP-T');

        DB::table('mbti_compare_invites')
            ->where('id', $inviteId)
            ->update([
                'invitee_attempt_id' => $inviteeAttemptId,
                'invitee_anon_id' => $inviteeAnonId,
                'status' => 'purchased',
                'accepted_at' => now()->subMinutes(5),
                'completed_at' => now()->subMinutes(4),
                'purchased_at' => now()->subMinutes(3),
            ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$inviteeToken,
            'X-Anon-Id' => $inviteeAnonId,
        ])->getJson("/api/v0.3/me/relationships/mbti/{$inviteId}")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'purchased')
            ->assertJsonPath('private_relationship_v1.access_state', 'private_access_ready')
            ->assertJsonPath('private_relationship_journey_v1.journey_contract_version', 'private_relationship_journey.v1')
            ->assertJsonPath('private_relationship_journey_v1.journey_scope', 'private_relationship_revisit')
            ->assertJsonPath('private_relationship_journey_v1.journey_state', 'ready_for_first_step')
            ->assertJsonPath('private_relationship_journey_v1.progress_state', 'not_started')
            ->assertJsonPath('dyadic_pulse_check_v1.pulse_contract_version', 'dyadic_pulse_check.v1')
            ->assertJsonPath('dyadic_pulse_check_v1.pulse_state', 'start_shared_practice')
            ->assertJsonPath('private_relationship_v1.participant_role', 'invitee')
            ->assertJsonPath('dyadic_consent_v1.consent_state', 'purchased')
            ->assertJsonPath('dyadic_consent_v1.access_state', 'private_access_ready')
            ->assertJsonPath('dyadic_consent_v1.revocation_state', 'active')
            ->assertJsonPath('dyadic_consent_v1.expiry_state', 'active')
            ->assertJsonPath('dyadic_graph_v1.graph_scope', 'private_relationship_protected')
            ->assertJsonMissingPath('invitee_attempt_id')
            ->assertJsonMissingPath('invitee_anon_id')
            ->assertJsonMissingPath('invitee_user_id')
            ->assertJsonMissingPath('invitee_order_no');

        [, $outsiderAnonId, $outsiderToken] = $this->createOwnerContext('anon_private_outsider', 'INFJ-A');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$outsiderToken,
            'X-Anon-Id' => $outsiderAnonId,
        ])->getJson("/api/v0.3/me/relationships/mbti/{$inviteId}")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'PRIVATE_RELATIONSHIP_NOT_FOUND');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$outsiderToken,
            'X-Anon-Id' => $outsiderAnonId,
        ])->postJson("/api/v0.3/me/relationships/mbti/{$inviteId}/consent", [
            'action' => 'revoke_access',
        ])->assertNotFound()
            ->assertJsonPath('error_code', 'PRIVATE_RELATIONSHIP_NOT_FOUND');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$outsiderToken,
            'X-Anon-Id' => $outsiderAnonId,
        ])->postJson("/api/v0.3/me/relationships/mbti/{$inviteId}/journey", [
            'action' => 'continue_dyadic_action',
        ])->assertNotFound()
            ->assertJsonPath('error_code', 'PRIVATE_RELATIONSHIP_NOT_FOUND');
    }

    public function test_private_relationship_lifecycle_overlay_restricts_access_and_supports_consent_mutation(): void
    {
        $this->seedScales();

        [$inviterAttemptId, $inviterAnonId, $inviterToken] = $this->createOwnerContext('anon_private_lifecycle_inviter', 'INTJ-A');
        $shareId = $this->createShareViaApi($inviterAttemptId, $inviterAnonId, $inviterToken);

        $invite = $this->postJson("/api/v0.3/shares/{$shareId}/compare-invites", [
            'anon_id' => 'scan_probe',
            'entrypoint' => 'share_page',
            'compare_intent' => true,
            'utm_source' => 'share',
        ]);
        $invite->assertOk();

        $inviteId = (string) $invite->json('invite_id');

        [$inviteeAttemptId, $inviteeAnonId, $inviteeToken] = $this->createOwnerContext('anon_private_lifecycle_invitee', 'ENFP-T');

        DB::table('mbti_compare_invites')
            ->where('id', $inviteId)
            ->update([
                'invitee_attempt_id' => $inviteeAttemptId,
                'invitee_anon_id' => $inviteeAnonId,
                'status' => 'purchased',
                'accepted_at' => now()->subMinutes(5),
                'completed_at' => now()->subMinutes(4),
                'purchased_at' => now()->subMinutes(3),
            ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$inviteeToken,
            'X-Anon-Id' => $inviteeAnonId,
        ])->postJson("/api/v0.3/me/relationships/mbti/{$inviteId}/journey", [
            'action' => 'continue_dyadic_action',
        ])->assertOk()
            ->assertJsonPath('private_relationship_journey_v1.journey_state', 'practice_started')
            ->assertJsonPath('private_relationship_journey_v1.progress_state', 'warming_up')
            ->assertJsonPath('private_relationship_journey_v1.last_dyadic_pulse_signal', 'continue_dyadic_action')
            ->assertJsonPath('dyadic_pulse_check_v1.pulse_state', 'repeat_shared_practice');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$inviterToken,
            'X-Anon-Id' => $inviterAnonId,
        ])->postJson("/api/v0.3/me/relationships/mbti/{$inviteId}/consent", [
            'action' => 'revoke_access',
        ])->assertOk()
            ->assertJsonPath('dyadic_consent_v1.revocation_state', 'revoked_by_subject')
            ->assertJsonPath('dyadic_consent_v1.access_state', 'private_access_revoked')
            ->assertJsonPath('private_relationship_v1.access_state', 'private_access_revoked')
            ->assertJsonPath('private_relationship_journey_v1.journey_state', 'access_revoked')
            ->assertJsonPath('private_relationship_journey_v1.progress_state', 'restricted')
            ->assertJsonCount(0, 'private_relationship_v1.private_sync_sections')
            ->assertJsonMissingPath('private_relationship_v1.private_action_prompt.key')
            ->assertJsonMissingPath('dyadic_pulse_check_v1.pulse_state');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$inviteeToken,
            'X-Anon-Id' => $inviteeAnonId,
        ])->postJson("/api/v0.3/me/relationships/mbti/{$inviteId}/journey", [
            'action' => 'continue_dyadic_action',
        ])->assertOk()
            ->assertJsonPath('private_relationship_journey_v1.journey_state', 'access_revoked')
            ->assertJsonPath('private_relationship_journey_v1.progress_state', 'restricted')
            ->assertJsonMissingPath('dyadic_pulse_check_v1.pulse_state');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$inviteeToken,
            'X-Anon-Id' => $inviteeAnonId,
        ])->getJson("/api/v0.3/me/relationships/mbti/{$inviteId}")
            ->assertOk()
            ->assertJsonPath('dyadic_consent_v1.revocation_state', 'revoked_by_subject')
            ->assertJsonPath('dyadic_consent_v1.access_state', 'private_access_revoked')
            ->assertJsonCount(0, 'private_relationship_v1.private_sync_sections');

        DB::table('mbti_compare_invites')
            ->where('id', $inviteId)
            ->update([
                'meta_json' => json_encode([
                    'dyadic_consent_lifecycle_v1' => [
                        'revocation_state' => 'active',
                        'expiry_state' => 'expired',
                        'expires_at' => now()->subMinute()->toISOString(),
                        'consent_policy_version' => '2025-01-01',
                    ],
                    'private_relationship_journey_v1' => [
                        'journey_state' => 'practice_started',
                        'progress_state' => 'warming_up',
                        'completed_dyadic_action_keys' => ['dyadic_action.name_decision_rule'],
                        'last_dyadic_pulse_signal' => 'continue_dyadic_action',
                        'pulse_feedback_mode' => 'protected_dyadic_ack',
                        'revisit_reorder_reason' => 'activate_first_dyadic_step',
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$inviteeToken,
            'X-Anon-Id' => $inviteeAnonId,
        ])->getJson("/api/v0.3/me/relationships/mbti/{$inviteId}")
            ->assertOk()
            ->assertJsonPath('dyadic_consent_v1.expiry_state', 'expired')
            ->assertJsonPath('dyadic_consent_v1.access_state', 'private_access_expired')
            ->assertJsonPath('dyadic_consent_v1.consent_refresh_required', true)
            ->assertJsonCount(0, 'private_relationship_v1.private_sync_sections')
            ->assertJsonPath('private_relationship_journey_v1.journey_state', 'revisit_after_consent_refresh')
            ->assertJsonPath('private_relationship_journey_v1.progress_state', 'restricted')
            ->assertJsonPath('dyadic_pulse_check_v1.pulse_state', 'refresh_private_access');

        $refresh = $this->withHeaders([
            'Authorization' => 'Bearer '.$inviteeToken,
            'X-Anon-Id' => $inviteeAnonId,
        ])->postJson("/api/v0.3/me/relationships/mbti/{$inviteId}/consent", [
            'action' => 'acknowledge_refresh',
        ]);

        $refresh->assertOk()
            ->assertJsonPath('dyadic_consent_v1.expiry_state', 'active')
            ->assertJsonPath('dyadic_consent_v1.consent_refresh_required', false)
            ->assertJsonPath('dyadic_consent_v1.access_state', 'private_access_ready')
            ->assertJsonPath('private_relationship_v1.access_state', 'private_access_ready');

        $row = DB::table('mbti_compare_invites')->where('id', $inviteId)->first();
        $meta = is_object($row) ? (array) json_decode((string) ($row->meta_json ?? '{}'), true) : [];
        $overlay = is_array($meta['dyadic_consent_lifecycle_v1'] ?? null) ? $meta['dyadic_consent_lifecycle_v1'] : [];
        $journeyOverlay = is_array($meta['private_relationship_journey_v1'] ?? null) ? $meta['private_relationship_journey_v1'] : [];
        $this->assertSame('active', (string) ($overlay['expiry_state'] ?? ''));
        $this->assertNotNull($overlay['acknowledged_at'] ?? null);
        $this->assertSame('practice_started', (string) ($journeyOverlay['journey_state'] ?? ''));
        $this->assertSame('warming_up', (string) ($journeyOverlay['progress_state'] ?? ''));
        $this->assertSame('continue_dyadic_action', (string) ($journeyOverlay['last_dyadic_pulse_signal'] ?? ''));
    }

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
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

    private function createShareViaApi(string $attemptId, string $anonId, string $token): string
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/share");

        $response->assertOk();

        return (string) $response->json('share_id');
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function createOwnerContext(string $anonId, string $typeCode): array
    {
        $attemptId = $this->createMbtiAttemptWithResult($anonId, $typeCode);
        $token = $this->issueAnonToken($anonId);

        return [$attemptId, $anonId, $token];
    }

    private function createMbtiAttemptWithResult(string $anonId, string $typeCode): string
    {
        $attemptId = (string) Str::uuid();
        $resultProfile = match ($typeCode) {
            'ENFP-T' => [
                'type_name' => '竞选者型',
                'summary' => 'Invitee public-safe share summary.',
                'tagline' => '热情的连接者',
                'rarity' => '约 8%',
                'keywords' => ['热情', '灵感', '共情'],
                'scores_pct' => ['EI' => 72, 'SN' => 74, 'TF' => 41, 'JP' => 38, 'AT' => 43],
            ],
            'INFJ-A' => [
                'type_name' => '提倡者型',
                'summary' => 'Outsider public-safe share summary.',
                'tagline' => '安静而坚定',
                'rarity' => '约 2%',
                'keywords' => ['洞察', '共情', '结构'],
                'scores_pct' => ['EI' => 28, 'SN' => 79, 'TF' => 39, 'JP' => 62, 'AT' => 61],
            ],
            default => [
                'type_name' => '建筑师型',
                'summary' => 'Public-safe share summary.',
                'tagline' => '冷静的长期规划者',
                'rarity' => '约 2%',
                'keywords' => ['战略', '独立', '前瞻'],
                'scores_pct' => ['EI' => 35, 'SN' => 72, 'TF' => 68, 'JP' => 63, 'AT' => 58],
            ],
        };

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => $typeCode,
            'scores_json' => ['total' => 100],
            'scores_pct' => $resultProfile['scores_pct'],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'moderate',
                'AT' => 'moderate',
            ],
            'content_package_version' => 'v0.3',
            'result_json' => [
                'type_code' => $typeCode,
                'type_name' => $resultProfile['type_name'],
                'summary' => $resultProfile['summary'],
                'tagline' => $resultProfile['tagline'],
                'rarity' => $resultProfile['rarity'],
                'keywords' => $resultProfile['keywords'],
                'layers' => [
                    'identity' => [
                        'body' => 'PRIVATE_PAID_SECTION_BODY',
                    ],
                ],
            ],
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }
}
