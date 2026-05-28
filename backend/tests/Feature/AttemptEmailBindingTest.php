<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Result;
use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttemptEmailBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_owned_public_attempt_can_bind_email_for_result_recovery(): void
    {
        $anonId = 'anon_email_bind_owner';
        $attemptId = $this->seedAttemptWithResult($anonId, 'MBTI');
        $token = $this->seedFmToken($anonId);
        $email = 'Owner@Example.Test';

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v0.3/attempts/{$attemptId}/email-bind", [
            'email' => $email,
            'locale' => 'zh-CN',
            'surface' => 'result_gate',
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('attempt_id', $attemptId);
        $response->assertJsonPath('status', 'active');
        $response->assertJsonPath('result_url', "/zh/result/{$attemptId}");
        $response->assertJsonPath('result_access_link_email_queued', true);
        $this->assertIsString($response->json('result_access_token_expires_at'));

        $emailHash = app(PiiCipher::class)->emailHash('owner@example.test');
        $this->assertDatabaseHas('attempt_email_bindings', [
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'email_hash' => $emailHash,
            'bound_anon_id' => $anonId,
            'status' => 'active',
            'source' => 'result_gate',
        ]);
        $this->assertDatabaseMissing('attempt_email_bindings', [
            'email_enc' => 'owner@example.test',
        ]);

        $outbox = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', 'result_access_link')
            ->first();
        $this->assertNotNull($outbox);
        $this->assertSame($emailHash, (string) ($outbox->email_hash ?? ''));
        $this->assertSame($emailHash, (string) ($outbox->to_email_hash ?? ''));

        $payloadJson = json_decode((string) ($outbox->payload_json ?? '{}'), true);
        $this->assertIsArray($payloadJson);
        $this->assertSame($attemptId, (string) ($payloadJson['attempt_id'] ?? ''));
        $this->assertSame('result_access_link', (string) ($payloadJson['template_key'] ?? ''));
        $this->assertArrayNotHasKey('result_access_token', $payloadJson);
        $this->assertArrayNotHasKey('result_access_url', $payloadJson);

        $payloadEnc = json_decode((string) app(PiiCipher::class)->decrypt((string) ($outbox->payload_enc ?? '')), true);
        $this->assertIsArray($payloadEnc);
        $this->assertSame('owner@example.test', (string) ($payloadEnc['to_email'] ?? ''));
        $this->assertStringStartsWith("/zh/result/{$attemptId}?access_token=", (string) ($payloadEnc['result_access_url'] ?? ''));
        $this->assertNotEmpty((string) ($payloadEnc['result_access_token'] ?? ''));
    }

    public function test_wrong_anon_cannot_bind_someone_else_attempt(): void
    {
        $attemptId = $this->seedAttemptWithResult('anon_email_bind_owner', 'MBTI');
        $token = $this->seedFmToken('anon_email_bind_intruder');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v0.3/attempts/{$attemptId}/email-bind", [
            'email' => 'intruder@example.test',
            'locale' => 'en',
            'surface' => 'result_gate',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseCount('attempt_email_bindings', 0);
    }

    public function test_owned_public_attempt_can_prebind_email_before_result_exists(): void
    {
        $anonId = 'anon_email_prebind_owner';
        $attemptId = $this->seedAttemptWithoutResult($anonId, 'RIASEC');
        $token = $this->seedFmToken($anonId);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v0.3/attempts/{$attemptId}/email-bind", [
            'email' => 'prebind@example.test',
            'locale' => 'zh-CN',
            'surface' => 'result_gate',
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('attempt_id', $attemptId);
        $response->assertJsonPath('status', 'active');
        $response->assertJsonPath('result_ready', false);
        $response->assertJsonPath('result_url', "/zh/result/{$attemptId}");

        $this->assertDatabaseHas('attempt_email_bindings', [
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'bound_anon_id' => $anonId,
            'status' => 'active',
            'source' => 'result_gate',
        ]);
    }

    public function test_prebound_email_allows_riasec_report_access_after_result_exists(): void
    {
        config()->set('fap.features.email_first_result_access', true);

        $anonId = 'anon_email_prebind_riasec_ready';
        $attemptId = $this->seedAttemptWithoutResult($anonId, 'RIASEC');
        $token = $this->seedFmToken($anonId);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v0.3/attempts/{$attemptId}/email-bind", [
            'email' => 'riasec-ready@example.test',
            'locale' => 'zh-CN',
            'surface' => 'result_gate',
        ])->assertOk();

        $this->seedResultForAttempt($attemptId, 'RIASEC', '');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access");

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('attempt_id', $attemptId);
        $response->assertJsonPath('access_state', 'ready');
        $response->assertJsonPath('report_state', 'ready');
    }

    public function test_sensitive_clinical_attempt_cannot_bind_email(): void
    {
        $anonId = 'anon_email_bind_clinical';
        $attemptId = $this->seedAttemptWithResult($anonId, 'CLINICAL_COMBO_68');
        $token = $this->seedFmToken($anonId);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v0.3/attempts/{$attemptId}/email-bind", [
            'email' => 'clinical@example.test',
            'locale' => 'en',
            'surface' => 'result_gate',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'EMAIL_BIND_UNSUPPORTED_SCALE');
        $this->assertDatabaseCount('attempt_email_bindings', 0);
    }

    public function test_sds_20_attempt_cannot_bind_email(): void
    {
        $anonId = 'anon_email_bind_sds';
        $attemptId = $this->seedAttemptWithResult($anonId, 'SDS_20');
        $token = $this->seedFmToken($anonId);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v0.3/attempts/{$attemptId}/email-bind", [
            'email' => 'sds@example.test',
            'locale' => 'en',
            'surface' => 'result_gate',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'EMAIL_BIND_UNSUPPORTED_SCALE');
        $this->assertDatabaseCount('attempt_email_bindings', 0);
    }

    private function seedAttemptWithResult(string $anonId, string $scaleCode): string
    {
        $attemptId = $this->seedAttemptWithoutResult($anonId, $scaleCode);

        $this->seedResultForAttempt(
            $attemptId,
            $scaleCode,
            in_array($scaleCode, ['CLINICAL_COMBO_68', 'SDS_20'], true) ? '' : 'INTJ-A'
        );

        return $attemptId;
    }

    private function seedAttemptWithoutResult(string $anonId, string $scaleCode): string
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
            'started_at' => now()->subMinutes(2),
            'submitted_at' => now()->subMinute(),
            'pack_id' => $scaleCode,
            'dir_version' => 'v1',
            'content_package_version' => 'content_test',
            'scoring_spec_version' => 'spec_test',
            'calculation_snapshot_json' => ['seed' => true],
        ]);

        return $attemptId;
    }

    private function seedResultForAttempt(string $attemptId, string $scaleCode, string $typeCode = 'INTJ-A'): void
    {
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
    }

    private function seedFmToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'anon_id' => $anonId,
            'user_id' => null,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
