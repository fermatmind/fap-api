<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class Career2786ReadinessPolicyClassifier
{
    public const DEFAULT_TARGET_COUNT = 80;

    private const REMEDIATION_REQUIRED_REASONS = [
        'occupation_missing',
        'entity_field_missing',
        'index_state_missing',
    ];

    private const APPROVAL_GATED_REASONS = [
        'index_state_missing',
        'occupation_missing',
        'entity_field_missing',
    ];

    private const EXPECTED_NOT_READY_REASONS = [
        'sitemap_expected_not_ready',
        'llms_expected_not_ready',
        'llms_full_expected_not_ready',
    ];

    private const DEFERRED_SURFACE_REASONS = [
        'surface_artifact_missing',
        'surface_unverified',
    ];

    private const CONTEXT_HARD_BLOCKERS = [
        'surface_context_missing',
        'entity_db_context_missing',
        'index_state_context_missing',
        'runtime_projection_context_missing',
        'runtime_truth_context_missing',
        'public_resolution_plan_missing',
    ];

    /**
     * @param  list<string>  $selectedCandidateSlugs
     */
    public function classify(
        CareerCanonicalEligibilityReport $report,
        int $targetCount = self::DEFAULT_TARGET_COUNT,
        array $selectedCandidateSlugs = [],
    ): Career2786ReadinessPolicyResult {
        if ($targetCount <= 0) {
            throw new InvalidArgumentException('Career 2786 readiness policy target_count must be positive.');
        }

        $selectedCandidateSlugs = $this->normalizeReasons($selectedCandidateSlugs);
        $rows = [];
        foreach ($this->rowsBySlug($report->rows) as $slug => $auditRows) {
            $rows[] = $this->classifySlug($slug, $auditRows, in_array($slug, $selectedCandidateSlugs, true));
        }

        usort(
            $rows,
            static fn (Career2786ReadinessPolicyRow $left, Career2786ReadinessPolicyRow $right): int => $left->canonicalSlug <=> $right->canonicalSlug
        );

        $byClassification = [];
        $byReason = [];
        $nearEligibleSlugs = [];
        $eligibleCandidateSlugs = [];
        $candidateBlockingSlugs = [];
        $approvalGatedSlugs = [];

        foreach ($rows as $row) {
            $byClassification[$row->classification] = ($byClassification[$row->classification] ?? 0) + 1;
            foreach ($row->issues as $issue) {
                $byReason[$issue->reason] = ($byReason[$issue->reason] ?? 0) + 1;
            }
            if ($row->nearEligible) {
                $nearEligibleSlugs[] = $row->canonicalSlug;
            }
            if ($row->eligibleCandidate) {
                $eligibleCandidateSlugs[] = $row->canonicalSlug;
            }
            if ($row->blocks80Readiness) {
                $candidateBlockingSlugs[] = $row->canonicalSlug;
            }
            if ($row->requiresApproval) {
                $approvalGatedSlugs[] = $row->canonicalSlug;
            }
        }

        ksort($byClassification);
        ksort($byReason);

        return new Career2786ReadinessPolicyResult(
            schemaVersion: Career2786ReadinessPolicyResult::SCHEMA_VERSION,
            targetCount: $targetCount,
            readinessCanRun: count($eligibleCandidateSlugs) >= $targetCount,
            rows: $rows,
            byClassification: $byClassification,
            byReason: $byReason,
            nearEligibleSlugs: $nearEligibleSlugs,
            eligibleCandidateSlugs: $eligibleCandidateSlugs,
            candidateBlockingSlugs: $candidateBlockingSlugs,
            approvalGatedSlugs: $approvalGatedSlugs,
            candidateCohortPrerequisites: $this->candidateCohortPrerequisites($rows, $targetCount),
            recommendedOrder: $this->recommendedOrder($rows),
        );
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $auditRows
     */
    private function classifySlug(string $slug, array $auditRows, bool $selectedCandidate): Career2786ReadinessPolicyRow
    {
        $locales = [];
        $reasons = [];
        $layerByReason = [];
        foreach ($auditRows as $auditRow) {
            $locales[] = $auditRow->locale;
            foreach ($this->layerStatuses($auditRow) as $layerStatus) {
                foreach ($layerStatus->reasons as $reason) {
                    $layerByReason[$reason] = $layerStatus->layer;
                }
            }
            $reasons = [...$reasons, ...$auditRow->reasons];
        }

        $locales = $this->normalizeReasons($locales);
        $reasons = $this->normalizeReasons($reasons);
        $remediation = array_values(array_intersect($reasons, self::REMEDIATION_REQUIRED_REASONS));
        $expectedNotReady = array_values(array_intersect($reasons, self::EXPECTED_NOT_READY_REASONS));
        $surface = array_values(array_intersect($reasons, self::DEFERRED_SURFACE_REASONS));
        $contextHardBlockers = array_values(array_intersect($reasons, self::CONTEXT_HARD_BLOCKERS));
        $approvalGated = array_values(array_intersect($reasons, self::APPROVAL_GATED_REASONS));
        if ($selectedCandidate) {
            $approvalGated = array_values(array_unique([...$approvalGated, ...$expectedNotReady, ...$surface]));
            sort($approvalGated);
        }

        $knownReasons = array_values(array_unique([
            ...self::REMEDIATION_REQUIRED_REASONS,
            ...self::EXPECTED_NOT_READY_REASONS,
            ...self::DEFERRED_SURFACE_REASONS,
            ...self::CONTEXT_HARD_BLOCKERS,
        ]));
        $unknownHardBlockers = array_values(array_diff($reasons, $knownReasons));
        $hardBlockers = $this->normalizeReasons([...$contextHardBlockers, ...$unknownHardBlockers]);
        $candidateReady = $hardBlockers === [] && $remediation === [];
        $eligibleCandidate = $candidateReady && $expectedNotReady === [] && $surface === [];
        $nearEligible = $candidateReady && ! $eligibleCandidate;
        $requiresApproval = $approvalGated !== [];
        $blocks80Readiness = $hardBlockers !== [] || $remediation !== [];
        $classification = $this->rowClassification(
            hardBlockers: $hardBlockers,
            remediation: $remediation,
            selectedCandidate: $selectedCandidate,
            expectedNotReady: $expectedNotReady,
            surface: $surface,
            eligibleCandidate: $eligibleCandidate,
            nearEligible: $nearEligible,
        );

        $issues = [];
        foreach ($hardBlockers as $reason) {
            $issues[] = $this->issue($reason, Career2786ReadinessPolicyIssue::HARD_BLOCKER, $layerByReason[$reason] ?? 'context', true);
        }
        foreach ($remediation as $reason) {
            $issues[] = $this->issue($reason, Career2786ReadinessPolicyIssue::REMEDIATION_REQUIRED, $layerByReason[$reason] ?? $this->layerForReason($reason), true);
        }
        foreach ($expectedNotReady as $reason) {
            $issues[] = $this->issue($reason, $selectedCandidate ? Career2786ReadinessPolicyIssue::APPROVAL_GATED : Career2786ReadinessPolicyIssue::EXPECTED_NOT_READY, $layerByReason[$reason] ?? CareerCanonicalEligibilityLayer::SEO_GEO, $selectedCandidate);
        }
        foreach ($surface as $reason) {
            $issues[] = $this->issue($reason, $selectedCandidate ? Career2786ReadinessPolicyIssue::APPROVAL_GATED : Career2786ReadinessPolicyIssue::DEFERRED_UNTIL_CANDIDATE, $layerByReason[$reason] ?? CareerCanonicalEligibilityLayer::SURFACE, $selectedCandidate);
        }
        if ($eligibleCandidate) {
            $issues[] = $this->issue('eligible_candidate', Career2786ReadinessPolicyIssue::ELIGIBLE_CANDIDATE, 'policy', false);
        } elseif ($nearEligible) {
            $issues[] = $this->issue('near_eligible', Career2786ReadinessPolicyIssue::NEAR_ELIGIBLE, 'policy', false);
        }

        return new Career2786ReadinessPolicyRow(
            canonicalSlug: $slug,
            classification: $classification,
            candidateReady: $candidateReady,
            nearEligible: $nearEligible,
            eligibleCandidate: $eligibleCandidate,
            requiresApproval: $requiresApproval,
            blocks80Readiness: $blocks80Readiness,
            locales: $locales,
            reasons: $reasons,
            hardBlockerReasons: $hardBlockers,
            remediationRequiredReasons: $remediation,
            expectedNotReadyReasons: $expectedNotReady,
            deferredUntilCandidateReasons: $surface,
            approvalGatedReasons: $approvalGated,
            issues: $issues,
            evidence: [[
                'selected_candidate' => $selectedCandidate,
                'candidate_ready_excludes_entity_index_blockers' => true,
                'surface_verification_deferred_until_candidate' => $surface !== [] && ! $selectedCandidate,
                'expected_not_ready_policy_deferred_until_candidate' => $expectedNotReady !== [] && ! $selectedCandidate,
                'locale_count' => count($locales),
            ]],
        );
    }

    /**
     * @param  list<string>  $hardBlockers
     * @param  list<string>  $remediation
     * @param  list<string>  $expectedNotReady
     * @param  list<string>  $surface
     */
    private function rowClassification(array $hardBlockers, array $remediation, bool $selectedCandidate, array $expectedNotReady, array $surface, bool $eligibleCandidate, bool $nearEligible): string
    {
        if ($hardBlockers !== []) {
            return Career2786ReadinessPolicyIssue::HARD_BLOCKER;
        }

        if ($remediation !== []) {
            return Career2786ReadinessPolicyIssue::REMEDIATION_REQUIRED;
        }

        if ($selectedCandidate && ($expectedNotReady !== [] || $surface !== [])) {
            return Career2786ReadinessPolicyIssue::APPROVAL_GATED;
        }

        if ($surface !== []) {
            return Career2786ReadinessPolicyIssue::DEFERRED_UNTIL_CANDIDATE;
        }

        if ($expectedNotReady !== []) {
            return Career2786ReadinessPolicyIssue::EXPECTED_NOT_READY;
        }

        if ($eligibleCandidate) {
            return Career2786ReadinessPolicyIssue::ELIGIBLE_CANDIDATE;
        }

        return $nearEligible
            ? Career2786ReadinessPolicyIssue::NEAR_ELIGIBLE
            : Career2786ReadinessPolicyIssue::HARD_BLOCKER;
    }

    private function issue(string $reason, string $classification, string $layer, bool $requiresApproval): Career2786ReadinessPolicyIssue
    {
        return new Career2786ReadinessPolicyIssue(
            reason: $reason,
            classification: $classification,
            layer: $layer,
            requiresApproval: $requiresApproval,
            blocks80Readiness: in_array($classification, [Career2786ReadinessPolicyIssue::HARD_BLOCKER, Career2786ReadinessPolicyIssue::REMEDIATION_REQUIRED], true),
            message: $this->messageFor($reason, $classification),
        );
    }

    private function messageFor(string $reason, string $classification): string
    {
        return match ($classification) {
            Career2786ReadinessPolicyIssue::REMEDIATION_REQUIRED => sprintf('[%s] requires reviewed remediation before a slug can enter an 80-candidate run.', $reason),
            Career2786ReadinessPolicyIssue::EXPECTED_NOT_READY => sprintf('[%s] is an expected-not-ready policy state until the slug is selected for publication.', $reason),
            Career2786ReadinessPolicyIssue::DEFERRED_UNTIL_CANDIDATE => sprintf('[%s] is deferred until a candidate cohort exists; it is not a 2786-wide live crawl trigger.', $reason),
            Career2786ReadinessPolicyIssue::APPROVAL_GATED => sprintf('[%s] requires explicit approval or concrete evidence for selected publication candidates.', $reason),
            Career2786ReadinessPolicyIssue::NEAR_ELIGIBLE => 'Slug has no entity/index hard blockers but still has deferred policy or surface prerequisites.',
            Career2786ReadinessPolicyIssue::ELIGIBLE_CANDIDATE => 'Slug has no remaining policy classifier blockers for candidate selection.',
            default => sprintf('[%s] is a hard blocker for 80 readiness until classified or repaired.', $reason),
        };
    }

    private function layerForReason(string $reason): string
    {
        return match ($reason) {
            'occupation_missing', 'entity_field_missing' => CareerCanonicalEligibilityLayer::ENTITY,
            'index_state_missing' => CareerCanonicalEligibilityLayer::INDEX,
            default => 'policy',
        };
    }

    /**
     * @param  list<Career2786ReadinessPolicyRow>  $rows
     * @return list<string>
     */
    private function candidateCohortPrerequisites(array $rows, int $targetCount): array
    {
        $nearEligible = count(array_filter($rows, static fn (Career2786ReadinessPolicyRow $row): bool => $row->nearEligible || $row->eligibleCandidate));
        $items = [];
        if ($nearEligible < $targetCount) {
            $items[] = sprintf('increase_near_eligible_candidates_to_%d', $targetCount);
        }
        if ($this->anyClassification($rows, Career2786ReadinessPolicyIssue::REMEDIATION_REQUIRED)) {
            $items[] = 'resolve_index_entity_remediation_required';
        }
        if ($this->anyReason($rows, self::EXPECTED_NOT_READY_REASONS)) {
            $items[] = 'resolve_or_scope_sitemap_llms_expected_not_ready_policy';
        }
        if ($this->anyReason($rows, self::DEFERRED_SURFACE_REASONS)) {
            $items[] = 'defer_surface_verification_until_candidate_cohort';
        }

        return $items;
    }

    /**
     * @param  list<Career2786ReadinessPolicyRow>  $rows
     * @return list<string>
     */
    private function recommendedOrder(array $rows): array
    {
        $order = [];
        if ($this->anyReason($rows, ['index_state_missing'])) {
            $order[] = 'index_state_remediation_plan_then_approval_gate';
        }
        if ($this->anyReason($rows, ['occupation_missing', 'entity_field_missing'])) {
            $order[] = 'occupation_entity_remediation_plan_then_approval_gate';
        }
        if ($this->anyReason($rows, self::EXPECTED_NOT_READY_REASONS)) {
            $order[] = 'sitemap_llms_expected_not_ready_policy_pr';
        }
        if ($this->anyReason($rows, self::DEFERRED_SURFACE_REASONS)) {
            $order[] = 'candidate_only_surface_verification_after_80_candidates_exist';
        }
        if ($order === []) {
            $order[] = 'run_80_readiness_read_only';
        }

        return $order;
    }

    /**
     * @param  list<Career2786ReadinessPolicyRow>  $rows
     */
    private function anyClassification(array $rows, string $classification): bool
    {
        foreach ($rows as $row) {
            if ($row->classification === $classification) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Career2786ReadinessPolicyRow>  $rows
     * @param  list<string>  $reasons
     */
    private function anyReason(array $rows, array $reasons): bool
    {
        foreach ($rows as $row) {
            if (array_intersect($row->reasons, $reasons) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $rows
     * @return array<string, list<CareerCanonicalEligibilityAuditRow>>
     */
    private function rowsBySlug(array $rows): array
    {
        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[$row->slug] ??= [];
            $bySlug[$row->slug][] = $row;
        }

        ksort($bySlug);

        return $bySlug;
    }

    /**
     * @return list<CareerCanonicalEligibilityLayerStatus>
     */
    private function layerStatuses(CareerCanonicalEligibilityAuditRow $row): array
    {
        return [
            $row->entityStatus,
            $row->baselineStatus,
            $row->indexStatus,
            $row->runtimeStatus,
            $row->seoGeoStatus,
            $row->surfaceStatus,
            $row->safetyStatus,
        ];
    }

    /**
     * @param  list<string>  $reasons
     * @return list<string>
     */
    private function normalizeReasons(array $reasons): array
    {
        if (! array_is_list($reasons)) {
            throw new InvalidArgumentException('Career 2786 readiness policy values must be a list.');
        }

        $normalized = [];
        foreach ($reasons as $reason) {
            if (! is_string($reason)) {
                throw new InvalidArgumentException('Career 2786 readiness policy values must contain strings.');
            }

            $value = trim($reason);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        sort($normalized);

        return array_values(array_unique($normalized));
    }
}
