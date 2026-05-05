<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\AttemptEmailBinding;
use App\Models\Result;
use App\Services\Results\ResultAccessTokenService;
use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ResultEmailLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_by_email_returns_lightweight_results_with_access_tokens(): void
    {
        $email = 'Owner@Example.Test';
        $firstAttemptId = $this->seedAttemptWithResult('anon_lookup_a', 'MBTI', 'INTJ-A', now()->subMinutes(5));
        $secondAttemptId = $this->seedAttemptWithResult('anon_lookup_b', 'BIG5_OCEAN', 'OCEAN-HIGH', now()->subMinute());
        $this->seedBinding($firstAttemptId, $email, 'anon_lookup_a');
        $this->seedBinding($secondAttemptId, $email, 'anon_lookup_b');

        $response = $this->postJson('/api/v0.3/results/lookup-by-email', [
            'email' => ' owner@example.test ',
            'locale' => 'zh-CN',
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonCount(2, 'items');
        $response->assertJsonPath('items.0.attempt_id', $secondAttemptId);
        $response->assertJsonPath('items.0.scale_code_legacy', 'BIG5_OCEAN');
        $response->assertJsonPath('items.1.attempt_id', $firstAttemptId);
        $response->assertJsonPath('items.1.scale_code_legacy', 'MBTI');

        $firstItem = $response->json('items.0');
        $this->assertIsArray($firstItem);
        $this->assertArrayHasKey('result_access_token', $firstItem);
        $this->assertArrayHasKey('result_access_token_expires_at', $firstItem);
        $this->assertArrayNotHasKey('result_json', $firstItem);
        $this->assertArrayNotHasKey('scores_json', $firstItem);
        $this->assertStringStartsWith("/zh/result/{$secondAttemptId}?access_token=", (string) ($firstItem['result_url'] ?? ''));

        $grant = app(ResultAccessTokenService::class)->verify((string) $firstItem['result_access_token']);
        $this->assertIsArray($grant);
        $this->assertSame($secondAttemptId, $grant['attempt_id']);
        $this->assertSame(0, $grant['org_id']);
    }

    public function test_lookup_by_email_returns_empty_items_for_no_matches(): void
    {
        $response = $this->postJson('/api/v0.3/results/lookup-by-email', [
            'email' => 'missing@example.test',
            'locale' => 'en',
        ]);

        $response->assertOk();
        $response->assertExactJson([
            'ok' => true,
            'items' => [],
        ]);
    }

    public function test_lookup_ignores_inactive_and_sensitive_bindings(): void
    {
        $email = 'owner@example.test';
        $activeAttemptId = $this->seedAttemptWithResult('anon_lookup_active', 'MBTI', 'ENFP-A', now()->subMinutes(3));
        $inactiveAttemptId = $this->seedAttemptWithResult('anon_lookup_inactive', 'MBTI', 'ISTJ-A', now()->subMinutes(2));
        $sensitiveAttemptId = $this->seedAttemptWithResult('anon_lookup_sds', 'SDS_20', '', now()->subMinute());
        $this->seedBinding($activeAttemptId, $email, 'anon_lookup_active');
        $this->seedBinding($inactiveAttemptId, $email, 'anon_lookup_inactive', 'pending');
        $this->seedBinding($sensitiveAttemptId, $email, 'anon_lookup_sds');

        $response = $this->postJson('/api/v0.3/results/lookup-by-email', [
            'email' => $email,
            'locale' => 'en',
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'items');
        $response->assertJsonPath('items.0.attempt_id', $activeAttemptId);
    }

    public function test_lookup_by_email_is_rate_limited(): void
    {
        Cache::flush();
        config([
            'fap.rate_limits.bypass_in_test_env' => false,
            'fap.rate_limits.api_result_lookup_per_minute' => 1,
        ]);

        $this->postJson('/api/v0.3/results/lookup-by-email', [
            'email' => 'ratelimit@example.test',
        ])->assertOk();

        $this->postJson('/api/v0.3/results/lookup-by-email', [
            'email' => 'ratelimit@example.test',
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
