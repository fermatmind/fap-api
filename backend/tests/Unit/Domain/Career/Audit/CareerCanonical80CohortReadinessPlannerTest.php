<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerCanonical80CohortReadinessIssue;
use App\Domain\Career\Audit\CareerCanonical80CohortReadinessPlanner;
use App\Domain\Career\Audit\CareerCanonicalEligibilityAuditRow;
use App\Domain\Career\Audit\CareerCanonicalEligibilityLayer;
use App\Domain\Career\Audit\CareerCanonicalEligibilityLayerStatus;
use App\Domain\Career\Audit\CareerCanonicalEligibilityReport;
use App\Domain\Career\Audit\CareerCanonicalEligibilityScope;
use App\Domain\Career\Audit\CareerCanonicalEligibilitySeverity;
use App\Domain\Career\Audit\CareerCanonicalEligibilitySidecar;
use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use PHPUnit\Framework\TestCase;

final class CareerCanonical80CohortReadinessPlannerTest extends TestCase
{
    public function test_generates_default_80_cohort_from_eligible_rows(): void
    {
        $result = $this->planner()->plan($this->report($this->eligibleRows(80)));

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertTrue($result->rolloutAllowed);
        $this->assertSame(80, $result->targetCount);
        $this->assertSame(80, $result->plannedCount);
        $this->assertSame('slug-080', $result->readySlugs[79]);
    }

    public function test_configured_candidate_set_controls_order_and_size(): void
    {
        $result = $this->planner()->plan(
            $this->report($this->eligibleRows(3)),
            candidateSlugs: ['slug-003', 'slug-001'],
            targetCount: 2,
        );

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertSame(['slug-003', 'slug-001'], $result->readySlugs);
        $this->assertSame(1, $result->rows[0]->cohortPosition);
        $this->assertSame(2, $result->rows[1]->cohortPosition);
    }

    public function test_blocks_when_eligibility_fails(): void
    {
        $result = $this->planner()->plan(
            $this->report([
                $this->row('actuaries', status: CareerCanonicalEligibilityStatus::BLOCKED, reasons: ['runtime_publish_state_not_published']),
            ]),
            candidateSlugs: ['actuaries'],
            targetCount: 1,
        );

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertFalse($result->rolloutAllowed);
        $this->assertSame([
            CareerCanonical80CohortReadinessIssue::COHORT_SIZE_NOT_MET => 1,
            CareerCanonical80CohortReadinessIssue::ELIGIBILITY_BLOCKED => 1,
        ], $result->byReason());
    }

    public function test_blocks_when_candidate_has_no_eligibility_row(): void
    {
        $result = $this->planner()->plan(
            $this->report([]),
            candidateSlugs: ['missing-slug'],
            targetCount: 1,
        );

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame([
            CareerCanonical80CohortReadinessIssue::COHORT_SIZE_NOT_MET => 1,
            CareerCanonical80CohortReadinessIssue::ELIGIBILITY_ROW_MISSING => 1,
        ], $result->byReason());
    }

    public function test_duplicate_candidate_slug_is_reported(): void
    {
        $result = $this->planner()->plan(
            $this->report([$this->row('actuaries')]),
            candidateSlugs: ['actuaries', 'actuaries'],
            targetCount: 1,
        );

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame(1, $result->byReason()[CareerCanonical80CohortReadinessIssue::DUPLICATE_CANDIDATE_SLUG]);
        $this->assertSame(['actuaries'], $result->readySlugs);
        $this->assertSame(['actuaries'], $result->blockedSlugs);
    }

    public function test_sidecars_are_included_and_block_rollout_when_train_blocking(): void
    {
        $result = $this->planner()->plan(
            $this->report(
                [$this->row('actuaries')],
                [new CareerCanonicalEligibilitySidecar(
                    sidecarId: 'audit10-current-pr-blocker',
                    title: 'Current PR blocker',
                    ownerRepo: 'fap-api',
                    scopeRelation: 'inside_current_pr',
                    introducedByCurrentPr: true,
                    affectedSlugs: ['actuaries'],
                    affectedLocales: [],
                    evidence: [['source' => 'unit-test']],
                    severity: CareerCanonicalEligibilitySeverity::HIGH,
                    nextGoal: 'Fix current PR blocker',
                    mayContinueTrain: false,
                )],
            ),
            candidateSlugs: ['actuaries'],
            targetCount: 1,
        );

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertFalse($result->rolloutAllowed);
        $this->assertSame(1, $result->byReason()[CareerCanonical80CohortReadinessIssue::SIDECAR_BLOCKS_TRAIN]);
        $this->assertSame('audit10-current-pr-blocker', $result->toArray()['sidecars'][0]['sidecar_id']);
    }

    public function test_result_to_array_is_stable(): void
    {
        $result = $this->planner()->plan(
            $this->report([$this->row('actuaries'), $this->row('actors')]),
            candidateSlugs: ['actuaries', 'actors'],
            targetCount: 2,
        )->toArray();

        $this->assertSame(
            <<<'JSON'
{
    "status": "pass",
    "target_count": 2,
    "candidate_count": 2,
    "planned_count": 2,
    "eligible_count": 2,
    "blocked_count": 0,
    "rollout_allowed": true,
    "candidate_slugs": [
        "actuaries",
        "actors"
    ],
    "ready_slugs": [
        "actuaries",
        "actors"
    ],
    "blocked_slugs": [],
    "by_reason": [],
    "rows": [
        {
            "canonical_slug": "actuaries",
            "cohort_position": 1,
            "selected": true,
            "eligibility_status": "pass",
            "reasons": [],
            "evidence": [
                {
                    "canonical_slug": "actuaries"
                },
                {
                    "locale": "en",
                    "overall_status": "pass",
                    "severity": "info"
                }
            ],
            "issues": []
        },
        {
            "canonical_slug": "actors",
            "cohort_position": 2,
            "selected": true,
            "eligibility_status": "pass",
            "reasons": [],
            "evidence": [
                {
                    "canonical_slug": "actors"
                },
                {
                    "locale": "en",
                    "overall_status": "pass",
                    "severity": "info"
                }
            ],
            "issues": []
        }
    ],
    "issues": [],
    "sidecars": []
}
JSON,
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function test_no_db_dependency_is_required(): void
    {
        $this->assertSame(
            CareerCanonicalEligibilityStatus::PASS,
            $this->planner()->plan($this->report($this->eligibleRows(1)), ['slug-001'], 1)->status,
        );
    }

    private function planner(): CareerCanonical80CohortReadinessPlanner
    {
        return new CareerCanonical80CohortReadinessPlanner;
    }

    /**
     * @return list<CareerCanonicalEligibilityAuditRow>
     */
    private function eligibleRows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = $this->row(sprintf('slug-%03d', $i));
        }

        return $rows;
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $rows
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    private function report(array $rows, array $sidecars = []): CareerCanonicalEligibilityReport
    {
        return new CareerCanonicalEligibilityReport(
            status: $rows === [] ? CareerCanonicalEligibilityStatus::BLOCKED : CareerCanonicalEligibilityStatus::PASS,
            scope: CareerCanonicalEligibilityScope::SLUGS,
            expectedOccupations: count(array_unique(array_map(static fn (CareerCanonicalEligibilityAuditRow $row): string => $row->slug, $rows))),
            auditedOccupations: count(array_unique(array_map(static fn (CareerCanonicalEligibilityAuditRow $row): string => $row->slug, $rows))),
            eligibleCount: count(array_filter($rows, static fn (CareerCanonicalEligibilityAuditRow $row): bool => $row->overallStatus === CareerCanonicalEligibilityStatus::PASS)),
            blockedCount: count(array_filter($rows, static fn (CareerCanonicalEligibilityAuditRow $row): bool => $row->overallStatus !== CareerCanonicalEligibilityStatus::PASS)),
            byReason: CareerCanonicalEligibilityReport::byReasonFromRows($rows),
            rows: $rows,
            sidecars: $sidecars,
        );
    }

    /**
     * @param  list<string>  $reasons
     */
    private function row(
        string $slug,
        string $status = CareerCanonicalEligibilityStatus::PASS,
        array $reasons = [],
    ): CareerCanonicalEligibilityAuditRow {
        $passStatus = new CareerCanonicalEligibilityLayerStatus(
            layer: CareerCanonicalEligibilityLayer::ENTITY,
            status: CareerCanonicalEligibilityStatus::PASS,
            reasons: [],
            evidence: [['slug' => $slug]],
            source: 'unit_test',
        );

        return new CareerCanonicalEligibilityAuditRow(
            slug: $slug,
            locale: 'en',
            sourceScope: CareerCanonicalEligibilityScope::SLUGS,
            entityStatus: $passStatus,
            baselineStatus: new CareerCanonicalEligibilityLayerStatus(CareerCanonicalEligibilityLayer::BASELINE, CareerCanonicalEligibilityStatus::PASS, [], [['slug' => $slug]], 'unit_test'),
            indexStatus: new CareerCanonicalEligibilityLayerStatus(CareerCanonicalEligibilityLayer::INDEX, CareerCanonicalEligibilityStatus::PASS, [], [['slug' => $slug]], 'unit_test'),
            runtimeStatus: new CareerCanonicalEligibilityLayerStatus(CareerCanonicalEligibilityLayer::RUNTIME, CareerCanonicalEligibilityStatus::PASS, [], [['slug' => $slug]], 'unit_test'),
            seoGeoStatus: new CareerCanonicalEligibilityLayerStatus(CareerCanonicalEligibilityLayer::SEO_GEO, CareerCanonicalEligibilityStatus::PASS, [], [['slug' => $slug]], 'unit_test'),
            surfaceStatus: new CareerCanonicalEligibilityLayerStatus(CareerCanonicalEligibilityLayer::SURFACE, CareerCanonicalEligibilityStatus::PASS, [], [['slug' => $slug]], 'unit_test'),
            safetyStatus: new CareerCanonicalEligibilityLayerStatus(CareerCanonicalEligibilityLayer::SAFETY, CareerCanonicalEligibilityStatus::PASS, [], [['read_only' => true]], 'unit_test'),
            overallStatus: $status,
            severity: $status === CareerCanonicalEligibilityStatus::PASS ? CareerCanonicalEligibilitySeverity::INFO : CareerCanonicalEligibilitySeverity::HIGH,
            reasons: $reasons,
            evidence: [['slug' => $slug]],
            sidecars: [],
        );
    }
}
