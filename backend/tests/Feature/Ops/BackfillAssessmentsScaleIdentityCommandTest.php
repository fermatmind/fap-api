<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BackfillAssessmentsScaleIdentityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_populates_scale_identity_columns_for_known_assessments(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $assessmentId = (int) DB::table('assessments')->insertGetId([
            'org_id' => 0,
            'scale_code' => 'MBTI',
            'scale_code_v2' => null,
            'scale_uid' => null,
            'title' => 'Assessment Seed',
            'created_by' => 1,
            'due_at' => null,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('ops:backfill-assessments-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_assessments_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('assessments')->where('id', $assessmentId)->first();
        $this->assertNotNull($after);
        $this->assertSame('MBTI_PERSONALITY_TEST_16_TYPES', (string) ($after->scale_code_v2 ?? ''));
        $this->assertSame('11111111-1111-4111-8111-111111111111', (string) ($after->scale_uid ?? ''));
    }

    public function test_backfill_skips_unknown_assessment_scale_code(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $assessmentId = (int) DB::table('assessments')->insertGetId([
            'org_id' => 0,
            'scale_code' => 'UNKNOWN_SCALE',
            'scale_code_v2' => null,
            'scale_uid' => null,
            'title' => 'Assessment Unknown',
            'created_by' => 1,
            'due_at' => null,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('ops:backfill-assessments-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_assessments_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('assessments')->where('id', $assessmentId)->first();
        $this->assertNotNull($after);
        $this->assertNull($after->scale_code_v2);
        $this->assertNull($after->scale_uid);
    }
}
