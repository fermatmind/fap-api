<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait SeedsFunnelAnalyticsScenario
{
    /**
     * @return array{
     *     day:string,
     *     attempt_a:string,
     *     attempt_b:string,
     *     attempt_c:string,
     *     share_a:string
     * }
     */
    private function seedFunnelAnalyticsScenario(int $orgId): array
    {
        $day = CarbonImmutable::parse('2026-01-03 08:00:00');
        $attemptA = (string) Str::uuid();
        $attemptB = (string) Str::uuid();
        $attemptC = (string) Str::uuid();
        $shareA = (string) Str::uuid();
        $orderA = 'ord_funnel_a_001';
        $orderB = 'ord_funnel_b_001';

        $this->insertAttempt($attemptA, $orgId, 'en', $day, null);
        $this->insertAttempt($attemptB, $orgId, 'zh-CN', $day->addHours(3), null);
        $this->insertAttempt($attemptC, $orgId, 'en', $day->addHours(4), $day->addHours(4)->addMinutes(5));

        $this->insertAttemptSubmission($attemptA, $orgId, $day->addMinutes(5));
        $this->insertResult($attemptA, $orgId, $day->addMinutes(6));
        $this->insertResult($attemptB, $orgId, $day->addHours(3)->addMinutes(6));

        $this->insertEvent($orgId, 'paywall_view', $attemptA, $day->addMinutes(7));
        $this->insertEvent($orgId, 'report_viewed', $attemptA, $day->addMinutes(10));
        $this->insertEvent($orgId, 'paywall_view', $attemptB, $day->addHours(3)->addMinutes(7));
        $this->insertEvent($orgId, 'result_view', $attemptB, $day->addHours(3)->addMinutes(10));
        $this->insertEvent($orgId, 'paywall_view', $attemptC, $day->addHours(4)->addMinutes(10));

        $this->insertOrder($orderA, $attemptA, $orgId, $day->addMinutes(20), 1299, null);
        $this->insertOrder($orderB, $attemptB, $orgId, $day->addHours(3)->addMinutes(15), 2599, $day->addHours(3)->addMinutes(20));

        $this->insertPaymentEvent($orderA, $orgId, $day->addMinutes(30));
        $this->insertBenefitGrant($attemptA, $orderA, $orgId, $day->addMinutes(35));

        $this->insertReportSnapshot($attemptA, $orderA, $orgId, $day->addMinutes(40));
        $this->insertReportSnapshot($attemptB, $orderB, $orgId, $day->addHours(3)->addMinutes(25));

        $this->insertEvent($orgId, 'report_pdf_view', $attemptA, $day->addMinutes(50));
        $this->insertShare($shareA, $attemptA, $day->addHour());
        $this->insertEvent($orgId, 'share_click', null, $day->addHour()->addMinutes(5), $shareA);

        return [
            'day' => $day->toDateString(),
            'attempt_a' => $attemptA,
            'attempt_b' => $attemptB,
            'attempt_c' => $attemptC,
            'share_a' => $shareA,
        ];
    }

    private function insertAttempt(
        string $attemptId,
        int $orgId,
        string $locale,
        CarbonImmutable $createdAt,
        ?CarbonImmutable $submittedAt
    ): void {
        $row = [
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'anon_id' => 'anon_'.substr(str_replace('-', '', $attemptId), 0, 10),
            'user_id' => null,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'question_count' => 93,
            'answers_summary_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_platform' => 'web',
            'client_version' => 'test',
            'channel' => 'web',
            'referrer' => '/tests/funnel',
            'region' => $locale === 'zh-CN' ? 'CN_MAINLAND' : 'US',
            'locale' => $locale,
            'started_at' => $createdAt,
            'submitted_at' => $submittedAt,
            'created_at' => $createdAt,
            'updated_at' => $submittedAt ?? $createdAt,
            'pack_id' => 'MBTI',
            'dir_version' => 'mbti_dir_2026_01',
            'content_package_version' => 'content_2026_01',
            'scoring_spec_version' => 'scoring_2026_01',
            'norm_version' => 'norm_2026_01',
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

    private function insertAttemptSubmission(string $attemptId, int $orgId, CarbonImmutable $finishedAt): void
    {
        DB::table('attempt_submissions')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'actor_user_id' => null,
            'actor_anon_id' => 'actor_'.$attemptId,
            'dedupe_key' => 'dedupe_'.$attemptId,
            'mode' => 'async',
            'state' => 'succeeded',
            'error_code' => null,
            'error_message' => null,
            'request_payload_json' => json_encode(['attempt_id' => $attemptId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response_payload_json' => json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'started_at' => $finishedAt->subMinute(),
            'finished_at' => $finishedAt,
            'created_at' => $finishedAt->subMinute(),
            'updated_at' => $finishedAt,
        ]);
    }

    private function insertResult(string $attemptId, int $orgId, CarbonImmutable $computedAt): void
    {
        $row = [
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ',
            'scores_json' => json_encode(['EI' => 10], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'profile_version' => 'profile_2026_01',
            'is_valid' => 1,
            'computed_at' => $computedAt,
            'created_at' => $computedAt,
            'updated_at' => $computedAt,
        ];

        if (Schema::hasColumn('results', 'scores_pct')) {
            $row['scores_pct'] = json_encode(['EI' => 0.75], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (Schema::hasColumn('results', 'axis_states')) {
            $row['axis_states'] = json_encode(['EI' => 'I'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (Schema::hasColumn('results', 'scale_code_v2')) {
            $row['scale_code_v2'] = 'MBTI';
        }

        if (Schema::hasColumn('results', 'scale_uid')) {
            $row['scale_uid'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        }

        if (Schema::hasColumn('results', 'result_json')) {
            $row['result_json'] = json_encode(['type_code' => 'INTJ'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (Schema::hasColumn('results', 'pack_id')) {
            $row['pack_id'] = 'MBTI';
        }

        if (Schema::hasColumn('results', 'content_package_version')) {
            $row['content_package_version'] = 'content_2026_01';
        }

        if (Schema::hasColumn('results', 'dir_version')) {
            $row['dir_version'] = 'mbti_dir_2026_01';
        }

        if (Schema::hasColumn('results', 'scoring_spec_version')) {
            $row['scoring_spec_version'] = 'scoring_2026_01';
        }

        if (Schema::hasColumn('results', 'report_engine_version')) {
            $row['report_engine_version'] = 'report_2026_01';
        }

        DB::table('results')->insert($row);
    }

    private function insertOrder(
        string $orderNo,
        string $attemptId,
        int $orgId,
        CarbonImmutable $createdAt,
        int $amountCents,
        ?CarbonImmutable $paidAt
    ): void {
        $row = [
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'provider' => 'stripe',
            'status' => $paidAt ? 'paid' : 'created',
            'amount_cents' => $amountCents,
            'currency' => 'USD',
            'sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'user_id' => null,
            'anon_id' => 'anon_'.$attemptId,
            'org_id' => $orgId,
            'target_attempt_id' => $attemptId,
            'paid_at' => $paidAt,
            'created_at' => $createdAt,
            'updated_at' => $paidAt ?? $createdAt,
        ];

        if (Schema::hasColumn('orders', 'scale_code_v2')) {
            $row['scale_code_v2'] = 'MBTI';
        }

        if (Schema::hasColumn('orders', 'scale_uid')) {
            $row['scale_uid'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        }

        if (Schema::hasColumn('orders', 'amount_total')) {
            $row['amount_total'] = $amountCents;
        }

        if (Schema::hasColumn('orders', 'item_sku')) {
            $row['item_sku'] = 'MBTI_REPORT_FULL';
        }

        if (Schema::hasColumn('orders', 'provider_order_id')) {
            $row['provider_order_id'] = $orderNo;
        }

        DB::table('orders')->insert($row);
    }

    private function insertPaymentEvent(string $orderNo, int $orgId, CarbonImmutable $processedAt): void
    {
        $orderId = DB::table('orders')->where('order_no', $orderNo)->value('id');

        $row = [
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'provider' => 'stripe',
            'provider_event_id' => 'evt_'.Str::lower(Str::random(12)),
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'event_type' => 'payment_succeeded',
            'payload_json' => json_encode(['order_no' => $orderNo], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'received_at' => $processedAt->subMinute(),
            'created_at' => $processedAt->subMinute(),
            'updated_at' => $processedAt,
            'status' => 'paid',
            'handle_status' => 'processed',
            'processed_at' => $processedAt,
        ];

        if (Schema::hasColumn('payment_events', 'handled_at')) {
            $row['handled_at'] = $processedAt;
        }

        if (Schema::hasColumn('payment_events', 'signature_ok')) {
            $row['signature_ok'] = 1;
        }

        if (Schema::hasColumn('payment_events', 'reason')) {
            $row['reason'] = 'paid';
        }

        if (Schema::hasColumn('payment_events', 'order_id')) {
            $row['order_id'] = $orderId;
        }

        DB::table('payment_events')->insert($row);
    }

    private function insertBenefitGrant(string $attemptId, string $orderNo, int $orgId, CarbonImmutable $createdAt): void
    {
        $row = [
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'user_id' => null,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'status' => 'active',
            'benefit_type' => 'report',
            'benefit_ref' => 'full',
            'order_no' => $orderNo,
            'source_order_id' => DB::table('orders')->where('order_no', $orderNo)->value('id'),
            'source_event_id' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];

        DB::table('benefit_grants')->insert($row);
    }

    private function insertReportSnapshot(string $attemptId, string $orderNo, int $orgId, CarbonImmutable $updatedAt): void
    {
        $row = [
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'order_no' => $orderNo,
            'scale_code' => 'MBTI',
            'pack_id' => 'MBTI',
            'dir_version' => 'mbti_dir_2026_01',
            'scoring_spec_version' => 'scoring_2026_01',
            'report_engine_version' => 'report_2026_01',
            'snapshot_version' => 'v1',
            'report_json' => json_encode(['summary' => 'ready'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'ready',
            'created_at' => $updatedAt->subMinutes(2),
            'updated_at' => $updatedAt,
        ];

        if (Schema::hasColumn('report_snapshots', 'scale_code_v2')) {
            $row['scale_code_v2'] = 'MBTI';
        }

        if (Schema::hasColumn('report_snapshots', 'scale_uid')) {
            $row['scale_uid'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        }

        if (Schema::hasColumn('report_snapshots', 'report_free_json')) {
            $row['report_free_json'] = json_encode(['summary' => 'free'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (Schema::hasColumn('report_snapshots', 'report_full_json')) {
            $row['report_full_json'] = json_encode(['summary' => 'full'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        DB::table('report_snapshots')->insert($row);
    }

    private function insertShare(string $shareId, string $attemptId, CarbonImmutable $createdAt): void
    {
        $row = [
            'id' => $shareId,
            'attempt_id' => $attemptId,
            'anon_id' => 'anon_'.$attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'content_package_version' => 'content_2026_01',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];

        if (Schema::hasColumn('shares', 'scale_code_v2')) {
            $row['scale_code_v2'] = 'MBTI';
        }

        if (Schema::hasColumn('shares', 'scale_uid')) {
            $row['scale_uid'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        }

        DB::table('shares')->insert($row);
    }

    private function insertEvent(
        int $orgId,
        string $eventCode,
        ?string $attemptId,
        CarbonImmutable $occurredAt,
        ?string $shareId = null
    ): void {
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
            'meta_json' => json_encode(['attempt_id' => $attemptId, 'share_id' => $shareId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $occurredAt,
            'share_id' => $shareId,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
            'scale_code' => 'MBTI',
            'channel' => 'web',
            'region' => 'US',
            'locale' => 'en',
        ];

        if (Schema::hasColumn('events', 'scale_code_v2')) {
            $row['scale_code_v2'] = 'MBTI';
        }

        if (Schema::hasColumn('events', 'scale_uid')) {
            $row['scale_uid'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        }

        DB::table('events')->insert($row);
    }
}
