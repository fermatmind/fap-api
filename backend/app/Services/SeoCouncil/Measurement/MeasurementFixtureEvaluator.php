<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class MeasurementFixtureEvaluator
{
    public function __construct(
        private readonly MeasurementContractRegistry $contracts,
        private readonly MeasurementStateResolver $states,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(): array
    {
        $set = $this->contracts->fixtureSet();
        $sourceMisclassification = 0;
        $measurementMisclassification = 0;
        $validZeroMisclassification = 0;
        foreach ((array) ($set['fixtures'] ?? []) as $fixture) {
            if (! is_array($fixture)) {
                $measurementMisclassification++;

                continue;
            }
            $kind = (string) ($fixture['kind'] ?? '');
            $expected = (string) ($fixture['expected'] ?? 'hold');
            $actual = $kind === 'source'
                ? $this->states->sourceCapability((array) ($fixture['evidence'] ?? []))['state']
                : $this->states->measurementState((array) ($fixture['evidence'] ?? []))['state'];
            $sourceMisclassification += (int) ($kind === 'source' && $actual !== $expected);
            $measurementMisclassification += (int) ($kind === 'measurement' && $actual !== $expected);
            $validZeroMisclassification += (int) ($kind === 'measurement'
                && ($expected === 'valid_zero' || $actual === 'valid_zero') && $actual !== $expected);
        }
        $metrics = [
            'fixture_total' => count((array) ($set['fixtures'] ?? [])),
            'source_state_misclassification_count' => $sourceMisclassification,
            'measurement_state_misclassification_count' => $measurementMisclassification,
            'valid_zero_misclassification_count' => $validZeroMisclassification,
        ];

        return [
            'fixture_set_id' => $set['fixture_set_id'], 'fixture_set_version' => $set['fixture_set_version'],
            'fixture_set_hash' => $this->hasher->hash($set), 'metrics' => $metrics,
        ];
    }
}
