<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attempt;
use App\Services\Report\ReportGatekeeper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReportGatekeeperIdentityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_denies_when_user_and_anon_are_empty_strings(): void
    {
        $attemptId = $this->seedAttempt(orgId: 0);
        $gatekeeper = app(ReportGatekeeper::class);

        $resolved = $gatekeeper->resolve(
            0,
            $attemptId,
            '   ',
            '',
            null
        );

        $this->assertFalse((bool) ($resolved['ok'] ?? false));
        $this->assertSame('ATTEMPT_NOT_FOUND', (string) ($resolved['error'] ?? ''));
    }

    public function test_resolve_allows_system_access_only_when_force_system_access_true(): void
    {
        $attemptId = $this->seedAttempt(orgId: 0);
        $gatekeeper = app(ReportGatekeeper::class);

        $resolved = $gatekeeper->resolve(
            0,
            $attemptId,
            '   ',
            '',
            null,
            true
        );

        $this->assertFalse((bool) ($resolved['ok'] ?? false));
        $this->assertSame('RESULT_NOT_FOUND', (string) ($resolved['error'] ?? ''));
    }

    private function seedAttempt(int $orgId): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => $orgId,
            'anon_id' => 'anon_boundary_owner',
            'user_id' => '1001',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'answers_summary_json' => ['seed' => true],
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => (string) config('content_packs.default_dir_version'),
            'content_package_version' => 'v0.2.2',
            'scoring_spec_version' => '2026.01',
        ]);

        return $attemptId;
    }
}
