<?php

declare(strict_types=1);

namespace App\Services\Career;

final class Career10kControlledRolloutGate
{
    public const BATCHES = [100, 500, 1000, 2500, 5000, 10000];

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function evaluate(int $target, array $evidence): array
    {
        $errors = [];
        $position = array_search($target, self::BATCHES, true);
        if ($position === false) {
            $errors[] = 'unsupported_rollout_batch';
            $position = 0;
        }

        $requiredPrevious = array_slice(self::BATCHES, 0, (int) $position);
        $completedEvidence = $evidence['completed_batches'] ?? null;
        $completed = is_array($completedEvidence)
            ? array_values(array_filter($completedEvidence, fn (mixed $value): bool => is_int($value) && in_array($value, self::BATCHES, true)))
            : [];
        if (! is_array($completedEvidence) || count($completed) !== count($completedEvidence)) {
            $errors[] = 'completed_batches_invalid';
        }
        if (array_diff($requiredPrevious, $completed) !== []) {
            $errors[] = 'previous_batches_not_completed';
        }

        $frontendSuccessRate = $this->number(data_get($evidence, 'frontend.success_rate'));
        $cacheWarmRate = $this->number(data_get($evidence, 'cache.warm_completion_rate'));
        $http404Rate = $this->number(data_get($evidence, 'errors.http_404_rate'));
        $http5xxRate = $this->number(data_get($evidence, 'errors.http_5xx_rate'));

        $checks = [
            'api_slo' => data_get($evidence, 'api_slo.passed') === true,
            'frontend_success' => $frontendSuccessRate !== null && $frontendSuccessRate >= 0.99,
            'authority_count' => $this->integer(data_get($evidence, 'authority.public_count')) === $target,
            'locale_parity' => $this->integer(data_get($evidence, 'authority.en_count')) === $target
                && $this->integer(data_get($evidence, 'authority.zh_count')) === $target,
            'seo_contracts' => data_get($evidence, 'seo.canonical_robots_structured_data_passed') === true,
            'sitemap_llms' => $this->integer(data_get($evidence, 'discoverability.sitemap_url_count')) === $target * 2
                && $this->integer(data_get($evidence, 'discoverability.llms_url_count')) === $target * 2,
            'cache_warm' => $cacheWarmRate === 1.0,
            'error_budget' => $http404Rate !== null && $http404Rate <= 0.01
                && $http5xxRate !== null && $http5xxRate <= 0.01
                && $this->integer(data_get($evidence, 'errors.http_504_count')) === 0,
            'rollback_ready' => data_get($evidence, 'rollback.ready') === true
                && is_string(data_get($evidence, 'rollback.previous_version'))
                && data_get($evidence, 'rollback.previous_version') !== '',
            'publication_indexability_gate' => data_get($evidence, 'publication_gate.passed') === true
                && $this->integer(data_get($evidence, 'publication_gate.approved_count')) === $target,
        ];

        foreach ($checks as $name => $passed) {
            if (! $passed) {
                $errors[] = $name.'_failed';
            }
        }

        return [
            'schema_version' => 'career.controlled_rollout_gate.v1',
            'status' => $errors === [] ? 'passed' : 'blocked',
            'target_batch' => $target,
            'batch_sequence' => self::BATCHES,
            'required_previous_batches' => $requiredPrevious,
            'checks' => $checks,
            'errors' => $errors,
            'ready_for_separate_exact_sha_approval' => $errors === [],
            'apply_allowed' => false,
            'promotion_executed' => false,
            'production_write_performed' => false,
            'deployment_triggered' => false,
            'search_channel_action_performed' => false,
            'rollback' => [
                'scope' => 'target_batch_runtime_projection_only',
                'restore_version' => data_get($evidence, 'rollback.previous_version'),
                'automatic_on_gate_failure' => true,
            ],
        ];
    }

    private function integer(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function number(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }
}
