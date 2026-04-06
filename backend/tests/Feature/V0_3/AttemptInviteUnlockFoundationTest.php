<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\AttemptInviteUnlock;
use App\Models\AttemptInviteUnlockCompletion;
use App\Models\Result;
use App\Services\Attempts\AttemptInviteUnlockCompletionService;
use App\Services\Attempts\AttemptInviteUnlockService;
use App\Services\Attempts\AttemptSubmitSideEffects;
use App\Services\Attempts\InviteUnlock\InviteUnlockCompletionStatus;
use App\Services\Attempts\InviteUnlock\InviteUnlockStatus;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttemptInviteUnlockFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_and_get_attempt_invite_unlock_progress(): void
    {
        $inviterAnonId = 'anon_inviter_route';
        $attemptId = $this->createAttemptWithOptionalResult($inviterAnonId, 'MBTI', true, true);
        $headers = $this->authHeaders($inviterAnonId);

        $post = $this->withHeaders($headers)->postJson("/api/v0.3/attempts/{$attemptId}/invite-unlocks");
        $post->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('has_invite', true)
            ->assertJsonPath('created', true)
            ->assertJsonPath('target_attempt_id', $attemptId)
            ->assertJsonPath('status', InviteUnlockStatus::PENDING)
            ->assertJsonPath('required_invitees', 2)
            ->assertJsonPath('completed_invitees', 0)
            ->assertJsonPath('unlock_stage', 'locked')
            ->assertJsonPath('unlock_source', 'none')
            ->assertJsonPath('invite_unlock_diag_v1.status', 'locked')
            ->assertJsonPath('invite_unlock_diag_v1.progress_percent', 0);

        $inviteCode = (string) $post->json('invite_code');
        $this->assertNotSame('', $inviteCode);

        $get = $this->withHeaders($headers)->getJson("/api/v0.3/attempts/{$attemptId}/invite-unlocks");
        $get->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('has_invite', true)
            ->assertJsonPath('created', false)
            ->assertJsonPath('invite_code', $inviteCode)
            ->assertJsonPath('completed_invitees', 0)
            ->assertJsonPath('invite_unlock_diag_v1.status', 'locked');

        $this->assertSame(1, AttemptInviteUnlock::query()->count());
        $this->assertDatabaseHas('events', [
            'event_code' => 'invite_unlock_created',
            'attempt_id' => $attemptId,
        ]);
    }

    public function test_post_invite_unlock_reuses_same_target_attempt_record(): void
    {
        $inviterAnonId = 'anon_inviter_reuse';
        $attemptId = $this->createAttemptWithOptionalResult($inviterAnonId, 'MBTI', true, true);
        $headers = $this->authHeaders($inviterAnonId);

        $first = $this->withHeaders($headers)->postJson("/api/v0.3/attempts/{$attemptId}/invite-unlocks");
        $second = $this->withHeaders($headers)->postJson("/api/v0.3/attempts/{$attemptId}/invite-unlocks");

        $first->assertOk();
        $second->assertOk();
        $this->assertSame((string) $first->json('invite_code'), (string) $second->json('invite_code'));
        $this->assertSame(1, AttemptInviteUnlock::query()->count());
    }

    public function test_completion_service_applies_minimum_anti_abuse_and_stable_012_progress(): void
    {
        $inviteService = app(AttemptInviteUnlockService::class);
        $completionService = app(AttemptInviteUnlockCompletionService::class);

        $inviterAnonId = 'anon_inviter_service';
        $targetAttemptId = $this->createAttemptWithOptionalResult($inviterAnonId, 'MBTI', true, true);
        $targetAttempt = Attempt::query()->where('id', $targetAttemptId)->firstOrFail();

        $invite = $inviteService->createOrReuseInvite($targetAttempt, null, $inviterAnonId);
        $inviteCode = (string) ($invite['invite_code'] ?? '');
        $this->assertNotSame('', $inviteCode);

        $inviteeAttempt1 = $this->createAttemptWithOptionalResult('anon_invitee_one', 'MBTI', true, true);
        $qualifiedOne = $completionService->recordCompletionForInvite($inviteCode, $inviteeAttempt1, null, 'anon_invitee_one');
        $this->assertTrue((bool) ($qualifiedOne['ok'] ?? false));
        $this->assertSame(
            InviteUnlockCompletionStatus::QUALIFIED_COUNTED,
            (string) ($qualifiedOne['qualification_status'] ?? '')
        );
        $this->assertSame(1, (int) data_get($qualifiedOne, 'progress.completed_invitees'));
        $this->assertSame(InviteUnlockStatus::IN_PROGRESS, (string) data_get($qualifiedOne, 'progress.status'));
        $this->assertSame('partial', (string) ($qualifiedOne['unlock_stage'] ?? ''));
        $this->assertSame('partial_unlock', (string) data_get($qualifiedOne, 'progress.invite_unlock_diag_v1.status'));
        $this->assertDatabaseHas('benefit_grants', [
            'org_id' => 0,
            'attempt_id' => $targetAttemptId,
            'benefit_code' => 'MBTI_CAREER',
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('benefit_grants', [
            'org_id' => 0,
            'attempt_id' => $targetAttemptId,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'status' => 'active',
        ]);

        $selfReferralAttempt = $this->createAttemptWithOptionalResult($inviterAnonId, 'MBTI', true, true);
        $selfReferral = $completionService->recordCompletionForInvite($inviteCode, $selfReferralAttempt, null, $inviterAnonId);
        $this->assertSame(
            InviteUnlockCompletionStatus::REJECTED_SELF_REFERRAL,
            (string) ($selfReferral['qualification_status'] ?? '')
        );

        $invalidAttempt = $completionService->recordCompletionForInvite(
            $inviteCode,
            (string) Str::uuid(),
            null,
            'anon_missing_attempt'
        );
        $this->assertSame(
            InviteUnlockCompletionStatus::REJECTED_INVALID_ATTEMPT,
            (string) ($invalidAttempt['qualification_status'] ?? '')
        );

        $missingResultAttempt = $this->createAttemptWithOptionalResult('anon_missing_result', 'MBTI', false, false);
        $missingResult = $completionService->recordCompletionForInvite(
            $inviteCode,
            $missingResultAttempt,
            null,
            'anon_missing_result'
        );
        $this->assertSame(
            InviteUnlockCompletionStatus::REJECTED_NOT_SUBMITTED_OR_RESULT_MISSING,
            (string) ($missingResult['qualification_status'] ?? '')
        );

        $scaleMismatchAttempt = $this->createAttemptWithOptionalResult('anon_big5_mismatch', 'BIG5_OCEAN', true, true);
        $scaleMismatch = $completionService->recordCompletionForInvite(
            $inviteCode,
            $scaleMismatchAttempt,
            null,
            'anon_big5_mismatch'
        );
        $this->assertSame(
            InviteUnlockCompletionStatus::REJECTED_SCALE_MISMATCH,
            (string) ($scaleMismatch['qualification_status'] ?? '')
        );

        $inviteeAttempt2 = $this->createAttemptWithOptionalResult('anon_invitee_two', 'MBTI', true, true);
        $qualifiedTwo = $completionService->recordCompletionForInvite($inviteCode, $inviteeAttempt2, null, 'anon_invitee_two');
        $this->assertSame(
            InviteUnlockCompletionStatus::QUALIFIED_COUNTED,
            (string) ($qualifiedTwo['qualification_status'] ?? '')
        );
        $this->assertSame(2, (int) data_get($qualifiedTwo, 'progress.completed_invitees'));
        $this->assertSame(InviteUnlockStatus::COMPLETED, (string) data_get($qualifiedTwo, 'progress.status'));
        $this->assertSame('full', (string) ($qualifiedTwo['unlock_stage'] ?? ''));
        $this->assertSame('full_unlock', (string) data_get($qualifiedTwo, 'progress.invite_unlock_diag_v1.status'));
        $this->assertDatabaseHas('benefit_grants', [
            'org_id' => 0,
            'attempt_id' => $targetAttemptId,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'status' => 'active',
        ]);

        $lateSelfAttempt = $this->createAttemptWithOptionalResult($inviterAnonId, 'MBTI', true, true);
        $lateSelfReferral = $completionService->recordCompletionForInvite($inviteCode, $lateSelfAttempt, null, $inviterAnonId);
        $this->assertSame(
            InviteUnlockCompletionStatus::REJECTED_SELF_REFERRAL,
            (string) ($lateSelfReferral['qualification_status'] ?? '')
        );

        $duplicateIdentityAttempt = $this->createAttemptWithOptionalResult('anon_invitee_two', 'MBTI', true, true);
        $duplicateIdentity = $completionService->recordCompletionForInvite(
            $inviteCode,
            $duplicateIdentityAttempt,
            null,
            'anon_invitee_two'
        );
        $this->assertSame(
            InviteUnlockCompletionStatus::REJECTED_DUPLICATE_INVITEE,
            (string) ($duplicateIdentity['qualification_status'] ?? '')
        );

        $overflowAttempt = $this->createAttemptWithOptionalResult('anon_invitee_three', 'MBTI', true, true);
        $overflow = $completionService->recordCompletionForInvite($inviteCode, $overflowAttempt, null, 'anon_invitee_three');
        $this->assertSame(
            InviteUnlockCompletionStatus::REJECTED_DUPLICATE_COMPLETION,
            (string) ($overflow['qualification_status'] ?? '')
        );
        $this->assertSame(2, (int) data_get($overflow, 'progress.completed_invitees'));
        $this->assertSame(1, DB::table('benefit_grants')
            ->where('org_id', 0)
            ->where('attempt_id', $targetAttemptId)
            ->where('benefit_code', 'MBTI_CAREER')
            ->where('status', 'active')
            ->count());
        $this->assertSame(1, DB::table('benefit_grants')
            ->where('org_id', 0)
            ->where('attempt_id', $targetAttemptId)
            ->where('benefit_code', 'MBTI_REPORT_FULL')
            ->where('status', 'active')
            ->count());

        $inviteRow = AttemptInviteUnlock::query()->where('invite_code', $inviteCode)->firstOrFail();
        $this->assertSame(2, (int) $inviteRow->completed_invitees);
        $this->assertSame(InviteUnlockStatus::COMPLETED, (string) $inviteRow->status);
        $this->assertSame(
            2,
            AttemptInviteUnlockCompletion::query()
                ->where('invite_id', (string) $inviteRow->id)
                ->where('counted', true)
                ->count()
        );
        $this->assertGreaterThanOrEqual(2, DB::table('events')
            ->where('event_code', 'invite_unlock_completion_qualified')
            ->where('attempt_id', $targetAttemptId)
            ->count());
        $this->assertGreaterThanOrEqual(1, DB::table('events')
            ->where('event_code', 'invite_unlock_completion_rejected')
            ->where('attempt_id', $targetAttemptId)
            ->count());
        $this->assertGreaterThanOrEqual(1, DB::table('events')
            ->where('event_code', 'invite_unlock_partial_granted')
            ->where('attempt_id', $targetAttemptId)
            ->count());
        $this->assertGreaterThanOrEqual(1, DB::table('events')
            ->where('event_code', 'invite_unlock_full_granted')
            ->where('attempt_id', $targetAttemptId)
            ->count());
    }

    public function test_submit_side_effect_records_completion_and_syncs_partial_stage_entitlement(): void
    {
        $inviteService = app(AttemptInviteUnlockService::class);
        $sideEffects = app(AttemptSubmitSideEffects::class);

        $inviterAnonId = 'anon_inviter_side_effect';
        $targetAttemptId = $this->createAttemptWithOptionalResult($inviterAnonId, 'MBTI', true, true);
        $targetAttempt = Attempt::query()->where('id', $targetAttemptId)->firstOrFail();
        $invite = $inviteService->createOrReuseInvite($targetAttempt, null, $inviterAnonId);
        $inviteCode = (string) ($invite['invite_code'] ?? '');

        $inviteeAnonId = 'anon_invitee_side_effect';
        $inviteeAttemptId = $this->createAttemptWithOptionalResult($inviteeAnonId, 'MBTI', true, true);

        $ctx = new OrgContext;
        $ctx->set(0, null, 'public', $inviteeAnonId);

        $sideEffects->runAfterSubmit($ctx, [
            'org_id' => 0,
            'attempt_id' => $inviteeAttemptId,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI',
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'scoring_spec_version' => '2026.01',
            'invite_token' => '',
            'share_id' => '',
            'compare_invite_id' => '',
            'invite_unlock_code' => $inviteCode,
            'credit_benefit_code' => '',
            'entitlement_benefit_code' => '',
        ], null, $inviteeAnonId);

        $completion = AttemptInviteUnlockCompletion::query()
            ->where('invite_code', $inviteCode)
            ->where('invitee_attempt_id', $inviteeAttemptId)
            ->first();

        $this->assertNotNull($completion);
        $this->assertSame(
            InviteUnlockCompletionStatus::QUALIFIED_COUNTED,
            (string) ($completion->qualification_status ?? '')
        );
        $this->assertDatabaseHas('benefit_grants', [
            'org_id' => 0,
            'attempt_id' => $targetAttemptId,
            'benefit_code' => 'MBTI_CAREER',
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('benefit_grants', [
            'org_id' => 0,
            'attempt_id' => $targetAttemptId,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'status' => 'active',
        ]);
    }

    /**
     * @return array<string,string>
     */
    private function authHeaders(string $anonId): array
    {
        return [
            'Authorization' => 'Bearer '.$this->issueAnonToken($anonId),
            'X-Anon-Id' => $anonId,
        ];
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

    private function createAttemptWithOptionalResult(
        string $anonId,
        string $scaleCode,
        bool $submitted,
        bool $withResult,
    ): string {
        $attemptId = (string) Str::uuid();
        $normalizedScaleCode = strtoupper(trim($scaleCode));
        $isBigFive = $normalizedScaleCode === 'BIG5_OCEAN';
        $questionCount = $isBigFive ? 120 : 144;

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => $normalizedScaleCode,
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => $questionCount,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => $submitted ? 'submit' : 'start'],
            'started_at' => now()->subMinute(),
            'submitted_at' => $submitted ? now() : null,
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        if ($withResult) {
            Result::create([
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'attempt_id' => $attemptId,
                'scale_code' => $normalizedScaleCode,
                'scale_version' => 'v0.3',
                'type_code' => $isBigFive ? 'BIG5_PROFILE_A' : 'INTJ-A',
                'scores_json' => ['total' => 100],
                'scores_pct' => ['axis_a' => 50],
                'axis_states' => ['axis_a' => 'clear'],
                'content_package_version' => 'v0.3',
                'result_json' => [
                    'type_code' => $isBigFive ? 'BIG5_PROFILE_A' : 'INTJ-A',
                    'summary' => 'test result summary',
                ],
                'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
                'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
                'scoring_spec_version' => '2026.01',
                'report_engine_version' => 'v1.2',
                'is_valid' => true,
                'computed_at' => now(),
            ]);
        }

        return $attemptId;
    }
}
