<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Attempt;
use App\Services\Report\ReportSnapshotStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportSnapshotStoreTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_snapshot_request_does_not_cross_tenant_boundary(): void
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 202,
            'user_id' => 9002,
            'anon_id' => 'anon_snapshot_tenant_guard',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        $response = app(ReportSnapshotStore::class)->createSnapshotForAttempt([
            'org_id' => 999,
            'attempt_id' => $attemptId,
            'trigger_source' => 'tenant-isolation-test',
            'org_role' => 'system',
        ]);

        $this->assertSame(false, $response['ok'] ?? null);
        $this->assertSame('ATTEMPT_NOT_FOUND', $response['error'] ?? null);
        $this->assertSame(0, DB::table('report_snapshots')->where('attempt_id', $attemptId)->count());
    }
}
