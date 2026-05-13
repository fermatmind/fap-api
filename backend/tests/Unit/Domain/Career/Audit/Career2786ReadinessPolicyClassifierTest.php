<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\Career2786ReadinessPolicyClassifier;
use App\Domain\Career\Audit\Career2786ReadinessPolicyIssue;
use App\Domain\Career\Audit\Career2786ReadinessPolicyResult;
use App\Domain\Career\Audit\CareerCanonicalEligibilityAuditRow;
use App\Domain\Career\Audit\CareerCanonicalEligibilityLayer;
use App\Domain\Career\Audit\CareerCanonicalEligibilityLayerStatus;
use App\Domain\Career\Audit\CareerCanonicalEligibilityReport;
use App\Domain\Career\Audit\CareerCanonicalEligibilityScope;
use App\Domain\Career\Audit\CareerCanonicalEligibilitySeverity;
use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use PHPUnit\Framework\TestCase;

final class Career2786ReadinessPolicyClassifierTest extends TestCase
{
    public function test_surface_unverified_is_deferred_for_non_candidate_rows(): void
    {
        $result = $this->classify([
            $this->row('actuaries', 'en', ['surface_unverified', 'surface_artifact_missing']),
        ]);

        $this->assertSame(1, $result->byClassification[Career2786ReadinessPolicyIssue::DEFERRED_UNTIL_CANDIDATE]);
        $this->assertSame(['actuaries'], $result->nearEligibleSlugs);
        $this->assertSame(['surface_artifact_missing', 'surface_unverified'], $result->rows[0]->deferredUntilCandidateReasons);
        $this->assertFalse($result->rows[0]->requiresApproval);
        $this->assertFalse($result->rows[0]->blocks80Readiness);
    }

    public function test_surface_unverified_remains_required_for_selected_candidate_rows(): void
    {
        $report = $this->report([
            $this->row('actuaries', 'en', ['surface_unverified']),
        ]);

        $result = (new Career2786ReadinessPolicyClassifier)->classify($report, selectedCandidateSlugs: ['actuaries']);

        $this->assertSame(Career2786ReadinessPolicyIssue::APPROVAL_GATED, $result->rows[0]->classification);
        $this->assertSame(['surface_unverified'], $result->rows[0]->approvalGatedReasons);
        $this->assertTrue($result->rows[0]->requiresApproval);
        $this->assertFalse($result->rows[0]->blocks80Readiness);
    }

    public function test_sitemap_and_llms_expected_not_ready_are_policy_states_until_candidate_stage(): void
    {
        $result = $this->classify([
            $this->row('policy-wait', 'en', ['sitemap_expected_not_ready', 'llms_expected_not_ready', 'llms_full_expected_not_ready']),
        ]);

        $this->assertSame(Career2786ReadinessPolicyIssue::EXPECTED_NOT_READY, $result->rows[0]->classification);
        $this->assertSame([
            'llms_expected_not_ready',
            'llms_full_expected_not_ready',
            'sitemap_expected_not_ready',
        ], $result->rows[0]->expectedNotReadyReasons);
        $this->assertContains('policy-wait', $result->nearEligibleSlugs);
    }

    public function test_sitemap_expected_not_ready_blocks_selected_publication_candidate(): void
    {
        $report = $this->report([
            $this->row('policy-wait', 'en', ['sitemap_expected_not_ready']),
        ]);

        $result = (new Career2786ReadinessPolicyClassifier)->classify($report, selectedCandidateSlugs: ['policy-wait']);

        $this->assertSame(Career2786ReadinessPolicyIssue::APPROVAL_GATED, $result->rows[0]->classification);
        $this->assertSame(['sitemap_expected_not_ready'], $result->rows[0]->approvalGatedReasons);
        $this->assertTrue($result->rows[0]->requiresApproval);
    }

    public function test_index_and_entity_gaps_remain_remediation_required(): void
    {
        $result = $this->classify([
            $this->row('index-gap', 'en', ['index_state_missing']),
            $this->row('missing-occupation', 'en', ['occupation_missing']),
            $this->row('field-gap', 'en', ['entity_field_missing']),
        ]);

        $this->assertSame(3, $result->byClassification[Career2786ReadinessPolicyIssue::REMEDIATION_REQUIRED]);
        $this->assertSame(['field-gap', 'index-gap', 'missing-occupation'], $result->candidateBlockingSlugs);
        $this->assertContains('resolve_index_entity_remediation_required', $result->candidateCohortPrerequisites);
        $this->assertSame(['index_state_missing'], $result->rows[1]->approvalGatedReasons);
    }

    public function test_near_eligible_classification_excludes_entity_and_index_blockers(): void
    {
        $result = $this->classify([
            $this->row('near-one', 'en', ['surface_unverified', 'sitemap_expected_not_ready']),
            $this->row('index-gap', 'en', ['index_state_missing']),
        ], targetCount: 2);

        $this->assertSame(['near-one'], $result->nearEligibleSlugs);
        $this->assertSame(['index-gap'], $result->candidateBlockingSlugs);
        $this->assertFalse($result->readinessCanRun);
        $this->assertContains('increase_near_eligible_candidates_to_2', $result->candidateCohortPrerequisites);
    }

    public function test_policy_summary_is_stable(): void
    {
        $summary = $this->classify([
            $this->row('near-one', 'en', ['surface_unverified']),
            $this->row('ready-one', 'en'),
            $this->row('index-gap', 'en', ['index_state_missing']),
        ], targetCount: 2)->summary();

        $this->assertSame('career_2786_readiness_policy.v1', $summary['schema_version']);
        $this->assertFalse($summary['readiness_can_run']);
        $this->assertSame(1, $summary['remediation_required_count']);
        $this->assertSame(1, $summary['deferred_until_candidate_count']);
        $this->assertSame(1, $summary['near_eligible_count']);
        $this->assertSame(1, $summary['eligible_candidate_count']);
        $this->assertContains('candidate_only_surface_verification_after_80_candidates_exist', $summary['recommended_order']);
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $rows
     */
    private function classify(array $rows, int $targetCount = 80): Career2786ReadinessPolicyResult
    {
        return (new Career2786ReadinessPolicyClassifier)->classify($this->report($rows), targetCount: $targetCount);
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $rows
     */
    private function report(array $rows): CareerCanonicalEligibilityReport
    {
        $blocked = count(array_filter($rows, static fn (CareerCanonicalEligibilityAuditRow $row): bool => $row->overallStatus !== CareerCanonicalEligibilityStatus::PASS));

        return new CareerCanonicalEligibilityReport(
            status: $blocked === 0 ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED,
            scope: CareerCanonicalEligibilityScope::ALL,
            expectedOccupations: count(array_unique(array_map(static fn (CareerCanonicalEligibilityAuditRow $row): string => $row->slug, $rows))),
            auditedOccupations: count(array_unique(array_map(static fn (CareerCanonicalEligibilityAuditRow $row): string => $row->slug, $rows))),
            eligibleCount: count($rows) - $blocked,
            blockedCount: $blocked,
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

        return new CareerCanonicalEligibilityAuditRow(
            slug: $slug,
            locale: $locale,
            sourceScope: CareerCanonicalEligibilityScope::ALL,
            entityStatus: $this->layer(CareerCanonicalEligibilityLayer::ENTITY, $this->layerStatus($reasons, ['occupation_missing', 'entity_field_missing']), array_values(array_intersect($reasons, ['occupation_missing', 'entity_field_missing']))),
            baselineStatus: $this->layer(CareerCanonicalEligibilityLayer::BASELINE, CareerCanonicalEligibilityStatus::PASS),
            indexStatus: $this->layer(CareerCanonicalEligibilityLayer::INDEX, $this->layerStatus($reasons, ['index_state_missing']), array_values(array_intersect($reasons, ['index_state_missing']))),
            runtimeStatus: $this->layer(CareerCanonicalEligibilityLayer::RUNTIME, CareerCanonicalEligibilityStatus::PASS),
            seoGeoStatus: $this->layer(CareerCanonicalEligibilityLayer::SEO_GEO, $this->layerStatus($reasons, ['sitemap_expected_not_ready', 'llms_expected_not_ready', 'llms_full_expected_not_ready']), array_values(array_intersect($reasons, ['sitemap_expected_not_ready', 'llms_expected_not_ready', 'llms_full_expected_not_ready']))),
            surfaceStatus: $this->layer(CareerCanonicalEligibilityLayer::SURFACE, $this->layerStatus($reasons, ['surface_unverified', 'surface_artifact_missing']), array_values(array_intersect($reasons, ['surface_unverified', 'surface_artifact_missing']))),
            safetyStatus: $this->layer(CareerCanonicalEligibilityLayer::SAFETY, CareerCanonicalEligibilityStatus::PASS),
            overallStatus: $status,
            severity: $status === CareerCanonicalEligibilityStatus::PASS ? CareerCanonicalEligibilitySeverity::INFO : CareerCanonicalEligibilitySeverity::HIGH,
            reasons: $reasons,
            evidence: [['slug' => $slug]],
        );
    }

    /**
     * @param  list<string>  $matches
     */
    private function layerStatus(array $reasons, array $matches): string
    {
        return array_intersect($reasons, $matches) === []
            ? CareerCanonicalEligibilityStatus::PASS
            : CareerCanonicalEligibilityStatus::BLOCKED;
    }

    /**
     * @param  list<string>  $reasons
     */
    private function layer(string $layer, string $status, array $reasons = []): CareerCanonicalEligibilityLayerStatus
    {
        return new CareerCanonicalEligibilityLayerStatus(
            layer: $layer,
            status: $status,
            reasons: $reasons,
            evidence: [['layer' => $layer]],
            source: 'unit_test',
        );
    }
}
