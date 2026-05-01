<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Services\Attempts\AttemptProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuthPhoneAttemptClaimOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_verify_does_not_claim_attempt_from_anon_id_without_resume_token(): void
    {
        $anonId = 'anon_phone_claim_without_resume';
        $attempt = $this->seedAttempt($anonId);

        $verify = $this->verifyPhone([
            'anon_id' => $anonId,
        ]);

        $verify->assertOk();
        $verify->assertJsonPath('user.anon_id', null);
        $this->assertNull(Attempt::withoutGlobalScopes()->whereKey($attempt->id)->value('user_id'));
    }

    public function test_phone_verify_does_not_claim_attempt_with_wrong_resume_token(): void
    {
        $anonId = 'anon_phone_claim_wrong_resume';
        $attempt = $this->seedAttempt($anonId);
        app(AttemptProgressService::class)->createDraftForAttempt($attempt);

        $verify = $this->verifyPhone([
            'anon_id' => $anonId,
            'resume_token' => 'resume_'.(string) Str::uuid(),
        ]);

        $verify->assertOk();
        $verify->assertJsonPath('user.anon_id', null);
        $this->assertNull(Attempt::withoutGlobalScopes()->whereKey($attempt->id)->value('user_id'));
    }

    public function test_phone_verify_claims_only_attempt_matching_resume_token(): void
    {
        $anonId = 'anon_phone_claim_valid_resume';
        $claimable = $this->seedAttempt($anonId);
        $sameAnonOtherAttempt = $this->seedAttempt($anonId);
        $draft = app(AttemptProgressService::class)->createDraftForAttempt($claimable);

        $verify = $this->verifyPhone([
            'anon_id' => $anonId,
            'resume_token' => (string) ($draft['token'] ?? ''),
        ]);

        $verify->assertOk();
        $verify->assertJsonPath('user.anon_id', $anonId);

        $claimedUserId = Attempt::withoutGlobalScopes()->whereKey($claimable->id)->value('user_id');
        $this->assertNotNull($claimedUserId);
        $this->assertNull(Attempt::withoutGlobalScopes()->whereKey($sameAnonOtherAttempt->id)->value('user_id'));
    }

    public function test_phone_verify_claims_otp_bound_anon_attempt_in_ci_mode(): void
    {
        $previousCi = getenv('CI');
        putenv('CI=true');

        $anonId = 'anon_phone_claim_bound_otp';
        $attempt = $this->seedAttempt($anonId);

        try {
            $verify = $this->verifyPhone([
                'anon_id' => $anonId,
            ], [
                'anon_id' => $anonId,
            ]);
        } finally {
            if ($previousCi === false) {
                putenv('CI');
            } else {
                putenv('CI='.$previousCi);
            }
        }

        $verify->assertOk();
        $verify->assertJsonPath('user.anon_id', $anonId);
        $this->assertNotNull(Attempt::withoutGlobalScopes()->whereKey($attempt->id)->value('user_id'));
    }

    public function test_phone_verify_preserves_legacy_ci_claim_without_send_bound_anon(): void
    {
        $previousCi = getenv('CI');
        putenv('CI=true');

        $anonId = 'anon_phone_claim_legacy_ci';
        $attempt = $this->seedAttempt($anonId);

        try {
            $verify = $this->verifyPhone([
                'anon_id' => $anonId,
            ]);
        } finally {
            if ($previousCi === false) {
                putenv('CI');
            } else {
                putenv('CI='.$previousCi);
            }
        }

        $verify->assertOk();
        $verify->assertJsonPath('user.anon_id', $anonId);
        $this->assertNotNull(Attempt::withoutGlobalScopes()->whereKey($attempt->id)->value('user_id'));
    }

    private function verifyPhone(array $overrides, array $sendOverrides = [])
    {
        $phone = '+86137'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

        $send = $this->postJson('/api/v0.3/auth/phone/send_code', array_merge([
            'phone' => $phone,
            'consent' => true,
        ], $sendOverrides));
        $send->assertOk();

        return $this->postJson('/api/v0.3/auth/phone/verify', array_merge([
            'phone' => $phone,
            'code' => (string) $send->json('dev_code', ''),
            'consent' => true,
            'scene' => 'login',
        ], $overrides));
    }

    private function seedAttempt(string $anonId): Attempt
    {
        return Attempt::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => $anonId,
            'user_id' => null,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 93,
            'answers_summary_json' => ['seed' => true],
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now(),
            'submitted_at' => null,
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);
    }
}
