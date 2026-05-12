<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerCanonical80CohortReadinessResult;
use App\Domain\Career\Audit\CareerCanonical80CohortReadinessRow;
use App\Domain\Career\Audit\CareerCanonicalEligibilitySeverity;
use App\Domain\Career\Audit\CareerCanonicalEligibilitySidecar;
use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use App\Domain\Career\Audit\CareerCanonicalExpansionManifestTrainGenerator;
use App\Domain\Career\Audit\CareerCanonicalExpansionManifestTrainIssue;
use PHPUnit\Framework\TestCase;

final class CareerCanonicalExpansionManifestTrainGeneratorTest extends TestCase
{
    public function test_rollback_group_uses_slug_list_not_batch_id(): void
    {
        $result = $this->generator()->generate($this->readiness(['actuaries', 'actors']), [2], ['en']);
        $manifest = $result->toArray()['batches'][0]['manifest'];

        $this->assertSame(['actuaries', 'actors'], $manifest['slugs']);
        $this->assertSame(['actuaries', 'actors'], $manifest['rollback_group']);
        $this->assertNotSame([$manifest['batch_id']], $manifest['rollback_group']);
    }

    public function test_generates_80_300_800_2786_staged_manifests_from_synthetic_readiness(): void
    {
        $result = $this->generator()->generate($this->readiness($this->slugs(2786)));

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertSame([80, 300, 800, 2786], $result->stageTargets);
        $this->assertSame([80, 300, 800, 2786], array_map(
            static fn (array $batch): int => count($batch['manifest']['slugs']),
            $result->toArray()['batches'],
        ));
        $this->assertFalse($result->publishingAllowed);
        $this->assertFalse($result->mutationAllowed);
    }

    public function test_blocks_if_readiness_missing(): void
    {
        $result = $this->generator()->generate(null, [80]);

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame([
            CareerCanonicalExpansionManifestTrainIssue::READINESS_MISSING => 1,
        ], $result->byReason());
        $this->assertSame([], $result->batches);
    }

    public function test_blocks_when_readiness_not_pass(): void
    {
        $result = $this->generator()->generate($this->readiness(['actuaries'], status: CareerCanonicalEligibilityStatus::BLOCKED), [1]);

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame(1, $result->byReason()[CareerCanonicalExpansionManifestTrainIssue::READINESS_NOT_PASS]);
    }

    public function test_blocks_when_stage_has_insufficient_ready_slugs(): void
    {
        $result = $this->generator()->generate($this->readiness(['actuaries']), [2]);

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame(1, $result->byReason()[CareerCanonicalExpansionManifestTrainIssue::INSUFFICIENT_READY_SLUGS]);
        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->toArray()['batches'][0]['readiness_gate']);
        $this->assertSame('blocked', $result->toArray()['batches'][0]['manifest']['rollout_state']);
    }

    public function test_sidecars_are_preserved_and_block_train_when_needed(): void
    {
        $result = $this->generator()->generate($this->readiness(
            ['actuaries'],
            sidecars: [new CareerCanonicalEligibilitySidecar(
                sidecarId: 'audit11-blocking-sidecar',
                title: 'Blocking sidecar',
                ownerRepo: 'fap-api',
                scopeRelation: 'inside_current_pr',
                introducedByCurrentPr: true,
                affectedSlugs: ['actuaries'],
                affectedLocales: [],
                evidence: [['source' => 'unit-test']],
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                nextGoal: 'Fix blocking sidecar',
                mayContinueTrain: false,
            )],
        ), [1]);

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame(1, $result->byReason()[CareerCanonicalExpansionManifestTrainIssue::SIDECAR_BLOCKS_TRAIN]);
        $this->assertSame('audit11-blocking-sidecar', $result->toArray()['sidecars'][0]['sidecar_id']);
    }

    public function test_result_to_array_is_stable(): void
    {
        $result = $this->generator()->generate($this->readiness(['actuaries', 'actors']), [2], ['en'])->toArray();

        $this->assertSame(
            <<<'JSON'
{
    "status": "pass",
    "train_kind": "career_canonical_expansion_manifest_train",
    "train_version": "career.canonical_expansion_manifest_train.v1",
    "readiness_status": "pass",
    "publishing_allowed": false,
    "mutation_allowed": false,
    "stage_targets": [
        2
    ],
    "ready_slug_count": 2,
    "by_reason": [],
    "batches": [
        {
            "stage": "canonical-2",
            "readiness_gate": "pass",
            "dry_run_only": true,
            "issues": [],
            "manifest": {
                "batch_id": "career-canonical-2",
                "batch_size": 2,
                "slugs": [
                    "actuaries",
                    "actors"
                ],
                "locales": [
                    "en"
                ],
                "projection_state": "published_candidate",
                "release_gate_required": true,
                "surface_equality_required": true,
                "rollback_group": [
                    "actuaries",
                    "actors"
                ],
                "rollout_state": "published_candidate",
                "candidate_route_semantics": "expected_pre_route",
                "candidate_release_gate_applicability": "not_applicable_before_promotion"
            }
        }
    ],
    "issues": [],
    "sidecars": []
}
JSON,
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function test_no_mutation_or_db_dependency_is_required(): void
    {
        $result = $this->generator()->generate($this->readiness(['actuaries']), [1]);

        $this->assertFalse($result->publishingAllowed);
        $this->assertFalse($result->mutationAllowed);
        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
    }

    private function generator(): CareerCanonicalExpansionManifestTrainGenerator
    {
        return new CareerCanonicalExpansionManifestTrainGenerator;
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    private function readiness(
        array $slugs,
        string $status = CareerCanonicalEligibilityStatus::PASS,
        array $sidecars = [],
    ): CareerCanonical80CohortReadinessResult {
        $rows = [];
        foreach ($slugs as $position => $slug) {
            $rows[] = new CareerCanonical80CohortReadinessRow(
                canonicalSlug: $slug,
                cohortPosition: $position + 1,
                selected: true,
                eligibilityStatus: CareerCanonicalEligibilityStatus::PASS,
                reasons: [],
                evidence: [['canonical_slug' => $slug]],
                issues: [],
            );
        }

        return new CareerCanonical80CohortReadinessResult(
            status: $status,
            targetCount: count($slugs),
            candidateCount: count($slugs),
            plannedCount: $status === CareerCanonicalEligibilityStatus::PASS ? count($slugs) : 0,
            eligibleCount: $status === CareerCanonicalEligibilityStatus::PASS ? count($slugs) : 0,
            blockedCount: $status === CareerCanonicalEligibilityStatus::PASS ? 0 : count($slugs),
            rolloutAllowed: $status === CareerCanonicalEligibilityStatus::PASS,
            candidateSlugs: $slugs,
            readySlugs: $status === CareerCanonicalEligibilityStatus::PASS ? $slugs : [],
            blockedSlugs: $status === CareerCanonicalEligibilityStatus::PASS ? [] : $slugs,
            rows: $rows,
            issues: [],
            sidecars: $sidecars,
        );
    }

    /**
     * @return list<string>
     */
    private function slugs(int $count): array
    {
        $slugs = [];
        for ($i = 1; $i <= $count; $i++) {
            $slugs[] = sprintf('slug-%04d', $i);
        }

        return $slugs;
    }
}
