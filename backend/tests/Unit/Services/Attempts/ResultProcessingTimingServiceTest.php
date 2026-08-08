<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Attempts;

use App\Services\Attempts\ResultProcessingTimingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ResultProcessingTimingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_only_sanitized_stage_durations_for_a_ready_report(): void
    {
        $attemptId = (string) Str::uuid();
        $submissionId = (string) Str::uuid();

        DB::table('attempt_submissions')->insert([
            'id' => $submissionId,
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'dedupe_key' => hash('sha256', $submissionId),
            'mode' => 'async',
            'state' => 'succeeded',
            'created_at' => '2026-08-08 12:00:00.000',
            'started_at' => '2026-08-08 12:00:01.250',
            'finished_at' => '2026-08-08 12:00:03.750',
            'updated_at' => '2026-08-08 12:00:03.750',
        ]);

        DB::table('report_snapshots')->insert([
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'BIG5_OCEAN',
            'pack_id' => 'big5_test',
            'dir_version' => 'test',
            'report_engine_version' => 'v1.2',
            'snapshot_version' => 'v1',
            'report_json' => '{}',
            'status' => 'ready',
            'created_at' => '2026-08-08 12:00:03.750',
            'updated_at' => '2026-08-08 12:00:05.000',
        ]);

        $timing = app(ResultProcessingTimingService::class)->forAttempt(0, $attemptId);

        $this->assertSame([
            'version' => 'v1',
            'phase' => 'ready',
            'queue_wait_ms' => 1250,
            'scoring_ms' => 2500,
            'report_wait_ms' => 1250,
            'server_total_ms' => 5000,
        ], $timing);
        $this->assertArrayNotHasKey('attempt_id', $timing);
        $this->assertArrayNotHasKey('queued_at', $timing);
        $this->assertArrayNotHasKey('started_at', $timing);
        $this->assertArrayNotHasKey('scored_at', $timing);
        $this->assertArrayNotHasKey('report_ready_at', $timing);
    }

    public function test_it_reports_queued_without_exposing_raw_timestamps(): void
    {
        $attemptId = (string) Str::uuid();
        $submissionId = (string) Str::uuid();

        DB::table('attempt_submissions')->insert([
            'id' => $submissionId,
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'dedupe_key' => hash('sha256', $submissionId),
            'mode' => 'async',
            'state' => 'pending',
            'created_at' => '2026-08-08 12:00:00',
            'updated_at' => '2026-08-08 12:00:00',
        ]);

        $timing = app(ResultProcessingTimingService::class)->forAttempt(0, $attemptId);

        $this->assertSame('queued', $timing['phase']);
        $this->assertNull($timing['queue_wait_ms']);
        $this->assertNull($timing['scoring_ms']);
        $this->assertNull($timing['report_wait_ms']);
        $this->assertNull($timing['server_total_ms']);
        $this->assertSame(
            ['version', 'phase', 'queue_wait_ms', 'scoring_ms', 'report_wait_ms', 'server_total_ms'],
            array_keys($timing)
        );
    }
}
