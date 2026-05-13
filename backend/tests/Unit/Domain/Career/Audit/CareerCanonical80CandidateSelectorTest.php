<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerCanonical80CandidateSelectionRow;
use App\Domain\Career\Audit\CareerCanonical80CandidateSelector;
use App\Domain\Career\Audit\CareerCanonicalEligibilityAuditRow;
use App\Domain\Career\Audit\CareerCanonicalEligibilityLayer;
use App\Domain\Career\Audit\CareerCanonicalEligibilityLayerStatus;
use App\Domain\Career\Audit\CareerCanonicalEligibilityReport;
use App\Domain\Career\Audit\CareerCanonicalEligibilityScope;
use App\Domain\Career\Audit\CareerCanonicalEligibilitySeverity;
use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use PHPUnit\Framework\TestCase;

final class CareerCanonical80CandidateSelectorTest extends TestCase
{
    public function test_selects_ready_candidates_without_running_readiness(): void
    {
        $report = $this->report([
            $this->row('actuaries', 'en'),
            $this->row('actors', 'en'),
        ]);

        $selection = (new CareerCanonical80CandidateSelector)->select($report, targetCount: 2);

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $selection->status);
        $this->assertTrue($selection->readinessCanRun);
        $this->assertSame(['actors', 'actuaries'], $selection->selectedSlugs);
        $this->assertSame(CareerCanonical80CandidateSelectionRow::STATUS_READY, $selection->rows[0]->candidateStatus);
        $this->assertSame(0, $selection->nearEligibleCount);
    }

    public function test_excludes_hard_blockers_and_reports_fewer_than_80_condition(): void
    {
        $report = $this->report([
            $this->row('surface-blocked', 'en', reasons: ['surface_context_missing']),
            $this->row('runtime-blocked', 'en', reasons: ['truth_row_missing']),
            $this->row('ready-one', 'en'),
        ]);

        $selection = (new CareerCanonical80CandidateSelector)->select($report, targetCount: 2);

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $selection->status);
        $this->assertFalse($selection->readinessCanRun);
        $this->assertSame(['ready-one'], $selection->selectedSlugs);
        $this->assertSame(2, $selection->excludedCount);
        $this->assertContains('surface-blocked', $selection->excludedSlugs);
        $runtimeBlocked = array_values(array_filter(
            $selection->rows,
            static fn (CareerCanonical80CandidateSelectionRow $row): bool => $row->canonicalSlug === 'runtime-blocked'
        ))[0];
        $this->assertContains('truth_row_missing', $runtimeBlocked->hardBlockers);
    }

    public function test_near_eligible_rows_are_ranked_after_ready_rows(): void
    {
        $report = $this->report([
            $this->row('near-one', 'en', reasons: ['manual_review_needed']),
            $this->row('ready-one', 'en'),
            $this->row('near-one', 'zh'),
        ]);

        $selection = (new CareerCanonical80CandidateSelector)->select($report, targetCount: 2);

        $this->assertSame(['ready-one'], $selection->selectedSlugs);
        $this->assertSame(['near-one'], $selection->nearEligibleSlugs);
        $this->assertSame(CareerCanonical80CandidateSelectionRow::STATUS_READY, $selection->rows[0]->candidateStatus);
        $this->assertSame(CareerCanonical80CandidateSelectionRow::STATUS_NEAR_ELIGIBLE, $selection->rows[1]->candidateStatus);
        $this->assertGreaterThan($selection->rows[1]->score, $selection->rows[0]->score);
    }

    public function test_result_to_array_is_stable(): void
    {
        $report = $this->report([
            $this->row('actuaries', 'en'),
            $this->row('blocked', 'en', reasons: ['index_state_missing']),
        ]);

        $payload = (new CareerCanonical80CandidateSelector)->select($report, targetCount: 1)->toArray();

        $this->assertSame('career_80_candidate_selection.v1', $payload['schema_version']);
        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $payload['status']);
        $this->assertSame(1, $payload['target_count']);
        $this->assertSame(2, $payload['candidate_count']);
        $this->assertSame(1, $payload['selected_count']);
        $this->assertSame(1, $payload['excluded_count']);
        $this->assertSame(['actuaries'], $payload['selected_slugs']);
        $this->assertSame('excluded_hard_blocker', $payload['rows'][1]['candidate_status']);
    }

    public function test_custom_hard_blockers_can_exclude_policy_reasons(): void
    {
        $report = $this->report([
            $this->row('policy-wait', 'en', reasons: ['policy_wait']),
        ]);

        $selection = (new CareerCanonical80CandidateSelector)->select($report, targetCount: 1, hardBlockers: ['policy_wait']);

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $selection->status);
        $this->assertSame(['policy-wait'], $selection->excludedSlugs);
        $this->assertSame(['policy_wait'], $selection->rows[0]->hardBlockers);
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $rows
     */
    private function report(array $rows): CareerCanonicalEligibilityReport
    {
        return new CareerCanonicalEligibilityReport(
            status: CareerCanonicalEligibilityStatus::BLOCKED,
            scope: CareerCanonicalEligibilityScope::ALL,
            expectedOccupations: count(array_unique(array_map(static fn (CareerCanonicalEligibilityAuditRow $row): string => $row->slug, $rows))),
            auditedOccupations: count(array_unique(array_map(static fn (CareerCanonicalEligibilityAuditRow $row): string => $row->slug, $rows))),
            eligibleCount: count(array_filter($rows, static fn (CareerCanonicalEligibilityAuditRow $row): bool => $row->overallStatus === CareerCanonicalEligibilityStatus::PASS)),
            blockedCount: count(array_filter($rows, static fn (CareerCanonicalEligibilityAuditRow $row): bool => $row->overallStatus !== CareerCanonicalEligibilityStatus::PASS)),
            byReason: CareerCanonicalEligibilityReport::byReasonFromRows($rows),
            rows: $rows,
        );
    }

    /**
     * @param  list<string>  $reasons
     */
    private function row(string $slug, string $locale, array $reasons = []): CareerCanonicalEligibilityAuditRow
    {
        $status = $reasons === [] ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED;
        $severity = $reasons === [] ? CareerCanonicalEligibilitySeverity::INFO : CareerCanonicalEligibilitySeverity::HIGH;

        return new CareerCanonicalEligibilityAuditRow(
            slug: $slug,
            locale: $locale,
            sourceScope: CareerCanonicalEligibilityScope::ALL,
            entityStatus: $this->layer(CareerCanonicalEligibilityLayer::ENTITY, $this->layerStatus($reasons, 'occupation_missing')),
            baselineStatus: $this->layer(CareerCanonicalEligibilityLayer::BASELINE, $this->layerStatus($reasons, 'zh_baseline_missing')),
            indexStatus: $this->layer(CareerCanonicalEligibilityLayer::INDEX, $this->layerStatus($reasons, 'index_state_missing')),
            runtimeStatus: $this->layer(CareerCanonicalEligibilityLayer::RUNTIME, $this->layerStatus($reasons, 'truth_row_missing')),
            seoGeoStatus: $this->layer(CareerCanonicalEligibilityLayer::SEO_GEO, $this->layerStatus($reasons, 'structured_data_missing')),
            surfaceStatus: $this->layer(CareerCanonicalEligibilityLayer::SURFACE, $this->layerStatus($reasons, 'surface_context_missing')),
            safetyStatus: $this->layer(CareerCanonicalEligibilityLayer::SAFETY, CareerCanonicalEligibilityStatus::PASS),
            overallStatus: $status,
            severity: $severity,
            reasons: $reasons,
            evidence: [['slug' => $slug]],
            sidecars: [],
        );
    }

    private function layerStatus(array $reasons, string $reason): string
    {
        return in_array($reason, $reasons, true)
            ? CareerCanonicalEligibilityStatus::BLOCKED
            : CareerCanonicalEligibilityStatus::PASS;
    }

    private function layer(string $layer, string $status): CareerCanonicalEligibilityLayerStatus
    {
        return new CareerCanonicalEligibilityLayerStatus(
            layer: $layer,
            status: $status,
            reasons: $status === CareerCanonicalEligibilityStatus::PASS ? [] : ['unit_blocker'],
            evidence: [['layer' => $layer]],
            source: 'unit_test',
        );
    }
}
