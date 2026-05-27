<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use RuntimeException;

final class CareerProgressiveCohortCloseoutPlanner
{
    public const SCHEMA_VERSION = 'career_progressive_cohort_closeout.v1';

    /**
     * @param  array<string, mixed>  $liveAcceptance
     */
    public function closeout(
        array $liveAcceptance,
        ?int $targetPublicTotal = null,
        ?string $liveAcceptancePath = null,
        ?string $baselineSlugsPath = null,
        ?string $deltaSlugsPath = null,
        ?string $totalSlugsPath = null,
    ): CareerProgressiveCohortCloseoutResult {
        $targetPublicTotal ??= $this->positiveInt(
            $liveAcceptance['target_public_total'] ?? $liveAcceptance['expected_slugs'] ?? null,
            'target_public_total',
        );
        $baselineCount = $this->nonNegativeInt($liveAcceptance['baseline_count'] ?? null, 'baseline_count');
        $deltaCount = $this->nonNegativeInt($liveAcceptance['delta_count'] ?? null, 'delta_count');
        $totalSlugCount = $this->positiveInt(
            $liveAcceptance['total_slug_count'] ?? $liveAcceptance['total_count'] ?? $liveAcceptance['expected_slugs'] ?? null,
            'total_slug_count',
        );
        $expectedLocaleRows = $this->positiveInt(
            $liveAcceptance['expected_locale_rows'] ?? $liveAcceptance['expected_rows'] ?? null,
            'expected_locale_rows',
        );
        $blockers = $this->blockers(
            liveAcceptance: $liveAcceptance,
            targetPublicTotal: $targetPublicTotal,
            baselineCount: $baselineCount,
            deltaCount: $deltaCount,
            totalSlugCount: $totalSlugCount,
            expectedLocaleRows: $expectedLocaleRows,
            totalSlugsPath: $totalSlugsPath,
        );
        $complete = $blockers === [];

        return new CareerProgressiveCohortCloseoutResult([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $complete ? 'complete' : 'blocked',
            'accepted' => $complete,
            'read_only' => true,
            'writes_database' => false,
            'apply_allowed' => false,
            'rollout_allowed' => false,
            'live_crawl_executed' => false,
            'target' => $this->target($targetPublicTotal, $liveAcceptance),
            'target_public_total' => $targetPublicTotal,
            'baseline_count' => $baselineCount,
            'delta_count' => $deltaCount,
            'total_slug_count' => $totalSlugCount,
            'expected_locale_rows' => $expectedLocaleRows,
            'baseline_slugs_path' => $baselineSlugsPath,
            'delta_slugs_path' => $deltaSlugsPath,
            'total_slugs_path' => $totalSlugsPath,
            'acceptance_artifacts' => [
                'live_acceptance' => $liveAcceptancePath,
            ],
            'acceptance_summary' => $this->acceptanceSummary($liveAcceptance),
            'blockers' => $blockers,
            'sidecars' => $this->sidecars($liveAcceptance),
            'next_required_action' => $complete
                ? $this->nextRequiredAction($targetPublicTotal)
                : 'FIX_PROGRESSIVE_COHORT_CLOSEOUT_BLOCKERS',
        ]);
    }

    /**
     * @param  array<string, mixed>  $liveAcceptance
     * @return list<array<string, mixed>>
     */
    private function blockers(
        array $liveAcceptance,
        int $targetPublicTotal,
        int $baselineCount,
        int $deltaCount,
        int $totalSlugCount,
        int $expectedLocaleRows,
        ?string $totalSlugsPath,
    ): array {
        $blockers = [];

        if (($liveAcceptance['status'] ?? null) !== 'pass' || ($liveAcceptance['accepted'] ?? null) !== true) {
            $blockers[] = $this->blocker('live_acceptance_not_accepted', [
                'status' => $liveAcceptance['status'] ?? null,
                'accepted' => $liveAcceptance['accepted'] ?? null,
            ]);
        }

        if (($liveAcceptance['writes_database'] ?? null) !== false) {
            $blockers[] = $this->blocker('live_acceptance_not_read_only', [
                'writes_database' => $liveAcceptance['writes_database'] ?? null,
            ]);
        }

        if ($baselineCount + $deltaCount !== $targetPublicTotal || $totalSlugCount !== $targetPublicTotal) {
            $blockers[] = $this->blocker('target_public_total_mismatch', [
                'target_public_total' => $targetPublicTotal,
                'baseline_count' => $baselineCount,
                'delta_count' => $deltaCount,
                'total_slug_count' => $totalSlugCount,
            ]);
        }

        $expectedRowsFromTarget = $targetPublicTotal * $this->localeCount($liveAcceptance);
        if ($expectedLocaleRows !== $expectedRowsFromTarget) {
            $blockers[] = $this->blocker('expected_locale_rows_mismatch', [
                'expected' => $expectedRowsFromTarget,
                'actual' => $expectedLocaleRows,
            ]);
        }

        if ($this->countList($liveAcceptance['failures'] ?? []) > 0) {
            $blockers[] = $this->blocker('live_acceptance_failures_present', [
                'failures_count' => $this->countList($liveAcceptance['failures'] ?? []),
            ]);
        }

        if ($totalSlugsPath === null || trim($totalSlugsPath) === '') {
            $blockers[] = $this->blocker('total_slugs_path_missing', []);
        }

        $blockers = [
            ...$blockers,
            ...(new CareerFullVisiblePublicationGate)->blockers(
                liveAcceptance: $liveAcceptance,
                targetPublicTotal: $targetPublicTotal,
                localeCount: $this->localeCount($liveAcceptance),
            ),
        ];

        return $blockers;
    }

    /**
     * @param  array<string, mixed>  $liveAcceptance
     * @return array<string, mixed>
     */
    private function acceptanceSummary(array $liveAcceptance): array
    {
        return [
            'status' => $liveAcceptance['status'] ?? null,
            'accepted' => $liveAcceptance['accepted'] ?? null,
            'found_published' => $liveAcceptance['found_published']
                ?? data_get($liveAcceptance, 'projection_truth.found_published'),
            'release_gate_pass_count' => $liveAcceptance['release_gate_pass_count']
                ?? data_get($liveAcceptance, 'release_gate.pass_count'),
            'release_gate_blocked_count' => $liveAcceptance['release_gate_blocked_count']
                ?? data_get($liveAcceptance, 'release_gate.blocked_count'),
            'surface_equality' => $liveAcceptance['surface_equality'] ?? null,
            'mismatch_count' => $liveAcceptance['mismatch_count'] ?? null,
            'unexpected_exposure' => $liveAcceptance['unexpected_exposure'] ?? null,
            'failures_count' => $this->countList($liveAcceptance['failures'] ?? []),
            'sidecars_count' => $this->countList($liveAcceptance['sidecars'] ?? []),
            'full_visible_publication_gate' => (new CareerFullVisiblePublicationGate)->summary(
                liveAcceptance: $liveAcceptance,
                targetPublicTotal: (int) ($liveAcceptance['target_public_total'] ?? $liveAcceptance['expected_slugs'] ?? 0),
                localeCount: $this->localeCount($liveAcceptance),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $liveAcceptance
     * @return list<array<string, mixed>>
     */
    private function sidecars(array $liveAcceptance): array
    {
        $sidecars = $liveAcceptance['sidecars'] ?? [];
        if (! is_array($sidecars) || ! array_is_list($sidecars)) {
            return [];
        }

        return array_values(array_filter($sidecars, static fn (mixed $sidecar): bool => is_array($sidecar)));
    }

    /**
     * @param  array<string, mixed>  $liveAcceptance
     */
    private function target(int $targetPublicTotal, array $liveAcceptance): string
    {
        $target = trim((string) ($liveAcceptance['target'] ?? ''));
        if ($targetPublicTotal === 1048 && $target === 'detail_ready_1048') {
            return 'detail_ready_1048';
        }

        return 'career_'.$targetPublicTotal.'_total';
    }

    private function nextRequiredAction(int $targetPublicTotal): string
    {
        return match ($targetPublicTotal) {
            80 => '300_READINESS_1',
            300 => '800_READINESS_1',
            800 => '2786_READINESS_1',
            1048 => 'CAREER_DETAIL_READY_1048_CLOSEOUT_COMPLETE',
            2786 => 'CAREER_2786_FINAL_CLOSEOUT_COMPLETE',
            default => 'NEXT_PROGRESSIVE_READINESS',
        };
    }

    /**
     * @param  array<string, mixed>  $liveAcceptance
     */
    private function localeCount(array $liveAcceptance): int
    {
        $locales = $liveAcceptance['locales'] ?? ['en', 'zh'];
        if (is_array($locales) && array_is_list($locales) && count($locales) > 0) {
            return count($locales);
        }

        $localeCount = $liveAcceptance['locale_count'] ?? null;
        if (is_numeric($localeCount) && (int) $localeCount > 0) {
            return (int) $localeCount;
        }

        return 2;
    }

    private function positiveInt(mixed $value, string $context): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if (! is_int($int) || $int < 1) {
            throw new RuntimeException($context.'_invalid');
        }

        return $int;
    }

    private function nonNegativeInt(mixed $value, string $context): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if (! is_int($int) || $int < 0) {
            throw new RuntimeException($context.'_invalid');
        }

        return $int;
    }

    private function countList(mixed $value): int
    {
        return is_array($value) ? count($value) : 0;
    }

    /**
     * @return array{reason: string, context: array<string, mixed>}
     */
    private function blocker(string $reason, array $context): array
    {
        return [
            'reason' => $reason,
            'context' => $context,
        ];
    }
}
