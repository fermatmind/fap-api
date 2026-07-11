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
        $completed = array_values(array_map('intval', (array) ($evidence['completed_batches'] ?? [])));
        if (array_diff($requiredPrevious, $completed) !== []) {
            $errors[] = 'previous_batches_not_completed';
        }

        $checks = [
            'api_slo' => data_get($evidence, 'api_slo.passed') === true,
            'frontend_success' => $this->number(data_get($evidence, 'frontend.success_rate'))?->value >= 0.99,
            'authority_count' => $this->integer(data_get($evidence, 'authority.public_count')) === $target,
            'locale_parity' => $this->integer(data_get($evidence, 'authority.en_count')) === $target
                && $this->integer(data_get($evidence, 'authority.zh_count')) === $target,
            'seo_contracts' => data_get($evidence, 'seo.canonical_robots_structured_data_passed') === true,
            'sitemap_llms' => $this->integer(data_get($evidence, 'discoverability.sitemap_url_count')) === $target * 2
                && $this->integer(data_get($evidence, 'discoverability.llms_url_count')) === $target * 2,
            'cache_warm' => $this->number(data_get($evidence, 'cache.warm_completion_rate'))?->value === 1.0,
            'error_budget' => $this->number(data_get($evidence, 'errors.http_404_rate'))?->value <= 0.01
                && $this->number(data_get($evidence, 'errors.http_5xx_rate'))?->value <= 0.01
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

    private function number(mixed $value): ?object
    {
        return is_int($value) || is_float($value) ? (object) ['value' => (float) $value] : null;
    }
}
