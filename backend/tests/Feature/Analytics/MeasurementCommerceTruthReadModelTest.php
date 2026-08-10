<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\MeasurementCommerceTruthReadModel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsFunnelAnalyticsScenario;
use Tests\TestCase;

final class MeasurementCommerceTruthReadModelTest extends TestCase
{
    use RefreshDatabase;
    use SeedsFunnelAnalyticsScenario;

    public function test_business_tables_remain_authoritative_and_coverage_events_do_not_inflate_counts(): void
    {
        $day = CarbonImmutable::parse('2026-08-10 08:00:00');
        $attemptA = $this->commerceAttempt($day, 'en', 'mbti_93');
        $attemptB = $this->commerceAttempt($day->addHour(), 'zh-CN', 'mbti_60');

        $this->insertOrder('ord-commerce-a', $attemptA, 91, $day, 1299, $day->addMinutes(10));
        $this->setOrderAuthority('ord-commerce-a', 'paid', 'web', 'stripe');
        $this->insertPaymentEvent('ord-commerce-a', 91, $day->addMinutes(11));
        $this->insertPaymentEvent('ord-commerce-a', 91, $day->addMinutes(12));
        $this->insertBenefitGrant($attemptA, 'ord-commerce-a', 91, $day->addMinutes(15));
        $this->insertEvent(91, 'report_unlock', $attemptA, $day->addMinutes(16));
        $this->insertReportSnapshot($attemptA, 'ord-commerce-a', 91, $day->addMinutes(20));
        DB::table('report_snapshots')->where('attempt_id', $attemptA)->update([
            'report_json' => json_encode(['private_report_marker' => 'must-not-leak']),
        ]);
        $this->insertEvent(91, 'report_ready', $attemptA, $day->addMinutes(21));
        $this->insertEvent(91, 'report_ready', $attemptA, $day->addMinutes(22));
        $this->insertEvent(91, 'result_ready', $attemptA, $day->addMinutes(23));

        $this->insertOrder('ord-commerce-b', $attemptB, 91, $day->addHour(), 2599, $day->addHour()->addMinutes(10));
        $this->setOrderAuthority('ord-commerce-b', 'refunded', 'alipay_miniapp', 'alipay', $day->addHour()->addMinutes(30));
        $this->insertPaymentEvent('ord-commerce-b', 91, $day->addHour()->addMinutes(11));

        $report = app(MeasurementCommerceTruthReadModel::class)->report('2026-08-10', '2026-08-10');

        $this->assertTrue($report['ok'] ?? false);
        $this->assertSame('warning', $report['status'] ?? null);
        $this->assertSame(2, data_get($report, 'totals.order_created_count'));
        $this->assertSame(2, data_get($report, 'totals.payment_succeeded_count'));
        $this->assertSame(1, data_get($report, 'totals.refund_count'));
        $this->assertSame(1, data_get($report, 'totals.report_unlock_count'));
        $this->assertSame(1, data_get($report, 'totals.report_ready_count'));
        $this->assertSame(1, data_get($report, 'totals.active_entitlement_count'));
        $this->assertSame('duplicate', data_get($report, 'totals.payment_event_coverage_status'));
        $this->assertSame('complete', data_get($report, 'totals.entitlement_coverage_status'));
        $this->assertSame('duplicate', data_get($report, 'totals.report_ready_coverage_status'));
        $this->assertSame(2, data_get($report, 'totals.duplicate_or_conflict_count'));

        $encoded = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach ([$attemptA, $attemptB, 'ord-commerce-a', 'ord-commerce-b', 'must-not-leak', 'result_ready', 'report_json', 'payload_json'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_missing_coverage_is_partial_without_becoming_a_duplicate_or_conflict(): void
    {
        $day = CarbonImmutable::parse('2026-08-10 08:00:00');
        $attemptId = $this->commerceAttempt($day, 'en', 'mbti_93');
        $this->insertOrder('ord-missing-coverage', $attemptId, 92, $day, 1299, $day->addMinutes(10));
        $this->setOrderAuthority('ord-missing-coverage', 'paid', 'web', 'stripe');
        $this->insertBenefitGrant($attemptId, 'ord-missing-coverage', 92, $day->addMinutes(15));
        $this->insertReportSnapshot($attemptId, 'ord-missing-coverage', 92, $day->addMinutes(20));

        $report = app(MeasurementCommerceTruthReadModel::class)->report('2026-08-10', '2026-08-10');

        $this->assertSame(1, data_get($report, 'totals.payment_succeeded_count'));
        $this->assertSame(1, data_get($report, 'totals.report_unlock_count'));
        $this->assertSame(1, data_get($report, 'totals.report_ready_count'));
        $this->assertSame('partial', data_get($report, 'totals.payment_event_coverage_status'));
        $this->assertSame('partial', data_get($report, 'totals.entitlement_coverage_status'));
        $this->assertSame('partial', data_get($report, 'totals.report_ready_coverage_status'));
        $this->assertSame(0, data_get($report, 'totals.duplicate_or_conflict_count'));
        $this->assertContains('payment_event_coverage_partial', $report['issues'] ?? []);
        $this->assertContains('entitlement_coverage_partial', $report['issues'] ?? []);
        $this->assertContains('report_ready_coverage_partial', $report['issues'] ?? []);
    }

    public function test_success_event_conflicting_with_authoritative_unpaid_order_is_reported_without_changing_truth(): void
    {
        $day = CarbonImmutable::parse('2026-08-10 08:00:00');
        $attemptId = $this->commerceAttempt($day, 'en', 'mbti_93');
        $this->insertOrder('ord-conflicting-event', $attemptId, 92, $day, 1299, null);
        $this->setOrderAuthority('ord-conflicting-event', 'created', 'web', 'stripe');
        $this->insertPaymentEvent('ord-conflicting-event', 92, $day->addMinutes(10));

        $report = app(MeasurementCommerceTruthReadModel::class)->report('2026-08-10', '2026-08-10');

        $this->assertSame(1, data_get($report, 'totals.order_created_count'));
        $this->assertSame(0, data_get($report, 'totals.payment_succeeded_count'));
        $this->assertSame('partial', data_get($report, 'totals.payment_event_coverage_status'));
        $this->assertSame(1, data_get($report, 'totals.duplicate_or_conflict_count'));
        $this->assertContains('payment_event_duplicate_or_conflict', $report['issues'] ?? []);
    }

    public function test_active_entitlement_is_an_as_of_stock_and_ready_status_is_the_only_report_authority(): void
    {
        $day = CarbonImmutable::parse('2026-08-10 08:00:00');
        $attemptActive = $this->commerceAttempt($day, 'en', 'mbti_93');
        $attemptPrior = $this->commerceAttempt($day->subDays(2), 'en', 'mbti_93');
        $attemptRevoked = $this->commerceAttempt($day, 'en', 'mbti_93');
        $attemptExpired = $this->commerceAttempt($day, 'en', 'mbti_93');

        foreach ([
            [$attemptActive, 'ord-active', $day],
            [$attemptPrior, 'ord-prior', $day->subDays(2)],
            [$attemptRevoked, 'ord-revoked', $day],
            [$attemptExpired, 'ord-expired', $day],
        ] as [$attemptId, $orderNo, $createdAt]) {
            $this->insertOrder($orderNo, $attemptId, 93, $createdAt, 1299, $createdAt->addMinutes(5));
            $this->setOrderAuthority($orderNo, 'paid', 'web', 'stripe');
            $this->insertBenefitGrant($attemptId, $orderNo, 93, $createdAt->addMinutes(10));
        }
        DB::table('benefit_grants')->where('attempt_id', $attemptRevoked)->update([
            'status' => 'revoked',
            'revoked_at' => $day->addHour(),
        ]);
        DB::table('benefit_grants')->where('attempt_id', $attemptExpired)->update([
            'status' => 'expired',
            'expires_at' => $day->addHour(),
        ]);

        $this->insertReportSnapshot($attemptActive, 'ord-active', 93, $day->addHours(2));
        $this->insertReportSnapshot($attemptRevoked, 'ord-revoked', 93, $day->addHours(2));
        DB::table('report_snapshots')->where('attempt_id', $attemptRevoked)->update([
            'status' => 'pending',
            'report_json' => json_encode(['private_report_marker' => 'payload-is-not-authority']),
        ]);
        $this->insertEvent(93, 'result_ready', $attemptRevoked, $day->addHours(3));

        $report = app(MeasurementCommerceTruthReadModel::class)->report('2026-08-10', '2026-08-10');

        $this->assertSame(1, data_get($report, 'totals.report_unlock_count'));
        $this->assertSame(2, data_get($report, 'totals.active_entitlement_count'));
        $this->assertSame(1, data_get($report, 'totals.report_ready_count'));
        $this->assertStringNotContainsString('payload-is-not-authority', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function test_safe_filters_apply_to_business_truth_without_cross_filter_event_conflicts(): void
    {
        $day = CarbonImmutable::parse('2026-08-10 08:00:00');
        $attemptA = $this->commerceAttempt($day, 'en', 'mbti_93');
        $attemptB = $this->commerceAttempt($day, 'zh-CN', 'mbti_60');
        $this->insertOrder('ord-filter-a', $attemptA, 94, $day, 1299, $day->addMinutes(5));
        $this->setOrderAuthority('ord-filter-a', 'paid', 'web', 'stripe');
        $this->insertBenefitGrant($attemptA, 'ord-filter-a', 94, $day->addMinutes(10));
        $this->insertEvent(94, 'report_unlock', $attemptA, $day->addMinutes(11));
        $this->insertOrder('ord-filter-b', $attemptB, 94, $day, 1299, $day->addMinutes(5));
        $this->setOrderAuthority('ord-filter-b', 'paid', 'alipay_miniapp', 'alipay');
        $this->insertBenefitGrant($attemptB, 'ord-filter-b', 94, $day->addMinutes(10));
        $this->insertEvent(94, 'report_unlock', $attemptB, $day->addMinutes(11));

        $report = app(MeasurementCommerceTruthReadModel::class)->report('2026-08-10', '2026-08-10', [
            'scale_code' => ['MBTI'],
            'form_code' => ['mbti_93'],
            'locale' => ['en'],
            'commerce_channel' => ['web'],
            'provider_class' => ['stripe'],
        ]);

        $this->assertSame(1, data_get($report, 'totals.order_created_count'));
        $this->assertSame(1, data_get($report, 'totals.payment_succeeded_count'));
        $this->assertSame(1, data_get($report, 'totals.report_unlock_count'));
        $this->assertSame('complete', data_get($report, 'totals.entitlement_coverage_status'));
        $this->assertSame(0, data_get($report, 'totals.duplicate_or_conflict_count'));

        $noMatch = app(MeasurementCommerceTruthReadModel::class)->report('2026-08-10', '2026-08-10', [
            'provider_class' => ['billing'],
        ]);
        $this->assertSame(0, data_get($noMatch, 'totals.order_created_count'));
        $this->assertSame(0, data_get($noMatch, 'totals.report_unlock_count'));
    }

    public function test_empty_invalid_and_missing_schema_states_are_explicit(): void
    {
        $empty = app(MeasurementCommerceTruthReadModel::class)->report('2026-08-10', '2026-08-10');
        $this->assertTrue($empty['ok'] ?? false);
        $this->assertSame('empty', $empty['status'] ?? null);

        $invalidFilter = app(MeasurementCommerceTruthReadModel::class)->report('2026-08-10', '2026-08-10', [
            'provider_class' => ['private-provider-name'],
        ]);
        $this->assertFalse($invalidFilter['ok'] ?? true);
        $this->assertContains('filter_invalid', $invalidFilter['issues'] ?? []);

        $invalidWindow = app(MeasurementCommerceTruthReadModel::class)->report('2026-08-11', '2026-08-10');
        $this->assertFalse($invalidWindow['ok'] ?? true);
        $this->assertContains('date_window_invalid', $invalidWindow['issues'] ?? []);

        Schema::drop('payment_events');
        $missing = app(MeasurementCommerceTruthReadModel::class)->report('2026-08-10', '2026-08-10');
        $this->assertFalse($missing['ok'] ?? true);
        $this->assertContains('payment_events_missing', $missing['issues'] ?? []);
    }

    public function test_command_is_read_only_and_returns_nonzero_for_invalid_dates(): void
    {
        $day = CarbonImmutable::parse('2026-08-10 08:00:00');
        $attemptId = $this->commerceAttempt($day, 'en', 'mbti_93');
        $this->insertOrder('ord-command', $attemptId, 95, $day, 1299, $day->addMinutes(5));
        $this->setOrderAuthority('ord-command', 'paid', 'web', 'stripe');
        $before = $this->databaseSnapshot();

        $exitCode = Artisan::call('analytics:measurement-commerce-truth-report', [
            '--from' => '2026-08-10',
            '--to' => '2026-08-10',
            '--scale' => ['MBTI'],
            '--form' => ['mbti_93'],
            '--locale' => ['en'],
            '--channel' => ['web'],
            '--provider' => ['stripe'],
            '--json' => true,
        ]);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"read_only": true', Artisan::output());
        foreach ([$attemptId, 'ord-command', 'payload_json', 'report_json', 'result_json', 'answers_json', 'scores_json'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, Artisan::output());
        }
        $this->assertSame($before, $this->databaseSnapshot());

        $invalidExitCode = Artisan::call('analytics:measurement-commerce-truth-report', [
            '--from' => 'not-a-date',
            '--to' => '2026-08-10',
            '--json' => true,
        ]);
        $this->assertSame(1, $invalidExitCode);
        $this->assertStringContainsString('from_date_invalid', Artisan::output());
        $this->assertSame($before, $this->databaseSnapshot());
    }

    private function commerceAttempt(CarbonImmutable $createdAt, string $locale, string $formCode): string
    {
        $attemptId = (string) Str::uuid();
        $this->insertAttempt($attemptId, 91, $locale, $createdAt, $createdAt->addMinutes(30));
        DB::table('attempts')->where('id', $attemptId)->update([
            'answers_summary_json' => json_encode(['meta' => ['form_code' => $formCode]]),
        ]);

        return $attemptId;
    }

    private function setOrderAuthority(
        string $orderNo,
        string $paymentState,
        string $channel,
        string $provider,
        ?CarbonImmutable $refundedAt = null,
    ): void {
        DB::table('orders')->where('order_no', $orderNo)->update([
            'status' => $paymentState,
            'payment_state' => $paymentState,
            'channel' => $channel,
            'provider' => $provider,
            'refunded_at' => $refundedAt,
        ]);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function databaseSnapshot(): array
    {
        $snapshot = [];
        foreach (['orders', 'payment_events', 'benefit_grants', 'report_snapshots', 'attempts', 'events'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy(DB::raw('1'))->get()->map(
                static fn (object $row): array => (array) $row,
            )->all();
        }

        return $snapshot;
    }
}
