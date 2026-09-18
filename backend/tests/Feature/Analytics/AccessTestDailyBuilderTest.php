<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\AccessTestDailyBuilder;
use App\Services\Analytics\AccessTestIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccessTestDailyBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_refresh_deduplicates_ips_requests_and_attempts_without_cross_scope_summing(): void
    {
        config()->set('analytics.access_test_statistics.collection_started_on', '2026-09-18');
        $day = CarbonImmutable::parse('2026-09-18', 'Asia/Shanghai');
        $sameIp = str_repeat('a', 64);
        $otherIp = str_repeat('b', 64);

        $this->insertPageView('pv-1', 'request-1', $sameIp, '2026-09-18 00:30:00');
        $this->insertPageView('pv-2', 'request-1', $sameIp, '2026-09-18 00:31:00');
        $this->insertPageView('pv-3', 'request-3', $otherIp, '2026-09-18 00:32:00');
        $this->insertPageView('pv-bot', 'request-bot', $otherIp, '2026-09-18 00:33:00', false, 'confirmed_bot');

        $first = $this->insertAttempt('MBTI', 'zh-CN', $sameIp, '2026-09-18 01:00:00', '2026-09-18 01:20:00');
        $second = $this->insertAttempt('RIASEC', 'en', $sameIp, '2026-09-18 02:00:00', '2026-09-18 02:20:00');
        $missing = $this->insertAttempt('ENNEAGRAM', 'zh-CN', null, '2026-09-18 03:00:00', '2026-09-18 03:20:00');
        $this->insertResult($first, 'MBTI', '2026-09-18 01:21:00');
        $this->insertResult($second, 'RIASEC', '2026-09-18 02:21:00');
        $this->insertResult($missing, 'ENNEAGRAM', '2026-09-18 03:21:00');

        $builder = app(AccessTestDailyBuilder::class);
        $builder->refresh($day, $day, [0]);
        $builder->refresh($day, $day, [0]);

        $global = DB::table('analytics_access_test_daily')
            ->where(['day' => '2026-09-18', 'org_id' => 0, 'scale_code' => '*', 'form_code' => '*', 'locale' => '*'])
            ->first();

        $this->assertNotNull($global);
        $this->assertSame(2, (int) $global->valid_visit_ips);
        $this->assertSame(2, (int) $global->valid_page_views);
        $this->assertSame(1, (int) $global->started_test_ips);
        $this->assertSame(1, (int) $global->completed_test_ips);
        $this->assertSame(3, (int) $global->successful_attempts);
        $this->assertSame(1, (int) $global->missing_started_ip_attempts);
        $this->assertSame(1, (int) $global->missing_completed_ip_attempts);
        $this->assertSame(1, (int) $global->excluded_page_views);
        $this->assertSame(1, DB::table('analytics_access_test_daily')->where([
            'day' => '2026-09-18', 'org_id' => 0, 'scale_code' => '*', 'form_code' => '*', 'locale' => '*',
        ])->count());

        $perTestSuccesses = DB::table('analytics_access_test_daily')
            ->where('day', '2026-09-18')
            ->where('org_id', 0)
            ->where('scale_code', '<>', '*')
            ->where('form_code', '*')
            ->where('locale', '*')
            ->sum('successful_attempts');
        $this->assertSame(3, (int) $perTestSuccesses);
    }

    public function test_start_and_completion_are_attributed_to_their_own_shanghai_days(): void
    {
        config()->set('analytics.access_test_statistics.collection_started_on', '2026-09-18');
        $attempt = $this->insertAttempt(
            'BIG5_OCEAN',
            'zh-CN',
            str_repeat('c', 64),
            '2026-09-18 15:59:00',
            '2026-09-18 16:01:00'
        );
        $this->insertResult($attempt, 'BIG5_OCEAN', '2026-09-18 16:02:00');

        $builder = app(AccessTestDailyBuilder::class);
        $builder->refresh(
            CarbonImmutable::parse('2026-09-18', AccessTestIdentity::TIMEZONE),
            CarbonImmutable::parse('2026-09-19', AccessTestIdentity::TIMEZONE),
            [0]
        );

        $firstDay = $this->globalRow('2026-09-18');
        $secondDay = $this->globalRow('2026-09-19');
        $this->assertSame(1, (int) $firstDay->started_test_ips);
        $this->assertSame(0, (int) $firstDay->successful_attempts);
        $this->assertSame(0, (int) $secondDay->started_test_ips);
        $this->assertSame(1, (int) $secondDay->completed_test_ips);
        $this->assertSame(1, (int) $secondDay->successful_attempts);
    }

    private function insertPageView(
        string $id,
        string $requestId,
        string $ipHash,
        string $occurredAt,
        bool $eligible = true,
        ?string $reason = null,
    ): void {
        DB::table('events')->insert([
            'id' => $id,
            'event_code' => 'landing_pv',
            'event_name' => 'landing_pv',
            'org_id' => 0,
            'request_id' => $requestId,
            'locale' => 'zh-CN',
            'meta_json' => json_encode(['raw_payload' => ['environment' => 'production']], JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt,
            'analytics_ip_hash' => $ipHash,
            'analytics_ip_status' => 'trusted_digest',
            'analytics_eligible' => $eligible,
            'analytics_exclusion_reason' => $reason,
            'analytics_rule_version' => AccessTestIdentity::RULE_VERSION,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }

    private function insertAttempt(
        string $scale,
        string $locale,
        ?string $ipHash,
        string $startedAt,
        string $submittedAt,
    ): string {
        $id = (string) Str::uuid();
        DB::table('attempts')->insert([
            'id' => $id,
            'anon_id' => 'anon_'.Str::lower(Str::random(12)),
            'user_id' => null,
            'org_id' => 0,
            'scale_code' => $scale,
            'scale_version' => 'v1',
            'question_count' => 60,
            'answers_summary_json' => json_encode(['meta' => ['form_code' => $scale.'_FORM']], JSON_THROW_ON_ERROR),
            'client_platform' => 'web',
            'channel' => 'organic',
            'locale' => $locale,
            'started_at' => $startedAt,
            'submitted_at' => $submittedAt,
            'analytics_start_ip_hash' => $ipHash,
            'analytics_start_ip_status' => $ipHash === null ? 'missing' : 'trusted',
            'analytics_start_eligible' => true,
            'analytics_submit_ip_hash' => $ipHash,
            'analytics_submit_ip_status' => $ipHash === null ? 'missing' : 'trusted',
            'analytics_submit_eligible' => true,
            'analytics_rule_version' => AccessTestIdentity::RULE_VERSION,
            'created_at' => $startedAt,
            'updated_at' => $submittedAt,
        ]);

        return $id;
    }

    private function insertResult(string $attemptId, string $scale, string $computedAt): void
    {
        DB::table('results')->insert([
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'org_id' => 0,
            'scale_code' => $scale,
            'scale_version' => 'v1',
            'type_code' => 'TEST',
            'scores_json' => json_encode([], JSON_THROW_ON_ERROR),
            'is_valid' => true,
            'computed_at' => $computedAt,
            'created_at' => $computedAt,
            'updated_at' => $computedAt,
        ]);
    }

    private function globalRow(string $day): object
    {
        return DB::table('analytics_access_test_daily')->where([
            'day' => $day,
            'org_id' => 0,
            'scale_code' => '*',
            'form_code' => '*',
            'locale' => '*',
        ])->firstOrFail();
    }
}
