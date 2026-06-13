<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\TestMetricsDailyBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TestMetricsDailyBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builder_aggregates_daily_success_failure_and_started_counts_by_test_scope(): void
    {
        $day = CarbonImmutable::parse('2026-06-13 09:00:00');
        $mbtiSuccess = (string) Str::uuid();
        $mbtiFailure = (string) Str::uuid();
        $bigFiveSuccess = (string) Str::uuid();

        $this->insertAttempt($mbtiSuccess, 7, 'MBTI', 'MBTI', 'zh-CN', $day, $day->addMinutes(8));
        $this->insertAttempt($mbtiFailure, 7, 'MBTI', 'MBTI', 'zh-CN', $day->addMinutes(2), null);
        $this->insertAttempt($bigFiveSuccess, 7, 'BIG5_OCEAN', 'BIG_FIVE_OCEAN_MODEL', 'en', $day->addMinutes(3), $day->addMinutes(14));

        $this->insertSubmission($mbtiSuccess, 7, 'succeeded', $day->addMinutes(9));
        $this->insertSubmission($mbtiFailure, 7, 'failed', $day->addMinutes(10));
        $this->insertSubmission($mbtiFailure, 7, 'failed', $day->addMinutes(11));
        $this->insertSubmission($bigFiveSuccess, 7, 'completed', $day->addMinutes(15));

        $payload = app(TestMetricsDailyBuilder::class)->build($day, $day, [7]);
        $rows = collect($payload['rows'])->keyBy('scale_code');

        $mbti = $rows->get('MBTI');
        $bigFive = $rows->get('BIG5_OCEAN');

        $this->assertNotNull($mbti);
        $this->assertSame('2026-06-13', $mbti['day']);
        $this->assertSame(2, (int) $mbti['started_attempts']);
        $this->assertSame(1, (int) $mbti['successful_attempts']);
        $this->assertSame(1, (int) $mbti['failed_attempts']);
        $this->assertSame(2, (int) $mbti['total_attempts']);
        $this->assertSame('zh-CN', $mbti['locale']);

        $this->assertNotNull($bigFive);
        $this->assertSame(1, (int) $bigFive['started_attempts']);
        $this->assertSame(1, (int) $bigFive['successful_attempts']);
        $this->assertSame(0, (int) $bigFive['failed_attempts']);
        $this->assertSame(1, (int) $bigFive['total_attempts']);
        $this->assertSame('BIG_FIVE_OCEAN_MODEL', $bigFive['scale_code_v2']);
    }

    public function test_refresh_upserts_and_replaces_the_requested_scope(): void
    {
        $day = CarbonImmutable::parse('2026-06-14 09:00:00');
        $attemptId = (string) Str::uuid();

        $this->insertAttempt($attemptId, 9, 'ENNEAGRAM', 'ENNEAGRAM_PERSONALITY_TEST', 'en', $day, $day->addMinutes(20));
        $this->insertSubmission($attemptId, 9, 'succeeded', $day->addMinutes(21));

        $builder = app(TestMetricsDailyBuilder::class);
        $first = $builder->refresh($day, $day, [9], [], false);
        $second = $builder->refresh($day, $day, [9], [], false);

        $this->assertSame(1, (int) $first['upserted_rows']);
        $this->assertSame(1, (int) $second['deleted_rows']);
        $this->assertSame(1, (int) $second['upserted_rows']);

        $this->assertDatabaseHas('analytics_test_metrics_daily', [
            'day' => '2026-06-14',
            'org_id' => 9,
            'scale_code' => 'ENNEAGRAM',
            'successful_attempts' => 1,
            'failed_attempts' => 0,
            'total_attempts' => 1,
        ]);
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
