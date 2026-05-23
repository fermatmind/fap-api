<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerCanonicalEligibilityAuditRow;
use App\Domain\Career\Audit\CareerCanonicalEligibilityCheckProtocol;
use App\Domain\Career\Audit\CareerCanonicalEligibilityLayer;
use App\Domain\Career\Audit\CareerCanonicalEligibilityLayerStatus;
use App\Domain\Career\Audit\CareerCanonicalEligibilityReport;
use App\Domain\Career\Audit\CareerCanonicalEligibilityScope;
use App\Domain\Career\Audit\CareerCanonicalEligibilitySeverity;
use App\Domain\Career\Audit\CareerCanonicalEligibilitySidecar;
use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CareerCanonicalEligibilityAuditSchemaTest extends TestCase
{
    public function test_layer_status_serializes_to_stable_json(): void
    {
        $status = new CareerCanonicalEligibilityLayerStatus(
            layer: CareerCanonicalEligibilityLayer::ENTITY,
            status: CareerCanonicalEligibilityStatus::PASS,
            source: 'db',
        );

        $this->assertSame(
            <<<'JSON'
{
    "layer": "entity",
    "status": "pass",
    "reasons": [],
    "evidence": [],
    "source": "db"
}
JSON,
            json_encode($status->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function test_sidecar_serializes_to_stable_json(): void
    {
        $sidecar = $this->externalSidecar();

        $this->assertSame(
            <<<'JSON'
{
    "sidecar_id": "AUDIT-1-EXTERNAL-FAP-WEB-LIVE-ACCEPTANCE",
    "title": "fap-web live HTML deploy pending",
    "owner_repo": "fap-web",
    "scope_relation": "external_to_current_pr",
    "introduced_by_current_pr": false,
    "affected_slugs": [],
    "affected_locales": [],
    "evidence": [
        "AUDIT-1 is schema-only and does not deploy fap-web."
    ],
    "severity": "blocker_for_full_2786_claim",
    "next_goal": "Complete frontend deployment and live acceptance outside AUDIT-1.",
    "may_continue_train": true
}
JSON,
            json_encode($sidecar->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function test_complete_external_sidecar_can_continue_train(): void
    {
        $this->assertTrue($this->externalSidecar()->canContinueTrain());
    }

    public function test_current_pr_blocker_cannot_continue_train(): void
    {
        $sidecar = new CareerCanonicalEligibilitySidecar(
            sidecarId: 'AUDIT-1-CURRENT-PR-BLOCKER',
            title: 'Current PR introduced schema blocker',
            ownerRepo: CareerCanonicalEligibilitySidecar::OWNER_REPO_FAP_API,
            scopeRelation: CareerCanonicalEligibilitySidecar::SCOPE_RELATION_INSIDE,
            introducedByCurrentPr: true,
            affectedSlugs: [],
            affectedLocales: [],
            evidence: ['The current PR introduced the blocker.'],
            severity: CareerCanonicalEligibilitySeverity::HIGH,
            nextGoal: 'Fix inside AUDIT-1 before continuing.',
            mayContinueTrain: false,
        );

        $this->assertFalse($sidecar->canContinueTrain());
    }

    public function test_missing_evidence_fails_validation_for_non_info_sidecar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('evidence is required');

        new CareerCanonicalEligibilitySidecar(
            sidecarId: 'AUDIT-1-MISSING-EVIDENCE',
            title: 'Missing evidence',
            ownerRepo: CareerCanonicalEligibilitySidecar::OWNER_REPO_EXTERNAL,
            scopeRelation: CareerCanonicalEligibilitySidecar::SCOPE_RELATION_EXTERNAL,
            introducedByCurrentPr: false,
            affectedSlugs: [],
            affectedLocales: [],
            evidence: [],
            severity: CareerCanonicalEligibilitySeverity::LOW,
            nextGoal: 'Add evidence.',
            mayContinueTrain: true,
        );
    }

    public function test_audit_row_serializes_with_all_seven_layers(): void
    {
        $row = $this->passingRow();

        $this->assertSame([
            'slug',
            'locale',
            'source_scope',
            'entity_status',
            'baseline_status',
            'index_status',
            'runtime_status',
            'seo_geo_status',
            'surface_status',
            'safety_status',
            'overall_status',
            'severity',
            'reasons',
            'evidence',
            'sidecars',
        ], array_keys($row->toArray()));

        $this->assertSame(CareerCanonicalEligibilityLayer::ENTITY, $row->toArray()['entity_status']['layer']);
        $this->assertSame(CareerCanonicalEligibilityLayer::BASELINE, $row->toArray()['baseline_status']['layer']);
        $this->assertSame(CareerCanonicalEligibilityLayer::INDEX, $row->toArray()['index_status']['layer']);
        $this->assertSame(CareerCanonicalEligibilityLayer::RUNTIME, $row->toArray()['runtime_status']['layer']);
        $this->assertSame(CareerCanonicalEligibilityLayer::SEO_GEO, $row->toArray()['seo_geo_status']['layer']);
        $this->assertSame(CareerCanonicalEligibilityLayer::SURFACE, $row->toArray()['surface_status']['layer']);
        $this->assertSame(CareerCanonicalEligibilityLayer::SAFETY, $row->toArray()['safety_status']['layer']);
    }

    public function test_report_serializes_expected_top_level_schema(): void
    {
        $report = new CareerCanonicalEligibilityReport(
            status: CareerCanonicalEligibilityStatus::PASS,
            scope: CareerCanonicalEligibilityScope::ALL,
            expectedOccupations: 2786,
            auditedOccupations: 2786,
            eligibleCount: 0,
            blockedCount: 0,
        );

        $this->assertSame([
            'status' => 'pass',
            'scope' => 'all',
            'expected_occupations' => 2786,
            'audited_occupations' => 2786,
            'eligible_count' => 0,
            'blocked_count' => 0,
            'by_reason' => [],
            'rows' => [],
            'sidecars' => [],
        ], $report->toArray());
    }

    public function test_report_can_identify_train_blocking_sidecars(): void
    {
        $blocking = new CareerCanonicalEligibilitySidecar(
            sidecarId: 'AUDIT-1-CURRENT-PR-BLOCKER',
            title: 'Current PR introduced schema blocker',
            ownerRepo: CareerCanonicalEligibilitySidecar::OWNER_REPO_FAP_API,
            scopeRelation: CareerCanonicalEligibilitySidecar::SCOPE_RELATION_INSIDE,
            introducedByCurrentPr: true,
            affectedSlugs: [],
            affectedLocales: [],
            evidence: ['The current PR introduced the blocker.'],
            severity: CareerCanonicalEligibilitySeverity::HIGH,
            nextGoal: 'Fix inside AUDIT-1 before continuing.',
            mayContinueTrain: false,
        );
        $report = new CareerCanonicalEligibilityReport(
            status: CareerCanonicalEligibilityStatus::BLOCKED,
            scope: CareerCanonicalEligibilityScope::BATCH,
            expectedOccupations: 58,
            auditedOccupations: 58,
            eligibleCount: 57,
            blockedCount: 1,
            sidecars: [$this->externalSidecar(), $blocking],
        );

        $this->assertFalse($report->canContinueTrain());
        $this->assertSame([$blocking], $report->sidecarsThatBlockTrain());
    }

    public function test_schema_distinguishes_external_to_current_pr_vs_inside_current_pr(): void
    {
        $external = $this->externalSidecar();
        $inside = new CareerCanonicalEligibilitySidecar(
            sidecarId: 'AUDIT-1-INSIDE-LOW',
            title: 'Inside current PR low severity note',
            ownerRepo: CareerCanonicalEligibilitySidecar::OWNER_REPO_FAP_API,
            scopeRelation: CareerCanonicalEligibilitySidecar::SCOPE_RELATION_INSIDE,
            introducedByCurrentPr: false,
            affectedSlugs: [],
            affectedLocales: [],
            evidence: ['Scoped note has evidence.'],
            severity: CareerCanonicalEligibilitySeverity::LOW,
            nextGoal: 'Resolve within current PR if needed.',
            mayContinueTrain: true,
        );

        $this->assertSame('external_to_current_pr', $external->scopeRelation);
        $this->assertSame('inside_current_pr', $inside->scopeRelation);
    }

    public function test_severity_values_are_stable(): void
    {
        $this->assertSame([
            'info',
            'low',
            'medium',
            'high',
            'blocker_for_publication',
            'blocker_for_full_2786_claim',
        ], CareerCanonicalEligibilitySeverity::values());
    }

    public function test_pending_check_state_is_not_treated_as_blocker(): void
    {
        $this->assertSame(
            CareerCanonicalEligibilityCheckProtocol::ACTION_WAIT_OR_POLL,
            CareerCanonicalEligibilityCheckProtocol::actionForState(CareerCanonicalEligibilityCheckProtocol::STATE_PENDING)
        );
        $this->assertFalse(CareerCanonicalEligibilityCheckProtocol::isImmediateStop(CareerCanonicalEligibilityCheckProtocol::STATE_PENDING));
        $this->assertSame(
            CareerCanonicalEligibilityCheckProtocol::TRAIN_CONTINUE_WAITING_FOR_CHECKS,
            CareerCanonicalEligibilityCheckProtocol::trainContinueForState(CareerCanonicalEligibilityCheckProtocol::STATE_PENDING)
        );
    }

    public function test_failed_check_state_requires_inspect_fix_loop_and_immediate_stop(): void
    {
        $this->assertSame(
            CareerCanonicalEligibilityCheckProtocol::ACTION_INSPECT_FAILURE,
            CareerCanonicalEligibilityCheckProtocol::actionForState(CareerCanonicalEligibilityCheckProtocol::STATE_FAILED)
        );
        $this->assertTrue(CareerCanonicalEligibilityCheckProtocol::isImmediateStop(CareerCanonicalEligibilityCheckProtocol::STATE_FAILED));
    }

    public function test_current_pr_introduced_failure_requires_may_continue_train_false(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('introduced by the current PR cannot continue');

        new CareerCanonicalEligibilitySidecar(
            sidecarId: 'AUDIT-1-CURRENT-PR-FAILURE',
            title: 'Current PR introduced CI failure',
            ownerRepo: CareerCanonicalEligibilitySidecar::OWNER_REPO_FAP_API,
            scopeRelation: CareerCanonicalEligibilitySidecar::SCOPE_RELATION_INSIDE,
            introducedByCurrentPr: true,
            affectedSlugs: [],
            affectedLocales: [],
            evidence: ['Failed check was introduced by this PR.'],
            severity: CareerCanonicalEligibilitySeverity::HIGH,
            nextGoal: 'Fix in AUDIT-1 and push a follow-up commit.',
            mayContinueTrain: true,
        );
    }

    public function test_external_pre_existing_failure_can_be_sidecar_continued_when_evidence_exists(): void
    {
        $sidecar = CareerCanonicalEligibilitySidecar::fromArray([
            'sidecar_id' => 'AUDIT-1-EXTERNAL-PRE-EXISTING-CHECK',
            'title' => 'Pre-existing external check failure',
            'owner_repo' => 'external',
            'scope_relation' => 'external_to_current_pr',
            'introduced_by_current_pr' => false,
            'affected_slugs' => [],
            'affected_locales' => [],
            'evidence' => ['The failure predates AUDIT-1 and does not touch schema scope.'],
            'severity' => 'medium',
            'next_goal' => 'Track externally while AUDIT-1 continues.',
            'may_continue_train' => true,
        ]);

        $this->assertTrue($sidecar->canContinueTrain());
    }

    private function passingRow(): CareerCanonicalEligibilityAuditRow
    {
        return new CareerCanonicalEligibilityAuditRow(
            slug: 'actuaries',
            locale: 'en',
            sourceScope: CareerCanonicalEligibilityScope::BATCH,
            entityStatus: $this->layer(CareerCanonicalEligibilityLayer::ENTITY),
            baselineStatus: $this->layer(CareerCanonicalEligibilityLayer::BASELINE),
            indexStatus: $this->layer(CareerCanonicalEligibilityLayer::INDEX),
            runtimeStatus: $this->layer(CareerCanonicalEligibilityLayer::RUNTIME),
            seoGeoStatus: $this->layer(CareerCanonicalEligibilityLayer::SEO_GEO),
            surfaceStatus: $this->layer(CareerCanonicalEligibilityLayer::SURFACE),
            safetyStatus: $this->layer(CareerCanonicalEligibilityLayer::SAFETY),
            overallStatus: CareerCanonicalEligibilityStatus::PASS,
            severity: CareerCanonicalEligibilitySeverity::INFO,
        );
    }

    private function layer(string $layer): CareerCanonicalEligibilityLayerStatus
    {
        return new CareerCanonicalEligibilityLayerStatus(
            layer: $layer,
            status: CareerCanonicalEligibilityStatus::PASS,
            source: 'schema_fixture',
        );
    }

    private function externalSidecar(): CareerCanonicalEligibilitySidecar
    {
        return new CareerCanonicalEligibilitySidecar(
            sidecarId: 'AUDIT-1-EXTERNAL-FAP-WEB-LIVE-ACCEPTANCE',
            title: 'fap-web live HTML deploy pending',
            ownerRepo: CareerCanonicalEligibilitySidecar::OWNER_REPO_FAP_WEB,
            scopeRelation: CareerCanonicalEligibilitySidecar::SCOPE_RELATION_EXTERNAL,
            introducedByCurrentPr: false,
            affectedSlugs: [],
            affectedLocales: [],
            evidence: ['AUDIT-1 is schema-only and does not deploy fap-web.'],
            severity: CareerCanonicalEligibilitySeverity::BLOCKER_FOR_FULL_2786_CLAIM,
            nextGoal: 'Complete frontend deployment and live acceptance outside AUDIT-1.',
            mayContinueTrain: true,
        );
    }
}
