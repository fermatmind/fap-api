<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\AttemptEmailBinding;
use App\Models\Result;
use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ResultEmailLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_by_email_lists_only_results_bound_to_current_anonymous_actor(): void
    {
        $email = 'Owner@Example.Test';
        $firstAttemptId = $this->seedAttemptWithResult('anon_lookup_a', 'MBTI', 'INTJ-A', now()->subMinutes(5));
        $secondAttemptId = $this->seedAttemptWithResult('anon_lookup_b', 'BIG5_OCEAN', 'OCEAN-HIGH', now()->subMinute());
        $this->seedBinding($firstAttemptId, $email, 'anon_lookup_a');
        $this->seedBinding($secondAttemptId, $email, 'anon_lookup_b');

        $response = $this->withHeader('X-Anon-Id', 'anon_lookup_a')
            ->postJson('/api/v0.3/results/lookup-by-email', [
                'email' => ' owner@example.test ',
                'locale' => 'zh-CN',
            ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('email_verification_required', false);
        $response->assertJsonCount(1, 'items');
        $response->assertJsonPath('items.0.attempt_id', $firstAttemptId);
        $response->assertJsonStructure([
            'items' => [
                [
                    'result_access_token',
                    'result_access_token_expires_at',
                ],
            ],
        ]);
        $this->assertStringContainsString('/zh/result/'.$firstAttemptId.'?access_token=', (string) $response->json('items.0.result_url'));
        $this->assertStringNotContainsString($secondAttemptId, $response->getContent());
    }

    public function test_lookup_by_email_does_not_list_results_for_other_anonymous_actor(): void
    {
        $email = 'Owner@Example.Test';
        $firstAttemptId = $this->seedAttemptWithResult('anon_lookup_a', 'MBTI', 'INTJ-A', now()->subMinutes(5));
        $secondAttemptId = $this->seedAttemptWithResult('anon_lookup_b', 'BIG5_OCEAN', 'OCEAN-HIGH', now()->subMinute());
        $this->seedBinding($firstAttemptId, $email, 'anon_lookup_a');
        $this->seedBinding($secondAttemptId, $email, 'anon_lookup_b');

        $response = $this->withHeader('X-Anon-Id', 'anon_lookup_other')
            ->postJson('/api/v0.3/results/lookup-by-email', [
                'email' => ' owner@example.test ',
                'locale' => 'zh-CN',
            ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('email_verification_required', false);
        $response->assertJsonCount(0, 'items');
        $response->assertJsonMissingPath('items.0.result_access_token');
        $this->assertStringNotContainsString($firstAttemptId, $response->getContent());
        $this->assertStringNotContainsString($secondAttemptId, $response->getContent());
    }

    public function test_lookup_by_email_returns_empty_items_for_no_matches(): void
    {
        $response = $this->postJson('/api/v0.3/results/lookup-by-email', [
            'email' => 'missing@example.test',
            'locale' => 'en',
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('email_verification_required', true);
        $response->assertJsonCount(0, 'items');
    }

    public function test_lookup_ignores_inactive_and_sensitive_bindings_for_current_actor(): void
    {
        $email = 'owner@example.test';
        $activeAttemptId = $this->seedAttemptWithResult('anon_lookup_active', 'MBTI', 'ENFP-A', now()->subMinutes(3));
        $inactiveAttemptId = $this->seedAttemptWithResult('anon_lookup_inactive', 'MBTI', 'ISTJ-A', now()->subMinutes(2));
        $sensitiveAttemptId = $this->seedAttemptWithResult('anon_lookup_sds', 'SDS_20', '', now()->subMinute());
        $this->seedBinding($activeAttemptId, $email, 'anon_lookup_active');
        $this->seedBinding($inactiveAttemptId, $email, 'anon_lookup_inactive', 'pending');
        $this->seedBinding($sensitiveAttemptId, $email, 'anon_lookup_sds');

        $response = $this->withHeader('X-Anon-Id', 'anon_lookup_active')
            ->postJson('/api/v0.3/results/lookup-by-email', [
                'email' => $email,
                'locale' => 'en',
            ]);

        $response->assertOk();
        $response->assertJsonPath('email_verification_required', false);
        $response->assertJsonCount(1, 'items');
        $response->assertJsonPath('items.0.attempt_id', $activeAttemptId);
        $this->assertStringNotContainsString($inactiveAttemptId, $response->getContent());
        $this->assertStringNotContainsString($sensitiveAttemptId, $response->getContent());
    }

    public function test_lookup_by_email_is_rate_limited(): void
    {
        Cache::flush();
        config([
            'fap.rate_limits.bypass_in_test_env' => false,
            'fap.rate_limits.api_public_per_minute' => 120,
            'fap.rate_limits.api_result_lookup_per_minute' => 1,
        ]);

        $email = 'ratelimit_'.Str::lower(Str::random(12)).'@example.test';
        $server = ['REMOTE_ADDR' => '198.51.100.77'];
        foreach (['127.0.0.1', '198.51.100.77'] as $ip) {
            RateLimiter::clear('ip:'.$ip);
            RateLimiter::clear('api_result_lookup|ip:'.$ip.'|org:0|route:api/v0.3/results/lookup-by-email');
            RateLimiter::clear('api_result_lookup|ip:'.$ip.'|org:0|route:v0.3/results/lookup-by-email');
        }

        $this->withServerVariables($server)->postJson('/api/v0.3/results/lookup-by-email', [
            'email' => $email,
        ])->assertOk();

        $this->withServerVariables($server)->postJson('/api/v0.3/results/lookup-by-email', [
            'email' => $email,
        ])
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'RATE_LIMIT_RESULT_LOOKUP');
    }

    private function seedAttemptWithResult(string $anonId, string $scaleCode, string $typeCode, mixed $submittedAt): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => 'US',
            'locale' => 'en',
            'question_count' => 4,
            'answers_summary_json' => ['seed' => true],
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now()->subMinutes(10),
            'submitted_at' => $submittedAt,
            'pack_id' => $scaleCode,
            'dir_version' => 'v1',
            'content_package_version' => 'content_test',
            'scoring_spec_version' => 'spec_test',
            'calculation_snapshot_json' => ['seed' => true],
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'org_id' => 0,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'type_code' => $typeCode,
            'scores_json' => ['seed' => 1],
            'profile_version' => 'test',
            'is_valid' => true,
            'computed_at' => now(),
            'result_json' => [
                'type_code' => $typeCode,
            ],
        ]);

        return $attemptId;
    }

    private function seedBinding(
        string $attemptId,
        string $email,
        string $anonId,
        string $status = AttemptEmailBinding::STATUS_ACTIVE
    ): void {
        $cipher = app(PiiCipher::class);
        $normalizedEmail = $cipher->normalizeEmail($email);

        DB::table('attempt_email_bindings')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'pii_email_key_version' => (string) $cipher->currentKeyVersion(),
            'email_hash' => $cipher->emailHash($normalizedEmail),
            'email_enc' => $cipher->encrypt($normalizedEmail),
            'bound_anon_id' => $anonId,
            'bound_user_id' => null,
            'status' => $status,
            'source' => 'result_gate',
            'first_bound_at' => now()->subMinute(),
            'last_accessed_at' => null,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
    }
}
