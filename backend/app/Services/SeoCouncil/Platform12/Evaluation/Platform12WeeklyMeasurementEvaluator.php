<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Evaluation;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use Throwable;

final readonly class Platform12WeeklyMeasurementEvaluator
{
    private const CHECKPOINTS = ['D7', 'D14', 'D28'];

    private const MINIMUM_SAMPLE_SIZE = 100;

    public function __construct(private SeoRegistryHasher $hasher) {}

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function evaluate(array $evidence): array
    {
        try {
            $evaluatedAt = $this->evaluatedAt($evidence);
            $checkpoints = $this->checkpoints($evidence['checkpoints'] ?? null);
            $gai = $this->gai($evidence['gai'] ?? null);
            $funnel = $this->funnel($evidence['public_funnel'] ?? null);
            $candidates = $this->candidates($evidence['cro_candidates'] ?? null);
            $state = $this->state($checkpoints, $funnel);
        } catch (Throwable) {
            $evaluatedAt = '1970-01-01T00:00:00Z';
            $checkpoints = [];
            $gai = $this->unavailableGai();
            $funnel = $this->unavailableFunnel();
            $candidates = [];
            $state = 'MEASUREMENT_HOLD';
        }

        $artifact = [
            'artifact_version' => 'seo.platform12_weekly_measurement.v1',
            'mission_id' => 'seo.platform12.weekly_checkpoints_gai_funnel_cro',
            'evaluated_at' => $evaluatedAt,
            'state' => $state,
            'minimum_sample_size' => self::MINIMUM_SAMPLE_SIZE,
            'checkpoints' => $checkpoints,
            'gai' => $gai,
            'public_funnel' => $funnel,
            'cro_candidates' => $candidates,
            'identity_data_read' => false,
            'private_result_data_read' => false,
            'third_party_gai_sync_assumed' => false,
            'artifact_only' => true,
            'read_only' => true,
            'execution_allowed' => false,
        ];
        $artifact['artifact_hash'] = $this->hasher->hash($artifact);

        return $artifact;
    }

    /** @return list<array<string,mixed>> */
    private function checkpoints(mixed $source): array
    {
        if (! is_array($source) || ! array_is_list($source) || count($source) !== 3) {
            throw new \InvalidArgumentException('CHECKPOINTS_INVALID');
        }
        $normalized = [];
        foreach ($source as $checkpoint) {
            if (! is_array($checkpoint)
                || ! in_array($checkpoint['checkpoint'] ?? null, self::CHECKPOINTS, true)
                || ! in_array($checkpoint['state'] ?? null, ['AVAILABLE', 'UNAVAILABLE', 'WINDOW_INCOMPLETE'], true)
                || ! is_int($checkpoint['sample_size'] ?? null)
                || $checkpoint['sample_size'] < 0
                || $checkpoint['sample_size'] > 100000000
                || (! is_int($checkpoint['metric_delta_ppm'] ?? null) && $checkpoint['metric_delta_ppm'] !== null)) {
                throw new \InvalidArgumentException('CHECKPOINTS_INVALID');
            }
            $normalized[] = array_intersect_key($checkpoint, array_flip(['checkpoint', 'state', 'sample_size', 'metric_delta_ppm']));
        }
        if (array_column($normalized, 'checkpoint') !== self::CHECKPOINTS) {
            throw new \InvalidArgumentException('CHECKPOINTS_INVALID');
        }

        return $normalized;
    }

    /** @return array<string,mixed> */
    private function gai(mixed $source): array
    {
        if (! is_array($source) || ! in_array($source['capability_state'] ?? null, ['AVAILABLE', 'UNAVAILABLE'], true)) {
            return $this->unavailableGai();
        }
        if ($source['capability_state'] === 'UNAVAILABLE') {
            return $this->unavailableGai();
        }
        if (($source['source_state'] ?? null) !== 'VERIFIED'
            || ! is_int($source['visibility_count'] ?? null)
            || $source['visibility_count'] < 0
            || $source['visibility_count'] > 100000000) {
            return $this->unavailableGai();
        }

        return [
            'capability_state' => 'AVAILABLE',
            'source_state' => 'VERIFIED',
            'visibility_count' => $source['visibility_count'],
            'automatic_sync_state' => 'NOT_ASSUMED',
        ];
    }

    /** @return array<string,mixed> */
    private function funnel(mixed $source): array
    {
        if (! is_array($source) || ($source['availability'] ?? null) !== 'AVAILABLE') {
            return $this->unavailableFunnel();
        }
        foreach (['sample_size', 'landing_count', 'start_count', 'result_count'] as $field) {
            if (! is_int($source[$field] ?? null) || $source[$field] < 0 || $source[$field] > 100000000) {
                throw new \InvalidArgumentException('FUNNEL_INVALID');
            }
        }
        if ($source['landing_count'] < $source['start_count'] || $source['start_count'] < $source['result_count']) {
            throw new \InvalidArgumentException('FUNNEL_INVALID');
        }

        return [
            'availability' => 'AVAILABLE',
            'sample_size' => $source['sample_size'],
            'landing_count' => $source['landing_count'],
            'start_count' => $source['start_count'],
            'result_count' => $source['result_count'],
            'aggregation_level' => 'PUBLIC_TOTALS_ONLY',
        ];
    }

    /** @return list<array<string,mixed>> */
    private function candidates(mixed $source): array
    {
        if (! is_array($source) || ! array_is_list($source) || count($source) > 50) {
            throw new \InvalidArgumentException('CRO_CANDIDATES_INVALID');
        }

        return array_map(function (mixed $candidate): array {
            if (! is_array($candidate)
                || ! is_string($candidate['candidate_ref'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $candidate['candidate_ref']) !== 1
                || ! is_int($candidate['confidence_ppm'] ?? null)
                || $candidate['confidence_ppm'] < 0
                || $candidate['confidence_ppm'] > 1000000
                || ! is_string($candidate['attribution_caveat'] ?? null)
                || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $candidate['attribution_caveat']) !== 1) {
                throw new \InvalidArgumentException('CRO_CANDIDATE_INVALID');
            }

            return array_intersect_key($candidate, array_flip(['candidate_ref', 'confidence_ppm', 'attribution_caveat']));
        }, $source);
    }

    /** @param list<array<string,mixed>> $checkpoints @param array<string,mixed> $funnel */
    private function state(array $checkpoints, array $funnel): string
    {
        if ($checkpoints === []
            || count(array_filter($checkpoints, static fn (array $item): bool => $item['state'] !== 'AVAILABLE')) > 0
            || count(array_filter($checkpoints, static fn (array $item): bool => $item['sample_size'] < self::MINIMUM_SAMPLE_SIZE)) > 0
            || $funnel['availability'] !== 'AVAILABLE'
            || $funnel['sample_size'] < self::MINIMUM_SAMPLE_SIZE) {
            return 'MEASUREMENT_HOLD';
        }

        return 'READY';
    }

    /** @return array<string,mixed> */
    private function unavailableGai(): array
    {
        return ['capability_state' => 'UNAVAILABLE', 'source_state' => 'UNAVAILABLE', 'visibility_count' => null, 'automatic_sync_state' => 'NOT_ASSUMED'];
    }

    /** @return array<string,mixed> */
    private function unavailableFunnel(): array
    {
        return ['availability' => 'UNAVAILABLE', 'sample_size' => null, 'landing_count' => null, 'start_count' => null, 'result_count' => null, 'aggregation_level' => 'PUBLIC_TOTALS_ONLY'];
    }

    private function evaluatedAt(array $evidence): string
    {
        $value = $evidence['evaluated_at'] ?? null;
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            throw new \InvalidArgumentException('EVALUATION_TIME_INVALID');
        }

        return $value;
    }
}
