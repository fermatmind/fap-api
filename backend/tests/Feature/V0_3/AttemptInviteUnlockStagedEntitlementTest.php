<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Attempts\AttemptInviteUnlockCompletionService;
use App\Services\Attempts\AttemptInviteUnlockService;
use App\Services\Commerce\EntitlementManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttemptInviteUnlockStagedEntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_progress_transitions_report_access_unlock_stage_from_locked_to_partial_to_full(): void
    {
        $inviteService = app(AttemptInviteUnlockService::class);
        $completionService = app(AttemptInviteUnlockCompletionService::class);

        $inviterAnonId = 'anon_stage_inviter';
        $targetAttemptId = $this->createAttemptWithResult($inviterAnonId);
        $targetAttempt = Attempt::query()->where('id', $targetAttemptId)->firstOrFail();
        $invite = $inviteService->createOrReuseInvite($targetAttempt, null, $inviterAnonId);
        $inviteCode = (string) ($invite['invite_code'] ?? '');

        $headers = $this->authHeaders($inviterAnonId);
        $this->withHeaders($headers)
            ->getJson("/api/v0.3/attempts/{$targetAttemptId}/report-access")
            ->assertOk()
            ->assertJsonPath('unlock_stage', 'locked')
            ->assertJsonPath('unlock_source', 'none')
            ->assertJsonPath('invite_unlock_diag_v1.status', 'locked');

        $inviteeAttemptOne = $this->createAttemptWithResult('anon_stage_invitee_one');
        $completionService->recordCompletionForInvite($inviteCode, $inviteeAttemptOne, null, 'anon_stage_invitee_one');

        $this->withHeaders($headers)
            ->getJson("/api/v0.3/attempts/{$targetAttemptId}/report-access")
            ->assertOk()
            ->assertJsonPath('unlock_stage', 'partial')
            ->assertJsonPath('unlock_source', 'invite')
            ->assertJsonPath('payload.access_level', 'partial')
            ->assertJsonPath('payload.variant', 'partial')
            ->assertJsonPath('invite_unlock_diag_v1.status', 'partial_unlock');

        $inviteeAttemptTwo = $this->createAttemptWithResult('anon_stage_invitee_two');
        $completionService->recordCompletionForInvite($inviteCode, $inviteeAttemptTwo, null, 'anon_stage_invitee_two');

        $this->withHeaders($headers)
            ->getJson("/api/v0.3/attempts/{$targetAttemptId}/report-access")
            ->assertOk()
            ->assertJsonPath('unlock_stage', 'full')
            ->assertJsonPath('unlock_source', 'invite')
            ->assertJsonPath('payload.access_level', 'full')
            ->assertJsonPath('payload.variant', 'full')
            ->assertJsonPath('invite_unlock_diag_v1.status', 'full_unlock');

        $this->assertSame(1, DB::table('benefit_grants')
            ->where('attempt_id', $targetAttemptId)
            ->where('benefit_code', 'MBTI_CAREER')
            ->where('status', 'active')
            ->count());
        $this->assertSame(1, DB::table('benefit_grants')
            ->where('attempt_id', $targetAttemptId)
            ->where('benefit_code', 'MBTI_REPORT_FULL')
            ->where('status', 'active')
            ->count());
    }

    public function test_payment_and_invite_stages_coexist_without_downgrading_full_access(): void
    {
        /** @var EntitlementManager $entitlements */
        $entitlements = app(EntitlementManager::class);
        $inviteService = app(AttemptInviteUnlockService::class);
        $completionService = app(AttemptInviteUnlockCompletionService::class);

        // payment full first, then invite completion should stay full and source=payment
        $paymentFirstAnon = 'anon_stage_payment_first';
        $paymentFirstAttemptId = $this->createAttemptWithResult($paymentFirstAnon);
        $entitlements->grantAttemptUnlock(
            0,
            null,
            $paymentFirstAnon,
            'MBTI_REPORT_FULL',
            $paymentFirstAttemptId,
            'ORDER-PAY-FIRST'
        );
        $paymentFirstAttempt = Attempt::query()->where('id', $paymentFirstAttemptId)->firstOrFail();
        $paymentFirstInvite = $inviteService->createOrReuseInvite($paymentFirstAttempt, null, $paymentFirstAnon);
        $completionService->recordCompletionForInvite(
            (string) $paymentFirstInvite['invite_code'],
            $this->createAttemptWithResult('anon_stage_payment_first_invitee'),
            null,
            'anon_stage_payment_first_invitee'
        );

        $this->withHeaders($this->authHeaders($paymentFirstAnon))
            ->getJson("/api/v0.3/attempts/{$paymentFirstAttemptId}/report-access")
            ->assertOk()
            ->assertJsonPath('unlock_stage', 'full')
            ->assertJsonPath('unlock_source', 'payment')
            ->assertJsonPath('invite_unlock_diag_v1.status', 'full_unlock');

        // invite partial first, then payment full should converge to full and source=mixed
        $partialFirstAnon = 'anon_stage_partial_first';
        $partialFirstAttemptId = $this->createAttemptWithResult($partialFirstAnon);
        $partialFirstAttempt = Attempt::query()->where('id', $partialFirstAttemptId)->firstOrFail();
        $partialFirstInvite = $inviteService->createOrReuseInvite($partialFirstAttempt, null, $partialFirstAnon);
        $completionService->recordCompletionForInvite(
            (string) $partialFirstInvite['invite_code'],
            $this->createAttemptWithResult('anon_stage_partial_first_invitee'),
            null,
            'anon_stage_partial_first_invitee'
        );

        $entitlements->grantAttemptUnlock(
            0,
            null,
            $partialFirstAnon,
            'MBTI_REPORT_FULL',
            $partialFirstAttemptId,
            'ORDER-PARTIAL-THEN-PAY'
        );

        $this->withHeaders($this->authHeaders($partialFirstAnon))
            ->getJson("/api/v0.3/attempts/{$partialFirstAttemptId}/report-access")
            ->assertOk()
            ->assertJsonPath('unlock_stage', 'full')
            ->assertJsonPath('unlock_source', 'mixed')
            ->assertJsonPath('invite_unlock_diag_v1.status', 'mixed_unlock');
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

    private function createAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();
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
            'answers_summary_json' => ['stage' => 'submit'],
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
            'type_code' => 'INTJ-A',
            'scores_json' => ['total' => 100],
            'scores_pct' => ['axis_a' => 50],
            'axis_states' => ['axis_a' => 'clear'],
            'content_package_version' => 'v0.3',
            'result_json' => ['type_code' => 'INTJ-A', 'summary' => 'stage test'],
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
