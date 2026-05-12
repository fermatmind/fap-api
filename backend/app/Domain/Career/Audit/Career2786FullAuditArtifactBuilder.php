<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class Career2786FullAuditArtifactBuilder
{
    public function build(
        CareerCanonicalEligibilityReport $eligibilityReport,
        ?CareerCanonical80CohortReadinessResult $readiness = null,
        ?CareerCanonicalExpansionManifestTrainResult $manifestTrain = null,
        ?CareerBatchLiveAcceptanceV2Result $batchAcceptance = null,
        int $totalExpected = 2786,
    ): Career2786FullAuditArtifact {
        $sections = [];
        $sidecars = $eligibilityReport->sidecars;
        $byReason = $eligibilityReport->byReason;
        $byLayer = $this->byLayer($eligibilityReport->rows);

        $sections[] = ['section' => 'canonical_eligibility', 'status' => $eligibilityReport->status, 'summary' => $eligibilityReport->toArray()];

        if ($readiness !== null) {
            $sections[] = ['section' => '80_cohort_readiness', 'status' => $readiness->status, 'summary' => $readiness->toArray()];
            $byReason = $this->mergeReasons($byReason, $readiness->byReason());
            $sidecars = [...$sidecars, ...$readiness->sidecars];
        }

        if ($manifestTrain !== null) {
            $sections[] = ['section' => 'manifest_train', 'status' => $manifestTrain->status, 'summary' => $manifestTrain->toArray()];
            $byReason = $this->mergeReasons($byReason, $manifestTrain->byReason());
            $sidecars = [...$sidecars, ...$manifestTrain->sidecars];
        }

        if ($batchAcceptance !== null) {
            $sections[] = ['section' => 'batch_live_acceptance_v2', 'status' => $batchAcceptance->status, 'summary' => $batchAcceptance->toArray()];
            $byReason = $this->mergeReasons($byReason, $batchAcceptance->byReason());
            if ($batchAcceptance->status !== CareerCanonicalEligibilityStatus::PASS) {
                $byLayer['surface']['blocked'] = ($byLayer['surface']['blocked'] ?? 0) + 1;
            }
        }

        ksort($byReason);
        ksort($byLayer);

        $readyForExpansion = $eligibilityReport->status === CareerCanonicalEligibilityStatus::PASS
            && $eligibilityReport->expectedOccupations === $totalExpected
            && $eligibilityReport->auditedOccupations === $totalExpected
            && $eligibilityReport->blockedCount === 0
            && ($readiness === null || $readiness->status === CareerCanonicalEligibilityStatus::PASS)
            && ($manifestTrain === null || $manifestTrain->status === CareerCanonicalEligibilityStatus::PASS)
            && ($batchAcceptance === null || $batchAcceptance->status === CareerCanonicalEligibilityStatus::PASS);

        return new Career2786FullAuditArtifact(
            status: $readyForExpansion ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED,
            totalExpected: $totalExpected,
            auditedCount: $eligibilityReport->auditedOccupations,
            eligibleCount: $eligibilityReport->eligibleCount,
            blockedCount: $eligibilityReport->blockedCount,
            readyForExpansion: $readyForExpansion,
            byReason: $byReason,
            byLayer: $byLayer,
            sections: $sections,
            sidecars: array_values($sidecars),
        );
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $rows
     * @return array<string, array<string, int>>
     */
    private function byLayer(array $rows): array
    {
        $layers = [
            'entity_status',
            'baseline_status',
            'index_status',
            'runtime_status',
            'seo_geo_status',
            'surface_status',
            'safety_status',
        ];
        $counts = [];

        foreach ($rows as $row) {
            foreach ($layers as $field) {
                $status = $row->toArray()[$field]['status'];
                $layer = str_replace('_status', '', $field);
                $counts[$layer][$status] = ($counts[$layer][$status] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $left
     * @param  array<string, int>  $right
     * @return array<string, int>
     */
    private function mergeReasons(array $left, array $right): array
    {
        foreach ($right as $reason => $count) {
            $left[$reason] = ($left[$reason] ?? 0) + $count;
        }

        return $left;
    }
}
