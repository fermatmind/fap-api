<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BackfillReportSnapshotsScaleIdentityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_populates_scale_identity_columns_for_known_report_snapshots(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $attemptId = (string) Str::uuid();
        DB::table('report_snapshots')->insert([
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'order_no' => null,
            'scale_code' => 'MBTI',
            'scale_code_v2' => null,
            'scale_uid' => null,
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => 'seed',
            'report_engine_version' => 'v1.2',
            'snapshot_version' => 'v1',
            'report_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'status' => 'ready',
            'last_error' => null,
            'updated_at' => now(),
        ]);

        $this->artisan('ops:backfill-report-snapshots-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_report_snapshots_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($after);
        $this->assertSame('MBTI_PERSONALITY_TEST_16_TYPES', (string) ($after->scale_code_v2 ?? ''));
        $this->assertSame('11111111-1111-4111-8111-111111111111', (string) ($after->scale_uid ?? ''));
    }

    public function test_backfill_skips_unknown_scale_code_report_snapshots(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $attemptId = (string) Str::uuid();
        DB::table('report_snapshots')->insert([
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'order_no' => null,
            'scale_code' => 'UNKNOWN_SCALE',
            'scale_code_v2' => null,
            'scale_uid' => null,
            'pack_id' => 'seed',
            'dir_version' => 'seed',
            'scoring_spec_version' => 'seed',
            'report_engine_version' => 'v1.2',
            'snapshot_version' => 'v1',
            'report_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'status' => 'ready',
            'last_error' => null,
            'updated_at' => now(),
        ]);

        $this->artisan('ops:backfill-report-snapshots-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_report_snapshots_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($after);
        $this->assertNull($after->scale_code_v2);
        $this->assertNull($after->scale_uid);
    }
}

