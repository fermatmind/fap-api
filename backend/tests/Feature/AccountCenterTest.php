<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Result;
use App\Models\User;
use App\Services\Attempts\AttemptProgressService;
use App\Services\Auth\FmTokenService;
use App\Services\Auth\GoogleLoginVerifier;
use App\Services\Commerce\EntitlementManager;
use App\Services\Commerce\MembershipService;
use App\Support\PiiCipher;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccountCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fap.rate_limits.bypass_in_test_env' => true, 'services.google_login.client_id' => 'account-test.apps.googleusercontent.com']);
        Mail::mailer('array')->getSymfonyTransport()->flush();
    }

    public function test_google_first_and_repeat_login_use_same_identity_and_one_time_challenge(): void
    {
        $this->googleVerifier();
        $challenge = $this->postJson('/api/v0.3/auth/google/challenge')->assertOk()->json('challenge_id');
        $first = $this->postJson('/api/v0.3/auth/google', ['challenge_id' => $challenge, 'credential' => 'signed-test']);
        $first->assertOk()->assertJsonPath('status', 'authenticated')->assertJsonPath('account.google_connected', true);
        $this->postJson('/api/v0.3/auth/google', ['challenge_id' => $challenge, 'credential' => 'signed-test'])->assertStatus(422);
        $repeat = $this->login();
        $this->assertSame($first->json('account.id'), $repeat->json('account.id'));
        $this->assertDatabaseCount('identities', 1);
        $this->assertDatabaseCount('users', 1);
        $this->withToken($first->json('fm_token'))->getJson('/api/v0.3/me/account')->assertOk()->assertJsonPath('account.id', $first->json('account.id'));
    }

    public function test_google_email_collision_requires_email_confirmation_without_silent_merge(): void
    {
        $owner = User::factory()->create(['email' => 'Account@Example.Test']);
        DB::table('users')->where('id', $owner->id)->update(['email' => 'Account@Example.Test', 'email_hash' => null]);
        $this->googleVerifier();
        $login = $this->login()->assertOk()->assertJsonPath('status', 'verification_required');
        $this->assertDatabaseCount('identities', 0);
        $this->postJson('/api/v0.3/auth/google/link-email', ['verification_ticket' => $login->json('verification_ticket'), 'locale' => 'en'])->assertOk();
        $proof = $this->emailProof();
        $this->postJson('/api/v0.3/auth/account/confirm-email', ['token' => $proof])->assertOk()->assertJsonPath('account.id', (string) $owner->id);
        $this->postJson('/api/v0.3/auth/account/confirm-email', ['token' => $proof])->assertStatus(422);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_conflicting_google_identity_and_tenant_session_are_rejected(): void
    {
        $this->googleVerifier();
        $first = $this->login()->assertOk();
        $other = User::factory()->create();
        $this->withToken($this->token($other))->login()->assertStatus(409);
        $tenantToken = app(FmTokenService::class)->issueForUser((string) $other->id, ['org_id' => 12])['token'];
        $this->withToken($tenantToken)->login()->assertStatus(403);
        $this->assertDatabaseHas('identities', ['user_id' => $first->json('account.id'), 'provider_uid' => 'google-sub-1']);
    }

    public function test_profile_update_and_logout_revoke_only_current_session(): void
    {
        $user = User::factory()->create();
        $a = $this->token($user);
        $b = $this->token($user);
        $this->withToken($a)->patchJson('/api/v0.3/me/account', ['name' => 'Account owner', 'locale' => 'en'])->assertOk()->assertJsonPath('account.name', 'Account owner');
        $this->withToken($a)->postJson('/api/v0.3/me/account/logout')->assertOk();
        $this->withToken($a)->getJson('/api/v0.3/me/account')->assertUnauthorized();
        $this->withToken($b)->getJson('/api/v0.3/me/account')->assertOk();
    }

    public function test_anonymous_id_and_arbitrary_attempt_ids_do_not_claim_assets(): void
    {
        $user = User::factory()->create();
        [$attempt] = $this->attempt();
        $this->withToken($this->token($user))->postJson('/api/v0.3/me/attempts/link-anon', ['anon_id' => 'account-anon', 'attempt_ids' => [$attempt->id]])->assertUnprocessable();
        $this->assertDatabaseHas('attempts', ['id' => $attempt->id, 'user_id' => null]);
    }

    public function test_valid_result_proof_is_idempotent_and_preserves_grants_not_billing_ownership(): void
    {
        $user = User::factory()->create();
        [$attempt, $proof] = $this->attempt();
        $grant = $this->grant($attempt);
        $this->withToken($this->token($user));
        foreach ([1, 2] as $ignored) {
            $this->postJson('/api/v0.3/me/attempts/link-anon', ['proofs' => [$proof]])->assertOk()->assertJsonPath('linked_attempt_ids.0', $attempt->id);
        }
        $this->assertDatabaseHas('attempts', ['id' => $attempt->id, 'user_id' => (string) $user->id]);
        $this->assertDatabaseHas('benefit_grants', ['id' => $grant, 'user_id' => (string) $user->id, 'benefit_ref' => 'account-anon', 'status' => 'active']);
        $this->assertDatabaseCount('benefit_grants', 1);
        $this->assertTrue(app(EntitlementManager::class)->hasFullAccess(0, (string) $user->id, null, $attempt->id, 'MBTI_REPORT_FULL'));
    }

    public function test_forged_or_expired_result_proof_cannot_claim_assets(): void
    {
        $user = User::factory()->create();
        [$attempt, $proof] = $this->attempt();
        $this->withToken($this->token($user))->postJson('/api/v0.3/me/attempts/link-anon', ['proofs' => [['attempt_id' => $attempt->id, 'resume_token' => 'forged']]])->assertForbidden();
        $this->travel(60)->days();
        $this->withToken($this->token($user))->postJson('/api/v0.3/me/attempts/link-anon', ['proofs' => [$proof]])->assertForbidden();
        $this->assertDatabaseHas('attempts', ['id' => $attempt->id, 'user_id' => null]);
    }

    public function test_foreign_owner_and_tenant_proof_fail_and_whole_batch_rolls_back(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$a, $pa] = $this->attempt();
        [$b, $pb] = $this->attempt();
        DB::table('attempts')->where('id', $b->id)->update(['user_id' => (string) $other->id]);
        $this->withToken($this->token($user))->postJson('/api/v0.3/me/attempts/link-anon', ['proofs' => [$pa, $pb]])->assertConflict();
        $this->assertDatabaseHas('attempts', ['id' => $a->id, 'user_id' => null]);
        DB::table('attempts')->where('id', $b->id)->update(['org_id' => 19]);
        $this->postJson('/api/v0.3/me/attempts/link-anon', ['proofs' => [$pb]])->assertNotFound();
    }

    public function test_verified_email_claim_is_bound_to_requesting_user_expires_and_cannot_replay(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$attempt] = $this->attempt();
        $this->binding($attempt, 'old@example.test');
        $this->withToken($this->token($user))->postJson('/api/v0.3/me/account/recover-email', ['email' => 'old@example.test'])->assertOk();
        $proof = $this->emailProof();
        $this->withToken($this->token($other))->postJson('/api/v0.3/auth/account/confirm-email', ['token' => $proof])->assertForbidden();
        $this->withToken($this->token($user))->postJson('/api/v0.3/auth/account/confirm-email', ['token' => str_repeat('x', 64)])->assertUnprocessable();
        $this->postJson('/api/v0.3/auth/account/confirm-email', ['token' => $proof])->assertOk();
        $this->postJson('/api/v0.3/auth/account/confirm-email', ['token' => $proof])->assertUnprocessable();
        $this->assertDatabaseHas('attempts', ['id' => $attempt->id, 'user_id' => (string) $user->id]);
        $this->postJson('/api/v0.3/me/account/recover-email', ['email' => 'old@example.test'])->assertOk();
        $expired = $this->emailProof();
        $this->travel(16)->minutes();
        $this->postJson('/api/v0.3/auth/account/confirm-email', ['token' => $expired])->assertUnprocessable();
    }

    public function test_purchase_email_keeps_gift_recipient_and_refund_expiry_states(): void
    {
        $buyer = User::factory()->create();
        $recipient = User::factory()->create();
        [$attempt] = $this->attempt();
        DB::table('attempts')->where('id', $attempt->id)->update(['user_id' => (string) $recipient->id]);
        $gift = $this->grant($attempt, 'active', (string) $recipient->id);
        $refundAttempt = $this->attempt()[0];
        $refund = $this->grant($refundAttempt, 'revoked');
        $expiredAttempt = $this->attempt()[0];
        $expired = $this->grant($expiredAttempt, 'active', null, now()->subDay()->toDateTimeString());
        $this->binding($refundAttempt, 'buyer@example.test');
        $this->binding($expiredAttempt, 'buyer@example.test');
        foreach (['paid', 'refunded', 'pending'] as $state) {
            $this->order('buyer@example.test', $state);
        }
        $this->withToken($this->token($buyer))->postJson('/api/v0.3/me/account/recover-email', ['email' => 'buyer@example.test'])->assertOk();
        $this->postJson('/api/v0.3/auth/account/confirm-email', ['token' => $this->emailProof()])->assertOk()->assertJsonPath('linked_orders', 3);
        $this->assertDatabaseHas('benefit_grants', ['id' => $gift, 'user_id' => (string) $recipient->id]);
        $this->assertDatabaseHas('attempts', ['id' => $attempt->id, 'user_id' => (string) $recipient->id]);
        $this->assertDatabaseHas('benefit_grants', ['id' => $refund, 'status' => 'revoked']);
        $this->assertDatabaseHas('benefit_grants', ['id' => $expired, 'expires_at' => now()->subDay()->toDateTimeString()]);
        $this->assertFalse(app(EntitlementManager::class)->hasFullAccess(0, (string) $buyer->id, null, $refundAttempt->id, 'MBTI_REPORT_FULL'));
        $this->assertFalse(app(EntitlementManager::class)->hasFullAccess(0, (string) $buyer->id, null, $expiredAttempt->id, 'MBTI_REPORT_FULL'));
        $this->withToken($this->token($buyer))->getJson('/api/v0.3/me/account/orders')->assertOk()->assertJsonCount(3, 'items');
        $this->withToken($this->token($recipient))->getJson('/api/v0.3/me/account/orders')->assertOk()->assertJsonCount(0, 'items');
        $this->assertDatabaseCount('benefit_grants', 3);
    }

    public function test_google_signature_audience_issuer_expiration_and_nonce_are_verified(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $public = openssl_pkey_get_details($key)['key'];
        Http::fake(['https://www.googleapis.com/oauth2/v1/certs' => Http::response(['test-key' => $public])]);
        $claims = ['iss' => 'https://accounts.google.com', 'aud' => 'account-test.apps.googleusercontent.com', 'sub' => 'real-signature-test', 'exp' => time() + 300, 'iat' => time() - 5, 'nonce' => 'challenge-nonce', 'email_verified' => true, 'email' => 'account@example.test'];
        $verify = app(GoogleLoginVerifier::class);
        $this->assertSame('real-signature-test', $verify->verify(JWT::encode($claims, $private, 'RS256', 'test-key'), 'challenge-nonce')['sub']);
        foreach ([['aud' => 'another-client'], ['iss' => 'https://attacker.invalid'], ['exp' => time() - 1], ['nonce' => 'another-challenge'], ['email_verified' => false]] as $patch) {
            try {
                $verify->verify(JWT::encode([...$claims, ...$patch], $private, 'RS256', 'test-key'), 'challenge-nonce');
                $this->fail('Invalid claims accepted.');
            } catch (\Illuminate\Validation\ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($otherKey, $forged);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $verify->verify(JWT::encode($claims, $forged, 'RS256', 'test-key'), 'challenge-nonce');
    }

    public function test_paid_anonymous_report_still_reads_and_downloads_after_proof_claim(): void
    {
        (new \Database\Seeders\ScaleRegistrySeeder)->run();
        config(['fap.features.report_snapshot_strict_v2' => false, 'gotenberg.enabled' => false]);
        Storage::fake('local');
        [$attempt, $proof] = $this->attempt();
        $attempt->update(['locale' => 'zh-CN', 'region' => 'CN_MAINLAND', 'question_count' => 144, 'pack_id' => config('content_packs.default_pack_id'), 'dir_version' => 'MBTI-CN-v0.3']);
        $axes = array_fill_keys(['EI', 'SN', 'TF', 'JP', 'AT'], 50);
        $states = array_fill_keys(array_keys($axes), 'clear');
        $scores = array_fill_keys(array_keys($axes), ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20]);
        Result::create(['id' => (string) Str::uuid(), 'org_id' => 0, 'attempt_id' => $attempt->id, 'scale_code' => 'MBTI', 'scale_version' => 'v0.3', 'type_code' => 'INTJ-A', 'scores_json' => $scores, 'scores_pct' => $axes, 'axis_states' => $states, 'profile_version' => 'test', 'is_valid' => true, 'computed_at' => now(), 'pack_id' => config('content_packs.default_pack_id'), 'dir_version' => 'MBTI-CN-v0.3', 'result_json' => ['type_code' => 'INTJ-A', 'axis_scores_json' => ['scores_pct' => $axes, 'axis_states' => $states]]]);
        app(EntitlementManager::class)->grantAttemptUnlock(0, null, $attempt->anon_id, 'MBTI_REPORT_FULL', $attempt->id, null);
        $anonToken = app(FmTokenService::class)->issueForUser($attempt->anon_id, ['anon_id' => $attempt->anon_id, 'org_id' => 0])['token'];
        $this->withToken($anonToken)->getJson('/api/v0.3/attempts/'.$attempt->id.'/report')->assertOk()->assertJsonPath('locked', false);
        $user = User::factory()->create();
        $this->withToken($this->token($user))->postJson('/api/v0.3/me/attempts/link-anon', ['proofs' => [$proof]])->assertOk();
        $this->getJson('/api/v0.3/attempts/'.$attempt->id.'/report')->assertOk()->assertJsonPath('locked', false);
        $pdf = $this->get('/api/v0.3/attempts/'.$attempt->id.'/report.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $other = User::factory()->create();
        $this->withToken($this->token($other))->getJson('/api/v0.3/attempts/'.$attempt->id.'/report')->assertNotFound();
        $this->getJson('/api/v0.3/attempts/'.$attempt->id.'/report.pdf')->assertNotFound();
    }

    public function test_six_assessments_include_eq_iq_access_actions_and_form_identity(): void
    {
        $user = User::factory()->create();
        $codes = ['MBTI', 'BIG5_OCEAN', 'ENNEAGRAM', 'RIASEC', 'EQ_60', 'IQ_RAVEN'];
        foreach ($codes as $code) {
            [$attempt] = $this->attempt();
            DB::table('attempts')->where('id', $attempt->id)->update(['user_id' => (string) $user->id, 'scale_code' => $code]);
            Result::create(['id' => (string) Str::uuid(), 'org_id' => 0, 'attempt_id' => $attempt->id, 'scale_code' => $code, 'scale_version' => 'v0.3', 'type_code' => '', 'scores_json' => [], 'profile_version' => 'test', 'is_valid' => true, 'computed_at' => now(), 'result_json' => []]);
        }
        $response = $this->withToken($this->token($user))->getJson('/api/v0.3/me/attempts?page_size=50')->assertOk()->assertJsonCount(6, 'items');
        foreach ($response->json('items') as $item) {
            $this->assertTrue($item['has_result']);
            $this->assertSame(4, $item['question_count']);
            $this->assertSame('/result/'.$item['attempt_id'], $item['access_summary']['actions']['page_href']);
            $this->assertArrayHasKey('pdf_href', $item['access_summary']['actions']);
        }
    }

    public function test_membership_order_recovery_preserves_award_id_expiry_and_refund_statistics(): void
    {
        $buyer = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->order('member@example.test', 'paid');
        }
        DB::table('orders')->update(['provider' => 'wechat_mini_virtual', 'sku' => 'MBTI_REPORT_FULL_199', 'amount_cents' => 199]);
        $membership = app(MembershipService::class);
        $old = $membership->status(0, null, 'account-anon');
        $this->assertTrue($old['membership']['active']);
        $grant = DB::table('benefit_grants')->where('benefit_type', 'membership')->first();
        $this->withToken($this->token($buyer))->postJson('/api/v0.3/me/account/recover-email', ['email' => 'member@example.test'])->assertOk();
        $this->postJson('/api/v0.3/auth/account/confirm-email', ['token' => $this->emailProof()])->assertOk();
        $new = $membership->status(0, (string) $buyer->id, null);
        $this->assertSame(5, $new['credit']['paid_report_count']);
        $this->assertSame($old['membership']['expires_at'], $new['membership']['expires_at']);
        $this->assertDatabaseCount('benefit_grants', 1);
        $this->assertDatabaseHas('benefit_grants', ['id' => $grant->id, 'user_id' => (string) $buyer->id]);
        DB::table('benefit_grants')->where('id', $grant->id)->update(['expires_at' => now()->subDay()]);
        $this->assertFalse($membership->status(0, (string) $buyer->id, null)['membership']['active']);
        $this->assertDatabaseCount('benefit_grants', 1);
        DB::table('orders')->limit(1)->update(['payment_state' => 'refunded', 'grant_state' => 'revoked', 'amount_refunded' => 199, 'refunded_at' => now()]);
        $this->assertSame(4, $membership->status(0, (string) $buyer->id, null)['credit']['paid_report_count']);
    }

    public function test_email_collision_rolls_back_result_and_order_changes(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$attempt] = $this->attempt();
        $this->binding($attempt, 'collision@example.test');
        $this->order('collision@example.test', 'paid');
        DB::table('orders')->update(['user_id' => (string) $other->id]);
        $this->withToken($this->token($user))->postJson('/api/v0.3/me/account/recover-email', ['email' => 'collision@example.test'])->assertOk();
        $this->postJson('/api/v0.3/auth/account/confirm-email', ['token' => $this->emailProof()])->assertConflict();
        $this->assertDatabaseHas('attempts', ['id' => $attempt->id, 'user_id' => null]);
        $this->assertDatabaseHas('orders', ['user_id' => (string) $other->id]);
    }

    public function test_login_challenge_expiration_and_google_unavailability_fail_closed(): void
    {
        $id = $this->postJson('/api/v0.3/auth/google/challenge')->assertOk()->json('challenge_id');
        $this->travel(6)->minutes();
        $this->postJson('/api/v0.3/auth/google', ['challenge_id' => $id, 'credential' => 'expired-challenge'])->assertUnprocessable();
        Http::fake(['https://www.googleapis.com/oauth2/v1/certs' => Http::response([], 503)]);
        $id = $this->postJson('/api/v0.3/auth/google/challenge')->assertOk()->json('challenge_id');
        $this->postJson('/api/v0.3/auth/google', ['challenge_id' => $id, 'credential' => 'credential'])->assertStatus(503);
        $this->assertDatabaseCount('identities', 0);
        config(['services.google_login.client_id' => '']);
        $this->postJson('/api/v0.3/auth/google/challenge')->assertOk()->assertJsonPath('configured', false);
    }

    private function googleVerifier(): void
    {
        $this->mock(GoogleLoginVerifier::class)->shouldReceive('verify')->andReturn(['sub' => 'google-sub-1', 'email' => 'account@example.test', 'name' => 'Account Test']);
    }

    private function login(): \Illuminate\Testing\TestResponse
    {
        $id = $this->postJson('/api/v0.3/auth/google/challenge')->assertOk()->json('challenge_id');

        return $this->postJson('/api/v0.3/auth/google', ['challenge_id' => $id, 'credential' => 'signed-test']);
    }

    private function token(User $user): string
    {
        return app(FmTokenService::class)->issueForUser((string) $user->id, ['org_id' => 0])['token'];
    }

    private function attempt(): array
    {
        $attempt = Attempt::create(['id' => (string) Str::uuid(), 'org_id' => 0, 'anon_id' => 'account-anon', 'scale_code' => 'MBTI', 'scale_version' => 'v0.3', 'region' => 'US', 'locale' => 'en', 'question_count' => 4, 'answers_summary_json' => [], 'client_platform' => 'test', 'started_at' => now(), 'submitted_at' => now(), 'pack_id' => 'MBTI', 'dir_version' => 'v1', 'content_package_version' => 'test', 'scoring_spec_version' => 'test']);
        $resume = app(AttemptProgressService::class)->createDraftForAttempt($attempt);

        return [$attempt, ['attempt_id' => $attempt->id, 'resume_token' => $resume['token']]];
    }

    private function binding(Attempt $attempt, string $email): void
    {
        $pii = app(PiiCipher::class);
        DB::table('attempt_email_bindings')->insert(['id' => (string) Str::uuid(), 'org_id' => 0, 'attempt_id' => $attempt->id, 'pii_email_key_version' => $pii->currentKeyVersion(), 'email_hash' => $pii->emailHash($email), 'email_enc' => $pii->encrypt($email), 'bound_anon_id' => $attempt->anon_id, 'status' => 'active', 'source' => 'result_gate', 'first_bound_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function grant(Attempt $attempt, string $status = 'active', ?string $user = null, ?string $expires = null): string
    {
        $id = (string) Str::uuid();
        DB::table('benefit_grants')->insert(['id' => $id, 'org_id' => 0, 'user_id' => $user ?? $attempt->anon_id, 'benefit_code' => 'MBTI_REPORT_FULL', 'benefit_type' => 'report', 'benefit_ref' => $attempt->anon_id, 'source_order_id' => (string) Str::uuid(), 'scope' => 'attempt', 'attempt_id' => $attempt->id, 'status' => $status, 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function order(string $email, string $state): void
    {
        $id = (string) Str::uuid();
        DB::table('orders')->insert(['id' => $id, 'org_id' => 0, 'order_no' => 'ACCOUNT-'.$id, 'anon_id' => 'account-anon', 'status' => $state, 'payment_state' => $state, 'grant_state' => $state === 'paid' ? 'granted' : 'pending', 'currency' => 'USD', 'amount_total' => 100, 'amount_cents' => 100, 'item_sku' => 'TEST', 'contact_email_hash' => app(PiiCipher::class)->emailHash($email), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function emailProof(): string
    {
        $body = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getTextBody();
        preg_match('/#verify=([A-Za-z0-9]{64})/', $body, $match);
        $this->assertNotEmpty($match[1] ?? null);

        return $match[1];
    }
}
