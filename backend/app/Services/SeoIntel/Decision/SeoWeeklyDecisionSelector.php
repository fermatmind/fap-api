<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Decision;

use Carbon\CarbonImmutable;

final class SeoWeeklyDecisionSelector
{
    public const CONTRACT_VERSION = 'seo.weekly_decision_snapshot.v1';

    public const DEFAULT_COUNT = 3;

    public const MAX_COUNT = 5;

    private const ELIGIBLE_STATES = ['candidate', 'selected', 'in_progress', 'recovery_pending'];

    public function __construct(
        private readonly SeoDecisionCardReadService $readService,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(?CarbonImmutable $now = null, int $limit = self::DEFAULT_COUNT): array
    {
        $source = $this->readService->snapshot();
        $isoWeek = ($now ?? CarbonImmutable::now('UTC'))->setTimezone('UTC')->format('o-\WW');
        if ($source['state'] === 'unavailable') {
            return $this->response('unavailable', $isoWeek, [], null);
        }

        $eligible = array_values(array_filter(
            $source['items'],
            fn (array $card): bool => $this->eligible($card),
        ));
        usort($eligible, fn (array $left, array $right): int => $this->compare($left, $right));
        $selected = array_slice($eligible, 0, max(0, min($limit, self::MAX_COUNT)));
        $selectionRevision = $this->selectionRevision($isoWeek, $selected);
        $decisions = [];
        foreach ($selected as $index => $card) {
            $card['selection_revision'] = $selectionRevision;
            $card['selection_rank'] = $index + 1;
            $decisions[] = $card;
        }

        return $this->response($decisions === [] ? 'verified_zero' : 'available', $isoWeek, $decisions, $selectionRevision);
    }

    /** @param array<string, mixed> $card */
    private function eligible(array $card): bool
    {
        if (! in_array($card['status'] ?? null, self::ELIGIBLE_STATES, true)
            || ($card['measurement_state'] ?? null) === 'MEASUREMENT_HOLD'
            || ($card['evidence_freshness'] ?? null) !== 'fresh'
            || ! is_numeric($card['priority_score'] ?? null)) {
            return false;
        }

        return ! in_array($card['risk_tier'] ?? null, ['P0', 'P1'], true)
            || ($card['evidence_state'] ?? null) === 'verified';
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compare(array $left, array $right): int
    {
        foreach ([
            [$this->riskBand($left), $this->riskBand($right), false],
            [(float) $left['priority_score'], (float) $right['priority_score'], true],
            [$this->evidenceBand($left), $this->evidenceBand($right), false],
            [(int) $left['affected_unique_url_count'], (int) $right['affected_unique_url_count'], true],
        ] as [$leftValue, $rightValue, $descending]) {
            $comparison = $leftValue <=> $rightValue;
            if ($comparison !== 0) {
                return $descending ? -$comparison : $comparison;
            }
        }

        return strcmp((string) $left['cluster_uid'], (string) $right['cluster_uid']);
    }

    /** @param array<string, mixed> $card */
    private function riskBand(array $card): int
    {
        return match ($card['risk_tier'] ?? null) {
            'P0' => 0,
            'P1' => 1,
            default => 2,
        };
    }

    /** @param array<string, mixed> $card */
    private function evidenceBand(array $card): int
    {
        return match ($card['evidence_state'] ?? null) {
            'verified' => 0,
            'observed' => 1,
            'inferred' => 2,
            default => 3,
        };
    }

    /** @param list<array<string, mixed>> $decisions */
    private function selectionRevision(string $isoWeek, array $decisions): string
    {
        $identities = array_map(
            fn (array $card): string => (string) ($card['decision_card_id'] ?? ''),
            $decisions,
        );

        return 'seo_weekly_'.$isoWeek.'_'.substr(hash('sha256', implode('|', $identities)), 0, 16);
    }

    /** @param list<array<string, mixed>> $decisions @return array<string, mixed> */
    private function response(string $state, string $isoWeek, array $decisions, ?string $selectionRevision): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'state' => $state,
            'iso_week' => $isoWeek,
            'selection_revision' => $selectionRevision,
            'decisions' => $decisions,
            'count' => count($decisions),
            'default_count' => self::DEFAULT_COUNT,
            'max_count' => self::MAX_COUNT,
            'read_only' => true,
            'padded' => false,
        ];
    }
}
