<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\DTO\Career\CareerFirstWaveRolloutQueueSummary;

final class CareerFirstWaveRolloutQueueSummaryService
{
    public const SUMMARY_VERSION = 'career.rollout.first_wave.v1';

    public const SCOPE = 'career_first_wave_10';

    public const QUEUE_STATE_PROMOTION_CANDIDATE_REVIEW = 'promotion_candidate_review';

    public const QUEUE_STATE_DEMOTION_REVIEW = 'demotion_review';

    public function __construct(
        private readonly CareerFirstWaveLifecycleSummaryService $lifecycleSummaryService,
        private readonly FirstWaveReadinessSummaryService $readinessSummaryService,
    ) {}

    public function build(): CareerFirstWaveRolloutQueueSummary
    {
        $lifecycleSummary = $this->lifecycleSummaryService->build()->toArray();
        $readinessSummary = $this->readinessSummaryService->build()->toArray();
        $readinessBySlug = [];

        foreach ((array) ($readinessSummary['occupations'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = (string) ($row['canonical_slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $readinessBySlug[$slug] = $row;
        }

        $counts = [
            'total' => 0,
            self::QUEUE_STATE_PROMOTION_CANDIDATE_REVIEW => 0,
            self::QUEUE_STATE_DEMOTION_REVIEW => 0,
        ];
        $queueItems = [];

        foreach ((array) ($lifecycleSummary['occupations'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = (string) ($row['canonical_slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $readinessRow = $readinessBySlug[$slug] ?? [];
            $queueState = $this->determineQueueState((string) ($row['lifecycle_state'] ?? ''));
            if ($queueState === null) {
                continue;
            }

            $counts['total']++;
            $counts[$queueState]++;

            $queueItems[] = [
                'occupation_uuid' => (string) ($row['occupation_uuid'] ?? ''),
                'canonical_slug' => $slug,
                'canonical_title_en' => (string) ($row['canonical_title_en'] ?? ''),
                'lifecycle_state' => (string) ($row['lifecycle_state'] ?? CareerIndexLifecycleState::NOINDEX),
                'readiness_status' => $this->normalizeNullableString($readinessRow['status'] ?? null),
                'public_index_state' => (string) ($row['public_index_state'] ?? 'noindex'),
                'index_eligible' => (bool) ($row['index_eligible'] ?? false),
                'reviewer_status' => $this->normalizeNullableString($row['reviewer_status'] ?? null),
                'blocked_governance_status' => $this->normalizeNullableString($readinessRow['blocked_governance_status'] ?? null),
                'queue_state' => $queueState,
                'reason_codes' => $this->curateReasonCodes(
                    queueState: $queueState,
                    lifecycleReasonCodes: (array) ($row['reason_codes'] ?? []),
                    blockedGovernanceStatus: $this->normalizeNullableString($readinessRow['blocked_governance_status'] ?? null),
                ),
            ];
        }

        usort($queueItems, function (array $left, array $right): int {
            $queueOrder = [
                self::QUEUE_STATE_PROMOTION_CANDIDATE_REVIEW => 0,
                self::QUEUE_STATE_DEMOTION_REVIEW => 1,
            ];

            $leftOrder = $queueOrder[(string) ($left['queue_state'] ?? '')] ?? 99;
            $rightOrder = $queueOrder[(string) ($right['queue_state'] ?? '')] ?? 99;

            if ($leftOrder !== $rightOrder) {
                return $leftOrder <=> $rightOrder;
            }

            return strcmp((string) ($left['canonical_slug'] ?? ''), (string) ($right['canonical_slug'] ?? ''));
        });

        return new CareerFirstWaveRolloutQueueSummary(
            summaryVersion: self::SUMMARY_VERSION,
            scope: self::SCOPE,
            counts: $counts,
            queueItems: $queueItems,
        );
    }

    private function determineQueueState(string $lifecycleState): ?string
    {
        $normalized = strtolower(trim($lifecycleState));

        return match ($normalized) {
            CareerIndexLifecycleState::PROMOTION_CANDIDATE => self::QUEUE_STATE_PROMOTION_CANDIDATE_REVIEW,
            CareerIndexLifecycleState::DEMOTED => self::QUEUE_STATE_DEMOTION_REVIEW,
            default => null,
        };
    }

    /**
     * @param  array<int, mixed>  $lifecycleReasonCodes
     * @return list<string>
     */
    private function curateReasonCodes(
        string $queueState,
        array $lifecycleReasonCodes,
        ?string $blockedGovernanceStatus,
    ): array {
        $allowedLifecycleCodes = [
            'publish_gate_candidate',
            'review_pending',
            'trust_limited',
            'demoted_trust_regression',
            'demoted_review_regression',
            'not_index_eligible',
        ];

        $reasonCodes = match ($queueState) {
            self::QUEUE_STATE_PROMOTION_CANDIDATE_REVIEW => ['promotion_candidate'],
            self::QUEUE_STATE_DEMOTION_REVIEW => ['demoted_lifecycle'],
            default => [],
        };

        foreach ($lifecycleReasonCodes as $code) {
            if (! is_string($code)) {
                continue;
            }

            $normalized = trim($code);
            if ($normalized !== '' && in_array($normalized, $allowedLifecycleCodes, true)) {
                $reasonCodes[] = $normalized;
            }
        }

        if ($blockedGovernanceStatus !== null) {
            $reasonCodes[] = 'blocked_governance';
        }

        return array_values(array_unique($reasonCodes));
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }
}
