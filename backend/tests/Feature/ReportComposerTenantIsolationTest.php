<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\ReportComposer;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReportComposerTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_compose_returns_attempt_not_found_when_ctx_org_mismatches_attempt_org(): void
    {
        $attempt = $this->seedAttempt(orgId: 200);

        $out = app(ReportComposer::class)->compose($attempt, [
            'org_id' => 100,
        ]);

        $this->assertFalse((bool) ($out['ok'] ?? false));
        $this->assertSame('ATTEMPT_NOT_FOUND', (string) ($out['error'] ?? ''));
        $this->assertSame(404, (int) ($out['status'] ?? 0));
    }

    public function test_compose_returns_attempt_not_found_when_org_context_mismatches_attempt_org(): void
    {
        $attempt = $this->seedAttempt(orgId: 201);

        $orgContext = app(OrgContext::class);
        $orgContext->set(101, null, 'public');
        app()->instance(OrgContext::class, $orgContext);

        $out = app(ReportComposer::class)->compose($attempt, []);

        $this->assertFalse((bool) ($out['ok'] ?? false));
        $this->assertSame('ATTEMPT_NOT_FOUND', (string) ($out['error'] ?? ''));
        $this->assertSame(404, (int) ($out['status'] ?? 0));
    }

    public function test_compose_returns_result_not_found_when_result_exists_only_in_other_org(): void
    {
        $attempt = $this->seedAttempt(orgId: 300);

        $this->seedResult((string) $attempt->id, 301);

        $out = app(ReportComposer::class)->compose($attempt, [
            'org_id' => 300,
        ]);

        $this->assertFalse((bool) ($out['ok'] ?? false));
        $this->assertSame('RESULT_NOT_FOUND', (string) ($out['error'] ?? ''));
        $this->assertSame(404, (int) ($out['status'] ?? 0));
    }

    private function seedAttempt(int $orgId): Attempt
    {
        return Attempt::create([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'anon_id' => 'tenant_iso_anon_' . $orgId,
            'user_id' => '1001',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'answers_summary_json' => ['seed' => true],
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);
    }

    private function seedResult(string $attemptId, int $orgId): void
    {
        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => [
                'EI' => 50,
                'SN' => 50,
                'TF' => 50,
                'JP' => 50,
                'AT' => 50,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'clear',
            ],
            'profile_version' => 'mbti32-v2.5',
            'content_package_version' => 'v0.3',
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);
    }
}
