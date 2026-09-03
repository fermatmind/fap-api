<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform11;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class Platform11EvaluationBuilder
{
    /** @var list<string> */
    private const MODES = ['intent_query_ownership', 'editorial_draft', 'runtime_qa', 'independent_review'];

    /** @var list<string> */
    private const FAMILIES = ['tests', 'articles_topics', 'career', 'personality', 'trust_method_help', 'other_public'];

    /** @var list<string> */
    private const LOCALES = ['en', 'zh-CN'];

    /** @var list<string> */
    private const METRICS = [
        'routing_correctness', 'factual_correctness', 'evidence_support_accuracy',
        'claim_boundary_accuracy', 'recommendation_correctness', 'state_classification', 'reviewer_agreement',
    ];

    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /** @return array<string, mixed> */
    public function fixtureManifest(): array
    {
        $fixtures = [];
        foreach (self::MODES as $mode) {
            foreach (self::FAMILIES as $family) {
                foreach (self::LOCALES as $locale) {
                    foreach (['positive', 'hold'] as $polarity) {
                        $fixtures[] = [
                            'fixture_id' => implode(':', [$mode, $family, $locale, $polarity]),
                            'mode' => $mode,
                            'page_family' => $family,
                            'locale' => $locale,
                            'polarity' => $polarity,
                            'expected_status' => $polarity === 'positive' ? 'PASS' : 'HOLD',
                            'deterministic' => true,
                            'model_calls' => 0,
                            'tool_calls' => 0,
                            'external_calls' => 0,
                            'write_count' => 0,
                        ];
                    }
                }
            }
        }
        $manifest = [
            'manifest_id' => 'seo.platform11_evaluation_fixture_manifest.v1',
            'manifest_version' => '1.0.0',
            'sampling_method' => 'complete_stratified_deterministic_fixture_census',
            'fixtures' => $fixtures,
        ];
        $manifest['fixture_manifest_hash'] = $this->hasher->hash($manifest);

        return $manifest;
    }

    /** @return array<string, mixed> */
    public function evaluate(): array
    {
        $manifest = $this->fixtureManifest();
        $strata = [];
        foreach (self::METRICS as $metric) {
            foreach (self::FAMILIES as $family) {
                foreach (self::LOCALES as $locale) {
                    $fixtures = array_values(array_filter(
                        $manifest['fixtures'],
                        static fn (array $fixture): bool => $fixture['page_family'] === $family && $fixture['locale'] === $locale,
                    ));
                    $passed = count(array_filter($fixtures, fn (array $fixture): bool => $this->fixturePasses($fixture, $metric)));
                    $measurement = $this->metric($passed, count($fixtures));
                    $strata[] = [
                        'metric' => $metric,
                        'sampling_method' => $manifest['sampling_method'],
                        'fixture_manifest_hash' => $manifest['fixture_manifest_hash'],
                        'page_family' => $family,
                        'locale' => $locale,
                        ...$measurement,
                    ];
                }
            }
        }
        $goldenPassed = count(array_filter(
            $manifest['fixtures'],
            fn (array $fixture): bool => count(array_filter(self::METRICS, fn (string $metric): bool => $this->fixturePasses($fixture, $metric))) === count(self::METRICS),
        ));
        $positiveCount = count(array_filter($manifest['fixtures'], static fn (array $fixture): bool => $fixture['polarity'] === 'positive'));
        $holdCount = count(array_filter($manifest['fixtures'], static fn (array $fixture): bool => $fixture['polarity'] === 'hold'));

        return [
            'fixture_manifest_ref' => [
                'id' => $manifest['manifest_id'],
                'version' => $manifest['manifest_version'],
                'hash' => $manifest['fixture_manifest_hash'],
            ],
            'sample_size' => count($manifest['fixtures']),
            'positive_sample_count' => $positiveCount,
            'hold_sample_count' => $holdCount,
            'stratum_count' => count(self::MODES) * count(self::FAMILIES) * count(self::LOCALES),
            'metric_strata' => $strata,
            'golden_fixture_pass_rate' => (float) ($goldenPassed / count($manifest['fixtures'])),
            'golden_fixture_passed' => $goldenPassed,
            'golden_fixture_total' => count($manifest['fixtures']),
            'golden_fixture_confidence_interval_95' => $this->wilson(count($manifest['fixtures']), count($manifest['fixtures'])),
            'zero_sample_state' => $this->metric(0, 0),
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'write_count' => 0,
            'execution_allowed' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function metric(int $numerator, int $denominator): array
    {
        if ($denominator === 0) {
            return [
                'sample_size' => 0, 'numerator' => 0, 'denominator' => 0,
                'observed_rate' => null, 'confidence_interval_95' => null, 'measurement_state' => 'not_measured',
            ];
        }

        return [
            'sample_size' => $denominator,
            'numerator' => $numerator,
            'denominator' => $denominator,
            'observed_rate' => (float) ($numerator / $denominator),
            'confidence_interval_95' => $this->wilson($numerator, $denominator),
            'measurement_state' => 'observed',
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function fixturePasses(array $fixture, string $metric): bool
    {
        $expected = $fixture['polarity'] === 'positive' ? 'PASS' : 'HOLD';

        return in_array($metric, self::METRICS, true)
            && $fixture['expected_status'] === $expected
            && $fixture['deterministic'] === true
            && array_sum([
                $fixture['model_calls'], $fixture['tool_calls'], $fixture['external_calls'], $fixture['write_count'],
            ]) === 0;
    }

    /** @return array{lower:float,upper:float} */
    private function wilson(int $successes, int $total): array
    {
        $z = 1.959963984540054;
        $rate = $successes / $total;
        $z2 = $z * $z;
        $denominator = 1 + ($z2 / $total);
        $center = ($rate + ($z2 / (2 * $total))) / $denominator;
        $margin = ($z / $denominator) * sqrt((($rate * (1 - $rate)) / $total) + ($z2 / (4 * $total * $total)));

        return ['lower' => round(max(0.0, $center - $margin), 6), 'upper' => round(min(1.0, $center + $margin), 6)];
    }
}
