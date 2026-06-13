<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RefreshTestMetricsDailyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_supports_dry_run_and_controlled_scope_write(): void
    {
        $day = CarbonImmutable::parse('2026-06-15 09:00:00');
        $success = (string) Str::uuid();
        $failure = (string) Str::uuid();

        $this->insertAttempt($success, 12, 'MBTI', 'MBTI', 'zh-CN', $day, $day->addMinutes(8));
        $this->insertAttempt($failure, 12, 'MBTI', 'MBTI', 'zh-CN', $day->addMinutes(2), null);
        $this->insertSubmission($success, 12, 'succeeded', $day->addMinutes(9));
        $this->insertSubmission($failure, 12, 'failed', $day->addMinutes(10));

        $this->artisan('analytics:refresh-test-metrics-daily', [
            '--from' => $day->toDateString(),
            '--to' => $day->toDateString(),
            '--org' => [12],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('attempted_rows=1')
            ->expectsOutputToContain('write_guard=dry_run_no_write')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('analytics_test_metrics_daily')->count());

        $this->artisan('analytics:refresh-test-metrics-daily', [
            '--from' => $day->toDateString(),
            '--to' => $day->toDateString(),
            '--org' => [12],
            '--confirm-write' => 'analytics_test_metrics_daily:write:'.$day->toDateString().':'.$day->toDateString().':org=12:scale=all',
        ])
            ->expectsOutputToContain('dry_run=0')
            ->expectsOutputToContain('upserted_rows=1')
            ->expectsOutputToContain('write_guard=passed')
            ->assertExitCode(0);

        $this->assertDatabaseHas('analytics_test_metrics_daily', [
            'day' => $day->toDateString(),
            'org_id' => 12,
            'scale_code' => 'MBTI',
            'started_attempts' => 2,
            'successful_attempts' => 1,
            'failed_attempts' => 1,
            'total_attempts' => 2,
        ]);
    }

    public function test_command_blocks_unconfirmed_non_dry_run_writes(): void
    {
        $day = CarbonImmutable::parse('2026-06-16 09:00:00');

        $this->artisan('analytics:refresh-test-metrics-daily', [
            '--from' => $day->toDateString(),
            '--to' => $day->toDateString(),
            '--org' => [12],
        ])
            ->expectsOutputToContain('write_guard=blocked')
            ->expectsOutputToContain('write_guard_reason=confirm_write_token_mismatch')
            ->expectsOutputToContain('expected_confirm_write=analytics_test_metrics_daily:write:2026-06-16:2026-06-16:org=12:scale=all')
            ->assertExitCode(1);
    }

    private function insertAttempt(
        string $attemptId,
        int $orgId,
        string $scaleCode,
        string $scaleCodeV2,
        string $locale,
        CarbonImmutable $startedAt,
        ?CarbonImmutable $submittedAt
    ): void {
        $row = [
            'id' => $attemptId,
            'anon_id' => 'anon_'.substr(str_replace('-', '', $attemptId), 0, 10),
            'user_id' => null,
            'org_id' => $orgId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'question_count' => 100,
            'answers_summary_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_platform' => 'web',
            'client_version' => 'test',
            'channel' => 'web',
            'referrer' => '/tests/test-metrics',
            'locale' => $locale,
            'started_at' => $startedAt,
            'submitted_at' => $submittedAt,
            'created_at' => $startedAt,
            'updated_at' => $submittedAt ?? $startedAt,
        ];

        if (Schema::hasColumn('attempts', 'scale_code_v2')) {
            $row['scale_code_v2'] = $scaleCodeV2;
        }

        if (Schema::hasColumn('attempts', 'scale_uid')) {
            $row['scale_uid'] = (string) Str::uuid();
        }

        DB::table('attempts')->insert($row);
    }

    private function insertSubmission(string $attemptId, int $orgId, string $state, CarbonImmutable $finishedAt): void
    {
        DB::table('attempt_submissions')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'actor_user_id' => null,
            'actor_anon_id' => 'actor_'.$attemptId,
            'dedupe_key' => 'dedupe_'.$attemptId.'_'.$state.'_'.$finishedAt->timestamp,
            'mode' => 'async',
            'state' => $state,
            'error_code' => $state === 'failed' ? 'SCORING_FAILED' : null,
            'error_message' => $state === 'failed' ? 'fixture failure' : null,
            'request_payload_json' => json_encode(['attempt_id' => $attemptId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response_payload_json' => json_encode(['ok' => $state !== 'failed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'started_at' => $finishedAt->subMinute(),
            'finished_at' => $finishedAt,
            'created_at' => $finishedAt->subMinute(),
            'updated_at' => $finishedAt,
        ]);
    }
}
