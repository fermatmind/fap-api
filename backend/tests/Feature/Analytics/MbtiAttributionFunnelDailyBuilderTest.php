<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\MbtiAttributionFunnelDailyBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MbtiAttributionFunnelDailyBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_builds_mbti_rows_grouped_by_entry_surface_and_source_page_type(): void
    {
        $orgId = 11;
        $day = CarbonImmutable::parse('2026-04-06 09:00:00');

        $attemptTopic = (string) Str::uuid();
        $attemptPersonality = (string) Str::uuid();

        $this->insertMbtiAttempt(
            attemptId: $attemptTopic,
            orgId: $orgId,
            locale: 'zh',
            createdAt: $day,
            entrySurface: 'mbti_topic_detail',
            sourcePageType: 'topic_detail',
            testSlug: 'mbti-personality-test-16-personality-types',
            formCode: 'mbti_144',
        );
        $this->insertMbtiAttempt(
            attemptId: $attemptPersonality,
            orgId: $orgId,
            locale: 'zh',
            createdAt: $day->addMinutes(5),
            entrySurface: 'mbti_personality_detail',
            sourcePageType: 'personality_detail',
            testSlug: 'mbti-personality-test-16-personality-types',
            formCode: 'mbti_144',
        );

        $this->insertEvent($orgId, 'landing_view', null, $day, [
            'entry_surface' => 'mbti_topic_detail',
            'source_page_type' => 'topic_detail',
            'test_slug' => 'mbti-personality-test-16-personality-types',
            'form_code' => 'mbti_144',
            'locale' => 'zh',
        ]);
        $this->insertEvent($orgId, 'start_click', null, $day->addMinute(), [
            'entry_surface' => 'mbti_topic_detail',
            'source_page_type' => 'topic_detail',
            'test_slug' => 'mbti-personality-test-16-personality-types',
            'form_code' => 'mbti_144',
            'locale' => 'zh',
        ]);
        $this->insertEvent($orgId, 'view_result', $attemptTopic, $day->addMinutes(10));
        $this->insertEvent($orgId, 'click_unlock', $attemptTopic, $day->addMinutes(12));
        $this->insertEvent($orgId, 'invite_create_success', $attemptTopic, $day->addMinutes(15));
        $this->insertEvent($orgId, 'invite_share_or_copy', $attemptTopic, $day->addMinutes(16));
        $this->insertEvent($orgId, 'invite_unlock_completion_qualified', $attemptTopic, $day->addMinutes(40), [
            'target_attempt_id' => $attemptTopic,
            'completion_id' => (string) Str::uuid(),
        ]);
        $this->insertEvent($orgId, 'invite_unlock_full_granted', $attemptTopic, $day->addMinutes(41), [
            'target_attempt_id' => $attemptTopic,
        ]);

        $this->insertOrder(
            orderNo: 'ord_pr7_mbti_001',
            attemptId: $attemptTopic,
            orgId: $orgId,
            createdAt: $day->addMinutes(20),
            paidAt: $day->addMinutes(24),
            fulfilledAt: $day->addMinutes(28),
        );

        $result = app(MbtiAttributionFunnelDailyBuilder::class)->refresh(
            $day,
            $day,
            [$orgId],
            false,
        );

        $this->assertSame(2, (int) ($result['upserted_rows'] ?? 0));

        $topicRow = DB::table('analytics_mbti_attribution_daily')
            ->where('day', $day->toDateString())
            ->where('org_id', $orgId)
            ->where('locale', 'zh')
            ->where('entry_surface', 'mbti_topic_detail')
            ->where('source_page_type', 'topic_detail')
            ->first();

        $this->assertNotNull($topicRow);
        $this->assertSame(1, (int) ($topicRow->entry_views ?? 0));
        $this->assertSame(1, (int) ($topicRow->start_clicks ?? 0));
        $this->assertSame(1, (int) ($topicRow->start_attempts ?? 0));
        $this->assertSame(1, (int) ($topicRow->result_views ?? 0));
        $this->assertSame(1, (int) ($topicRow->unlock_clicks ?? 0));
        $this->assertSame(1, (int) ($topicRow->orders_created ?? 0));
        $this->assertSame(1, (int) ($topicRow->payments_confirmed ?? 0));
        $this->assertSame(1, (int) ($topicRow->invite_creates ?? 0));
        $this->assertSame(1, (int) ($topicRow->invite_shares ?? 0));
        $this->assertSame(1, (int) ($topicRow->invite_completions ?? 0));
        $this->assertSame(1, (int) ($topicRow->payment_unlock_successes ?? 0));
        $this->assertSame(1, (int) ($topicRow->invite_unlock_successes ?? 0));
        $this->assertSame(2, (int) ($topicRow->unlock_successes ?? 0));

        $personalityRow = DB::table('analytics_mbti_attribution_daily')
            ->where('day', $day->toDateString())
            ->where('org_id', $orgId)
            ->where('locale', 'zh')
            ->where('entry_surface', 'mbti_personality_detail')
            ->where('source_page_type', 'personality_detail')
            ->first();

        $this->assertNotNull($personalityRow);
        $this->assertSame(1, (int) ($personalityRow->start_attempts ?? 0));
        $this->assertSame(0, (int) ($personalityRow->entry_views ?? 0));
        $this->assertSame(0, (int) ($personalityRow->payments_confirmed ?? 0));
    }

    private function insertMbtiAttempt(
        string $attemptId,
        int $orgId,
        string $locale,
        CarbonImmutable $createdAt,
        string $entrySurface,
        string $sourcePageType,
        string $testSlug,
        string $formCode,
    ): void {
        $answersSummary = [
            'stage' => 'start',
            'meta' => [
                'entry_surface' => $entrySurface,
                'source_page_type' => $sourcePageType,
                'test_slug' => $testSlug,
                'form_code' => $formCode,
                'landing_path' => '/zh/tests/mbti-personality-test-16-personality-types',
            ],
        ];

        $row = [
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'anon_id' => 'anon_'.substr(str_replace('-', '', $attemptId), 0, 10),
            'user_id' => null,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'question_count' => 93,
            'answers_summary_json' => json_encode($answersSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_platform' => 'web',
            'client_version' => 'test',
            'channel' => 'web',
            'referrer' => '/tests/mbti-personality-test-16-personality-types',
            'region' => 'CN_MAINLAND',
            'locale' => $locale,
            'started_at' => $createdAt,
            'submitted_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'pack_id' => 'MBTI',
            'dir_version' => 'mbti_dir_2026_04',
            'content_package_version' => 'content_2026_04',
            'scoring_spec_version' => 'scoring_2026_04',
            'norm_version' => 'norm_2026_04',
            'result_json' => json_encode(['type_code' => 'INTJ'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if (Schema::hasColumn('attempts', 'scale_code_v2')) {
            $row['scale_code_v2'] = 'MBTI';
        }

        if (Schema::hasColumn('attempts', 'scale_uid')) {
            $row['scale_uid'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        }

        DB::table('attempts')->insert($row);
    }

    private function insertEvent(
        int $orgId,
        string $eventCode,
        ?string $attemptId,
        CarbonImmutable $occurredAt,
        array $meta = [],
    ): void {
        $metaPayload = array_merge([
            'attempt_id' => $attemptId,
            'target_attempt_id' => $attemptId,
        ], $meta);

        $row = [
            'id' => (string) Str::uuid(),
            'event_code' => $eventCode,
            'event_name' => $eventCode,
            'org_id' => $orgId,
            'user_id' => null,
            'anon_id' => $attemptId ? 'anon_'.$attemptId : null,
            'session_id' => 'session_'.substr(str_replace('-', '', (string) Str::uuid()), 0, 8),
            'request_id' => 'req_'.substr(str_replace('-', '', (string) Str::uuid()), 0, 12),
            'attempt_id' => $attemptId,
            'meta_json' => json_encode($metaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $occurredAt,
            'share_id' => null,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
            'scale_code' => 'MBTI',
            'channel' => 'web',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh',
        ];

        if (Schema::hasColumn('events', 'scale_code_v2')) {
            $row['scale_code_v2'] = 'MBTI';
        }

        if (Schema::hasColumn('events', 'scale_uid')) {
            $row['scale_uid'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        }

        DB::table('events')->insert($row);
    }

    private function insertOrder(
        string $orderNo,
        string $attemptId,
        int $orgId,
        CarbonImmutable $createdAt,
        CarbonImmutable $paidAt,
        CarbonImmutable $fulfilledAt,
    ): void {
        $row = [
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'provider' => 'stripe',
            'status' => 'fulfilled',
            'payment_state' => 'paid',
            'grant_state' => 'granted',
            'amount_cents' => 199,
            'currency' => 'CNY',
            'sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'user_id' => null,
            'anon_id' => 'anon_'.$attemptId,
            'org_id' => $orgId,
            'target_attempt_id' => $attemptId,
            'paid_at' => $paidAt,
            'fulfilled_at' => $fulfilledAt,
            'created_at' => $createdAt,
            'updated_at' => $fulfilledAt,
        ];

        if (Schema::hasColumn('orders', 'scale_code_v2')) {
            $row['scale_code_v2'] = 'MBTI';
        }

        if (Schema::hasColumn('orders', 'scale_uid')) {
            $row['scale_uid'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        }

        if (Schema::hasColumn('orders', 'amount_total')) {
            $row['amount_total'] = 199;
        }

        if (Schema::hasColumn('orders', 'item_sku')) {
            $row['item_sku'] = 'MBTI_REPORT_FULL';
        }

        DB::table('orders')->insert($row);
    }
}
