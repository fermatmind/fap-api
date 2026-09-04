<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Evaluation;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use InvalidArgumentException;
use Throwable;

final readonly class Platform12MonthlyEvalCapabilityLifecycleEvaluator
{
    private const DIMENSIONS = ['role', 'prompt', 'model', 'tool', 'policy', 'schema', 'evidence', 'binding'];

    private const CAPABILITY_STATES = ['HOLD', 'OFFLINE_EVAL', 'ACTIVE'];

    public function __construct(private SeoRegistryHasher $hasher) {}

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function evaluate(array $evidence): array
    {
        try {
            $evaluatedAt = $this->evaluatedAt($evidence);
            $allTeamInvocation = $evidence['all_team_invocation'] ?? null;
            if ($allTeamInvocation !== 0) {
                throw new InvalidArgumentException('ALL_TEAM_INVOCATION_FORBIDDEN');
            }
            $evaluations = $this->evaluations($evidence['evaluations'] ?? null);
            $capabilities = $this->capabilities($evidence['capabilities'] ?? null);
            $state = collect($evaluations)->contains('lifecycle_state', 'HOLD')
                || collect($capabilities)->contains(fn (array $capability): bool => $capability['transition_state'] !== 'UNCHANGED')
                ? 'HOLD' : 'READY';
        } catch (Throwable) {
            $evaluatedAt = '1970-01-01T00:00:00Z';
            $allTeamInvocation = 0;
            $evaluations = [];
            $capabilities = [];
            $state = 'HOLD';
        }

        $artifact = [
            'artifact_version' => 'seo.platform12_monthly_eval_capability_lifecycle.v1',
            'mission_id' => 'seo.platform12.monthly_eval_capability_lifecycle',
            'evaluated_at' => $evaluatedAt,
            'state' => $state,
            'evaluations' => $evaluations,
            'capabilities' => $capabilities,
            'all_team_invocation' => $allTeamInvocation,
            'artifact_only' => true,
            'read_only' => true,
            'production_capability_activation_allowed' => false,
            'execution_allowed' => false,
        ];
        $artifact['artifact_hash'] = $this->hasher->hash($artifact);

        return $artifact;
    }

    /** @return list<array<string,mixed>> */
    private function evaluations(mixed $source): array
    {
        if (! is_array($source) || ! array_is_list($source) || count($source) > 100) {
            throw new InvalidArgumentException('EVALUATIONS_INVALID');
        }

        return array_map(function (mixed $evaluation): array {
            if (! is_array($evaluation)
                || ! in_array($evaluation['eval_type'] ?? null, ['DETECTOR', 'AGENT'], true)
                || ! is_string($evaluation['evaluation_id'] ?? null)
                || preg_match('/^[a-z][a-z0-9._-]{0,95}$/D', $evaluation['evaluation_id']) !== 1
                || ! is_string($evaluation['family'] ?? null)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $evaluation['family']) !== 1
                || ! in_array($evaluation['locale'] ?? null, ['zh-CN', 'en'], true)
                || ! is_int($evaluation['sample_size'] ?? null)
                || $evaluation['sample_size'] < 0
                || $evaluation['sample_size'] > 1000000
                || ! in_array($evaluation['sampling_method'] ?? null, ['CENSUS', 'RANDOM', 'STRATIFIED'], true)
                || ! is_int($evaluation['success_count'] ?? null)
                || $evaluation['success_count'] < 0
                || $evaluation['success_count'] > $evaluation['sample_size']) {
                throw new InvalidArgumentException('EVALUATION_INVALID');
            }
            $current = $this->versionVector($evaluation['version_vector'] ?? null);
            $previous = $this->versionVector($evaluation['previous_version_vector'] ?? null);
            $drift = array_values(array_filter(self::DIMENSIONS, static fn (string $key): bool => $current[$key] !== $previous[$key]));
            $sampleSize = $evaluation['sample_size'];
            $measured = $sampleSize >= 30;
            $rate = $measured ? $evaluation['success_count'] / $sampleSize : null;
            $margin = $measured ? 1.96 * sqrt($rate * (1 - $rate) / $sampleSize) : null;

            return [
                'evaluation_id' => $evaluation['evaluation_id'],
                'eval_type' => $evaluation['eval_type'],
                'family' => $evaluation['family'],
                'locale' => $evaluation['locale'],
                'sample_size' => $sampleSize,
                'sampling_method' => $evaluation['sampling_method'],
                'measurement_state' => $measured ? 'MEASURED' : 'NOT_MEASURED',
                'success_rate' => $rate,
                'confidence_interval_95' => $measured ? [
                    'lower' => max(0.0, $rate - $margin),
                    'upper' => min(1.0, $rate + $margin),
                ] : null,
                'version_drift' => $drift,
                'lifecycle_state' => $drift === [] ? 'EVALUATED' : 'HOLD',
                'required_next_state' => $drift === [] ? 'NONE' : 'OFFLINE_EVAL',
            ];
        }, $source);
    }

    /** @return list<array<string,mixed>> */
    private function capabilities(mixed $source): array
    {
        if (! is_array($source) || ! array_is_list($source) || count($source) > 100) {
            throw new InvalidArgumentException('CAPABILITIES_INVALID');
        }

        return array_map(function (mixed $capability): array {
            if (! is_array($capability)
                || ! is_string($capability['capability_id'] ?? null)
                || preg_match('/^seo\.[a-z][a-z0-9._-]{0,95}$/D', $capability['capability_id']) !== 1
                || ! in_array($capability['current_state'] ?? null, self::CAPABILITY_STATES, true)
                || ! in_array($capability['requested_state'] ?? null, self::CAPABILITY_STATES, true)) {
                throw new InvalidArgumentException('CAPABILITY_INVALID');
            }
            $blockedDirectActivation = $capability['current_state'] === 'HOLD' && $capability['requested_state'] === 'ACTIVE';
            $effective = $blockedDirectActivation ? 'OFFLINE_EVAL' : $capability['requested_state'];

            return [
                'capability_id' => $capability['capability_id'],
                'current_state' => $capability['current_state'],
                'requested_state' => $capability['requested_state'],
                'effective_candidate_state' => $effective,
                'transition_state' => $effective === $capability['current_state'] ? 'UNCHANGED' : ($blockedDirectActivation ? 'HOLD_TO_ACTIVE_BLOCKED' : 'OFFLINE_EVAL_REQUIRED'),
                'production_active' => false,
            ];
        }, $source);
    }

    /** @return array<string,string> */
    private function versionVector(mixed $source): array
    {
        if (! is_array($source)) {
            throw new InvalidArgumentException('VERSION_VECTOR_INVALID');
        }
        $keys = array_keys($source);
        $expected = self::DIMENSIONS;
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('VERSION_VECTOR_INVALID');
        }
        foreach ($source as $value) {
            if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
                throw new InvalidArgumentException('VERSION_VECTOR_INVALID');
            }
        }

        return $source;
    }

    /** @param array<string,mixed> $evidence */
    private function evaluatedAt(array $evidence): string
    {
        $value = $evidence['evaluated_at'] ?? null;
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            throw new InvalidArgumentException('EVALUATION_TIME_INVALID');
        }

        return $value;
    }
}
