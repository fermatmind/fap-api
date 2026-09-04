<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Operations;

use App\Services\SeoIntel\Decision\SeoWeeklyDecisionSelector;
use App\Services\SeoIntel\Ledger\SeoLedgerSnapshotReadService;

final readonly class Platform12DecisionExperimentReadService
{
    public function __construct(
        private SeoWeeklyDecisionSelector $decisions,
        private SeoLedgerSnapshotReadService $experiments,
    ) {}

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $decisionSource = $this->decisions->snapshot(limit: 5);
        $experimentSource = $this->experiments->snapshot(page: 1, perPage: 20);

        return [
            'cards' => [
                'state' => $this->sourceState((string) ($decisionSource['state'] ?? 'unavailable')),
                'iso_week' => $decisionSource['iso_week'] ?? null,
                'items' => array_map(fn (array $card): array => $this->card($card), $decisionSource['decisions'] ?? []),
            ],
            'experiments' => [
                'state' => $this->sourceState((string) ($experimentSource['data_state'] ?? 'unavailable')),
                'items' => array_values(array_map(
                    fn (array $experiment): array => $this->experiment($experiment),
                    array_filter(
                        $experimentSource['items'] ?? [],
                        static fn (array $experiment): bool => in_array($experiment['status'] ?? null, ['planned', 'canary', 'canary_running', 'observing'], true),
                    ),
                )),
            ],
            'navigation_only' => true,
            'read_only' => true,
            'execution_allowed' => false,
            'write_allowed' => false,
            'publish_allowed' => false,
            'cms_authority' => 'existing_filament_resources',
        ];
    }

    /** @param array<string,mixed> $card @return array<string,mixed> */
    private function card(array $card): array
    {
        return [
            'reference' => $card['decision_card_id'] ?? null,
            'detector' => $card['detector'] ?? null,
            'family' => $card['page_family'] ?? null,
            'locale' => $card['locale'] ?? null,
            'status' => $card['status'] ?? 'unavailable',
            'expires_at' => $card['expires_at'] ?? null,
            'owner' => $card['owner'] ?? 'unavailable',
            'next_step' => $card['next_step'] ?? null,
        ];
    }

    /** @param array<string,mixed> $experiment @return array<string,mixed> */
    private function experiment(array $experiment): array
    {
        $scope = is_array($experiment['canary_scope'] ?? null) ? $experiment['canary_scope'] : [];
        $shared = ($scope['shared_layer'] ?? null) === true;
        $canaryEligible = ! $shared || (($scope['feature_flag'] ?? null) === true && ($scope['allowlist'] ?? null) === true);

        return [
            'reference' => $experiment['ledger_id'] ?? null,
            'status' => $experiment['status'] ?? 'unavailable',
            'family' => $experiment['scope']['page_family'] ?? null,
            'locale' => $experiment['scope']['locale'] ?? null,
            'sample_size' => $scope['sample_size'] ?? ($experiment['primary_metric']['sample_size'] ?? null),
            'window_days' => $experiment['observation_window']['window_days'] ?? null,
            'owner' => $experiment['owner'] ?? 'unavailable',
            'readback' => $experiment['evidence_readback']['public_runtime']['status'] ?? 'unavailable',
            'rollback' => $experiment['rollback']['status'] ?? ($experiment['rollback']['state'] ?? 'unavailable'),
            'shared_layer' => $shared,
            'feature_flag' => $scope['feature_flag'] ?? null,
            'allowlist' => $scope['allowlist'] ?? null,
            'canary_state' => $canaryEligible ? 'ELIGIBLE' : 'HOLD',
        ];
    }

    private function sourceState(string $state): string
    {
        return match ($state) {
            'available' => 'available',
            'verified_zero' => 'not_started',
            default => 'unavailable',
        };
    }
}
