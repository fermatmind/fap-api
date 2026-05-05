<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\AttemptEmailBinding;
use App\Models\Result;
use App\Services\Results\ResultAccessTokenService;
use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ResultEmailGatedReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_gate_is_default_off_for_existing_public_result_reads(): void
    {
        config()->set('fap.features.email_first_result_access', false);

        $attemptId = $this->seedAttemptWithResult('anon_email_gate_default_off', 'MBTI');
        $token = $this->seedFmToken('anon_email_gate_default_off');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])
            ->getJson("/api/v0.3/attempts/{$attemptId}/result");

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('attempt_id', $attemptId);
    }

    public function test_unbound_public_result_read_requires_email_when_gate_is_enabled(): void
    {
        config()->set('fap.features.email_first_result_access', true);

        $attemptId = $this->seedAttemptWithResult('anon_email_gate_required', 'MBTI');
        $token = $this->seedFmToken('anon_email_gate_required');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])
            ->getJson("/api/v0.3/attempts/{$attemptId}/result");

        $response->assertStatus(428);
        $response->assertJsonPath('error_code', 'EMAIL_BIND_REQUIRED');
        $response->assertJsonPath('details.attempt_id', $attemptId);
        $response->assertJsonPath('details.bind_endpoint', "/api/v0.3/attempts/{$attemptId}/email-bind");
    }

    public function test_unbound_public_report_read_requires_email_when_gate_is_enabled(): void
    {
        config()->set('fap.features.email_first_result_access', true);
        config()->set('fap.features.report_snapshot_strict_v2', false);

        $attemptId = $this->seedAttemptWithResult('anon_email_gate_report_required', 'MBTI');
        $token = $this->seedFmToken('anon_email_gate_report_required');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $response->assertStatus(428);
        $response->assertJsonPath('error_code', 'EMAIL_BIND_REQUIRED');
        $response->assertJsonPath('details.attempt_id', $attemptId);
    }

    public function test_active_email_binding_allows_owner_result_read_when_gate_is_enabled(): void
    {
        config()->set('fap.features.email_first_result_access', true);

        $attemptId = $this->seedAttemptWithResult('anon_email_gate_bound', 'MBTI');
        $this->seedBinding($attemptId, 'owner@example.test', 'anon_email_gate_bound');
        $token = $this->seedFmToken('anon_email_gate_bound');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])
            ->getJson("/api/v0.3/attempts/{$attemptId}/result");

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('attempt_id', $attemptId);
    }

    public function test_result_access_token_grants_read_only_result_access_without_actor(): void
    {
        config()->set('fap.features.email_first_result_access', true);

        $attemptId = $this->seedAttemptWithResult('anon_email_gate_token', 'MBTI');
        $bindingId = $this->seedBinding($attemptId, 'owner@example.test', 'anon_email_gate_token');
        $token = $this->issueResultAccessToken($bindingId, $attemptId);

        $response = $this->getJson("/api/v0.3/attempts/{$attemptId}/result?access_token=".rawurlencode($token));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('attempt_id', $attemptId);
        $response->assertJsonPath('type_code', 'INTJ-A');
    }

    public function test_result_access_token_grants_report_access_read_without_actor(): void
    {
        config()->set('fap.features.email_first_result_access', true);

        $attemptId = $this->seedAttemptWithResult('anon_email_gate_report_access', 'MBTI');
        $bindingId = $this->seedBinding($attemptId, 'owner@example.test', 'anon_email_gate_report_access');
        $token = $this->issueResultAccessToken($bindingId, $attemptId);

        $response = $this->getJson("/api/v0.3/attempts/{$attemptId}/report-access?access_token=".rawurlencode($token));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('attempt_id', $attemptId);
        $response->assertJsonPath('report_state', 'ready');
    }

    public function test_sensitive_sds_result_is_excluded_from_email_gate(): void
    {
        config()->set('fap.features.email_first_result_access', true);

        $attemptId = $this->seedAttemptWithResult('anon_email_gate_sds', 'SDS_20', '');
        $token = $this->seedFmToken('anon_email_gate_sds');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])
            ->getJson("/api/v0.3/attempts/{$attemptId}/result");

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('attempt_id', $attemptId);
        $response->assertJsonPath('meta.scale_code_legacy', 'SDS_20');
    }

    public function test_result_access_token_does_not_grant_sensitive_report_access_without_actor(): void
    {
        config()->set('fap.features.email_first_result_access', true);

        $attemptId = $this->seedAttemptWithResult('anon_email_gate_sds_token', 'SDS_20', '');
        $bindingId = $this->seedBinding($attemptId, 'owner@example.test', 'anon_email_gate_sds_token');
        $token = $this->issueResultAccessToken($bindingId, $attemptId);

        $response = $this->getJson("/api/v0.3/attempts/{$attemptId}/report-access?access_token=".rawurlencode($token));

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
    }

    private function seedAttemptWithResult(string $anonId, string $scaleCode, string $typeCode = 'INTJ-A'): string
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
            'submitted_at' => now()->subMinute(),
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

    private function seedBinding(string $attemptId, string $email, string $anonId): string
    {
        $bindingId = (string) Str::uuid();
        $cipher = app(PiiCipher::class);
        $normalizedEmail = $cipher->normalizeEmail($email);

        DB::table('attempt_email_bindings')->insert([
            'id' => $bindingId,
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'pii_email_key_version' => (string) $cipher->currentKeyVersion(),
            'email_hash' => $cipher->emailHash($normalizedEmail),
            'email_enc' => $cipher->encrypt($normalizedEmail),
            'bound_anon_id' => $anonId,
            'bound_user_id' => null,
            'status' => AttemptEmailBinding::STATUS_ACTIVE,
            'source' => 'result_gate',
            'first_bound_at' => now()->subMinute(),
            'last_accessed_at' => null,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        return $bindingId;
    }

    private function issueResultAccessToken(string $bindingId, string $attemptId): string
    {
        $binding = new AttemptEmailBinding([
            'id' => $bindingId,
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'status' => AttemptEmailBinding::STATUS_ACTIVE,
        ]);
        $binding->exists = true;

        return (string) app(ResultAccessTokenService::class)->issueForBinding($binding)['token'];
    }

    private function seedFmToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'anon_id' => $anonId,
            'user_id' => null,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
