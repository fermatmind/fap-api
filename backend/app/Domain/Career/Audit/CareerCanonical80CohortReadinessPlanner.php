<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonical80CohortReadinessPlanner
{
    public const DEFAULT_TARGET_COUNT = 80;

    /**
     * @param  list<string>|null  $candidateSlugs
     */
    public function plan(CareerCanonicalEligibilityReport $report, ?array $candidateSlugs = null, int $targetCount = self::DEFAULT_TARGET_COUNT): CareerCanonical80CohortReadinessResult
    {
        if ($targetCount <= 0) {
            throw new InvalidArgumentException('Career 80-cohort readiness target_count must be positive.');
        }

        $candidates = $this->candidateSlugs($report, $candidateSlugs);
        $rowsBySlug = $this->rowsBySlug($report->rows);
        $readinessRows = [];
        $issues = [];
        $seen = [];
        $selectedCount = 0;

        foreach ($candidates as $slug) {
            $duplicate = array_key_exists($slug, $seen);
            $seen[$slug] = true;
            $slugRows = $rowsBySlug[$slug] ?? [];
            $rowIssues = [];
            $reasons = [];
            $evidence = [['canonical_slug' => $slug]];

            if ($duplicate) {
                $rowIssues[] = new CareerCanonical80CohortReadinessIssue(
                    reason: CareerCanonical80CohortReadinessIssue::DUPLICATE_CANDIDATE_SLUG,
                    canonicalSlug: $slug,
                    severity: CareerCanonicalEligibilitySeverity::MEDIUM,
                    evidence: [['canonical_slug' => $slug]],
                );
                $reasons[] = CareerCanonical80CohortReadinessIssue::DUPLICATE_CANDIDATE_SLUG;
            }

            if ($slugRows === []) {
                $rowIssues[] = new CareerCanonical80CohortReadinessIssue(
                    reason: CareerCanonical80CohortReadinessIssue::ELIGIBILITY_ROW_MISSING,
                    canonicalSlug: $slug,
                    severity: CareerCanonicalEligibilitySeverity::HIGH,
                    evidence: [['canonical_slug' => $slug]],
                );
                $reasons[] = CareerCanonical80CohortReadinessIssue::ELIGIBILITY_ROW_MISSING;
            }

            foreach ($slugRows as $eligibilityRow) {
                $evidence[] = [
                    'locale' => $eligibilityRow->locale,
                    'overall_status' => $eligibilityRow->overallStatus,
                    'severity' => $eligibilityRow->severity,
                ];
                if ($eligibilityRow->overallStatus !== CareerCanonicalEligibilityStatus::PASS) {
                    $rowReasons = $eligibilityRow->reasons === []
                        ? [CareerCanonical80CohortReadinessIssue::ELIGIBILITY_BLOCKED]
                        : $eligibilityRow->reasons;
                    $rowIssues[] = new CareerCanonical80CohortReadinessIssue(
                        reason: CareerCanonical80CohortReadinessIssue::ELIGIBILITY_BLOCKED,
                        canonicalSlug: $slug,
                        severity: $eligibilityRow->severity,
                        evidence: [$eligibilityRow->toArray()],
                    );
                    $reasons = [...$reasons, ...$rowReasons];
                }
            }

            $selected = $rowIssues === [] && $selectedCount < $targetCount;
            if ($selected) {
                $selectedCount++;
            }

            $readinessRows[] = new CareerCanonical80CohortReadinessRow(
                canonicalSlug: $slug,
                cohortPosition: $selected ? $selectedCount : 0,
                selected: $selected,
                eligibilityStatus: $rowIssues === [] ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED,
                reasons: array_values(array_unique($reasons)),
                evidence: $evidence,
                issues: $rowIssues,
            );
        }

        if ($selectedCount < $targetCount) {
            $issues[] = new CareerCanonical80CohortReadinessIssue(
                reason: CareerCanonical80CohortReadinessIssue::COHORT_SIZE_NOT_MET,
                canonicalSlug: '__cohort__',
                severity: CareerCanonicalEligibilitySeverity::BLOCKER_FOR_PUBLICATION,
                evidence: [[
                    'target_count' => $targetCount,
                    'planned_count' => $selectedCount,
                    'candidate_count' => count($candidates),
                ]],
            );
        }

        return CareerCanonical80CohortReadinessResult::build(
            targetCount: $targetCount,
            candidateSlugs: $candidates,
            rows: $readinessRows,
            issues: $issues,
            sidecars: $report->sidecars,
        );
    }

    /**
     * @param  list<string>|null  $candidateSlugs
     * @return list<string>
     */
    private function candidateSlugs(CareerCanonicalEligibilityReport $report, ?array $candidateSlugs): array
    {
        if ($candidateSlugs !== null) {
            return $this->normalizeSlugs($candidateSlugs);
        }

        return $this->normalizeSlugs(array_map(
            static fn (CareerCanonicalEligibilityAuditRow $row): string => $row->slug,
            $report->rows
        ));
    }

    /**
     * @param  list<string>  $slugs
     * @return list<string>
     */
    private function normalizeSlugs(array $slugs): array
    {
        if (! array_is_list($slugs)) {
            throw new InvalidArgumentException('Career 80-cohort readiness candidate slugs must be a list.');
        }

        return array_values(array_map(static function (string $slug): string {
            $slug = trim($slug);
            if ($slug === '') {
                throw new InvalidArgumentException('Career 80-cohort readiness candidate slugs must be non-empty strings.');
            }

            return $slug;
        }, $slugs));
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

        return $bySlug;
    }
}
