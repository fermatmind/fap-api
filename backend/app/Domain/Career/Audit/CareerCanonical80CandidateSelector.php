<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonical80CandidateSelector
{
    /**
     * These reasons require remediation or approval-gated data/runtime work before a slug can enter an 80-readiness run.
     *
     * @var list<string>
     */
    private const DEFAULT_HARD_BLOCKERS = [
        'occupation_missing',
        'entity_field_missing',
        'index_state_missing',
        'projection_row_missing',
        'truth_row_missing',
        'locale_row_missing',
        'ledger_member_missing',
        'runtime_publish_state_not_published',
        'canonical_public_type_invalid',
        'zh_baseline_missing',
        'en_title_derivation_required',
        'surface_context_missing',
        'structured_data_missing',
        'sitemap_missing',
        'required_display_field_missing',
        'llms_missing',
        'llms_full_missing',
        'citation_metadata_missing',
    ];

    /**
     * @param  list<string>  $hardBlockers
     */
    public function select(
        CareerCanonicalEligibilityReport $report,
        int $targetCount = CareerCanonical80CohortReadinessPlanner::DEFAULT_TARGET_COUNT,
        array $hardBlockers = self::DEFAULT_HARD_BLOCKERS,
    ): CareerCanonical80CandidateSelectionReport {
        if ($targetCount <= 0) {
            throw new InvalidArgumentException('Career 80 candidate selection target_count must be positive.');
        }

        $policy = (new Career2786ReadinessPolicyClassifier)->classify(
            report: $report,
            targetCount: $targetCount,
        );
        $hardBlockers = $this->normalizeReasons($hardBlockers);
        $ranked = $this->rankedRows($report, $hardBlockers);
        $selectedCount = 0;
        $rows = [];

        foreach ($ranked as $index => $row) {
            $selected = $row['candidate_status'] === CareerCanonical80CandidateSelectionRow::STATUS_READY
                && $selectedCount < $targetCount;
            if ($selected) {
                $selectedCount++;
            }

            $rows[] = new CareerCanonical80CandidateSelectionRow(
                canonicalSlug: $row['canonical_slug'],
                rank: $index + 1,
                score: $row['score'],
                candidateStatus: $row['candidate_status'],
                selected: $selected,
                hardBlocked: $row['hard_blockers'] !== [],
                passedLocaleCount: $row['passed_locale_count'],
                blockedLocaleCount: $row['blocked_locale_count'],
                locales: $row['locales'],
                reasons: $row['reasons'],
                hardBlockers: $row['hard_blockers'],
                layerStatuses: $row['layer_statuses'],
                evidence: $row['evidence'],
            );
        }

        return CareerCanonical80CandidateSelectionReport::build($targetCount, $rows, $policy->summary());
    }

    /**
     * @param  list<string>  $hardBlockers
     * @return list<array{canonical_slug: string, score: int, candidate_status: string, passed_locale_count: int, blocked_locale_count: int, locales: list<string>, reasons: list<string>, hard_blockers: list<string>, layer_statuses: array<string, string>, evidence: list<mixed>}>
     */
    private function rankedRows(CareerCanonicalEligibilityReport $report, array $hardBlockers): array
    {
        $rows = [];
        foreach ($this->rowsBySlug($report->rows) as $slug => $auditRows) {
            $locales = [];
            $reasons = [];
            $layerStatuses = [];
            $passedLocaleCount = 0;
            $blockedLocaleCount = 0;

            foreach ($auditRows as $auditRow) {
                $locales[] = $auditRow->locale;
                $reasons = [...$reasons, ...$auditRow->reasons];
                $passedLocaleCount += $auditRow->overallStatus === CareerCanonicalEligibilityStatus::PASS ? 1 : 0;
                $blockedLocaleCount += $auditRow->overallStatus === CareerCanonicalEligibilityStatus::PASS ? 0 : 1;
                foreach ($this->layerStatuses($auditRow) as $layer => $status) {
                    $layerStatuses[$layer] = $this->leastReadyStatus($layerStatuses[$layer] ?? null, $status);
                }
            }

            $reasons = $this->normalizeReasons($reasons);
            $rowHardBlockers = array_values(array_intersect($reasons, $hardBlockers));
            $score = $this->score($passedLocaleCount, $blockedLocaleCount, count($reasons), count($rowHardBlockers));

            $rows[] = [
                'canonical_slug' => $slug,
                'score' => $score,
                'candidate_status' => $this->candidateStatus($rowHardBlockers, $blockedLocaleCount),
                'passed_locale_count' => $passedLocaleCount,
                'blocked_locale_count' => $blockedLocaleCount,
                'locales' => array_values(array_unique($locales)),
                'reasons' => $reasons,
                'hard_blockers' => $rowHardBlockers,
                'layer_statuses' => $layerStatuses,
                'evidence' => [[
                    'report_status' => $report->status,
                    'report_scope' => $report->scope,
                    'row_count' => count($auditRows),
                    'reason_count' => count($reasons),
                    'hard_blocker_count' => count($rowHardBlockers),
                ]],
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            if ($left['score'] !== $right['score']) {
                return $right['score'] <=> $left['score'];
            }

            return $left['canonical_slug'] <=> $right['canonical_slug'];
        });

        return $rows;
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

    /**
     * @return array<string, string>
     */
    private function layerStatuses(CareerCanonicalEligibilityAuditRow $row): array
    {
        return [
            CareerCanonicalEligibilityLayer::ENTITY => $row->entityStatus->status,
            CareerCanonicalEligibilityLayer::BASELINE => $row->baselineStatus->status,
            CareerCanonicalEligibilityLayer::INDEX => $row->indexStatus->status,
            CareerCanonicalEligibilityLayer::RUNTIME => $row->runtimeStatus->status,
            CareerCanonicalEligibilityLayer::SEO_GEO => $row->seoGeoStatus->status,
            CareerCanonicalEligibilityLayer::SURFACE => $row->surfaceStatus->status,
            CareerCanonicalEligibilityLayer::SAFETY => $row->safetyStatus->status,
        ];
    }

    private function leastReadyStatus(?string $current, string $next): string
    {
        if ($current === null) {
            return $next;
        }

        $rank = [
            CareerCanonicalEligibilityStatus::PASS => 0,
            CareerCanonicalEligibilityStatus::WARNING => 1,
            CareerCanonicalEligibilityStatus::BLOCKED => 2,
            CareerCanonicalEligibilityStatus::FAIL => 3,
        ];

        return ($rank[$next] ?? 3) > ($rank[$current] ?? -1) ? $next : $current;
    }

    private function candidateStatus(array $hardBlockers, int $blockedLocaleCount): string
    {
        if ($hardBlockers !== []) {
            return CareerCanonical80CandidateSelectionRow::STATUS_EXCLUDED_HARD_BLOCKER;
        }

        return $blockedLocaleCount === 0
            ? CareerCanonical80CandidateSelectionRow::STATUS_READY
            : CareerCanonical80CandidateSelectionRow::STATUS_NEAR_ELIGIBLE;
    }

    private function score(int $passedLocaleCount, int $blockedLocaleCount, int $reasonCount, int $hardBlockerCount): int
    {
        return max(0, 1000 + ($passedLocaleCount * 100) - ($blockedLocaleCount * 25) - ($reasonCount * 5) - ($hardBlockerCount * 100));
    }

    /**
     * @param  list<string>  $reasons
     * @return list<string>
     */
    private function normalizeReasons(array $reasons): array
    {
        if (! array_is_list($reasons)) {
            throw new InvalidArgumentException('Career 80 candidate selection reasons must be a list.');
        }

        $normalized = [];
        foreach ($reasons as $reason) {
            if (! is_string($reason)) {
                throw new InvalidArgumentException('Career 80 candidate selection reasons must contain strings.');
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
