<?php

declare(strict_types=1);

namespace App\Services\BigFive\Norms;

use App\Models\BigFiveNormObservation;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class BigFiveNormRecomputeEngine
{
    /**
     * @param  array<string,mixed>  $options
     */
    public function recompute(BigFiveNormSnapshot $snapshot, array $options = []): BigFiveNormRecomputeResult
    {
        $snapshotPayload = $snapshot->toArray();
        $this->assertSnapshotUsable($snapshotPayload);

        $entries = (array) data_get($snapshotPayload, 'observation_cut.entries', []);
        $ids = array_values(array_filter(array_map(
            static fn (mixed $entry): string => is_array($entry) ? trim((string) ($entry['id'] ?? '')) : '',
            $entries,
        )));

        $observations = BigFiveNormObservation::query()
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(static fn (BigFiveNormObservation $observation): int => array_search((string) $observation->getKey(), $ids, true))
            ->values();

        if ($observations->count() !== count($ids)) {
            throw new InvalidArgumentException('snapshot observation set is incomplete');
        }

        $domainKeys = $this->domainKeys($observations);
        $metrics = $this->domainMetrics($observations, $domainKeys);
        $internalPercentiles = $this->internalPercentiles($observations, $domainKeys);
        $payloadForHash = [
            'snapshot_version' => $snapshot->version(),
            'snapshot_hash' => $snapshot->hash(),
            'metrics' => $metrics,
            'internal_percentiles' => $internalPercentiles,
        ];

        return new BigFiveNormRecomputeResult([
            'mode' => 'big5_norm_recompute',
            'dry_run_only' => (bool) ($options['dry_run'] ?? true),
            'snapshot_version' => $snapshot->version(),
            'snapshot_hash' => $snapshot->hash(),
            'source' => 'immutable_norm_snapshot',
            'observation_count' => $observations->count(),
            'public_percentile_display' => 'disabled',
            'runtime_attachment' => 'disabled',
            'frontend_exposure' => 'disabled',
            'fake_percentile_fallback' => 'blocked',
            'output_hash' => $this->hash($payloadForHash),
        ], $metrics, $internalPercentiles);
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function assertSnapshotUsable(array $snapshot): void
    {
        if (($snapshot['schema_version'] ?? null) !== BigFiveNormSnapshotBuilder::SCHEMA_VERSION) {
            throw new InvalidArgumentException('unsupported norm snapshot schema');
        }

        if (($snapshot['snapshot_hash'] ?? '') === '') {
            throw new InvalidArgumentException('snapshot hash is required');
        }

        if (data_get($snapshot, 'release_metadata.public_percentile_display') !== 'disabled') {
            throw new InvalidArgumentException('public percentile display must remain disabled');
        }

        if (data_get($snapshot, 'release_metadata.runtime_attachment') !== 'disabled') {
            throw new InvalidArgumentException('runtime attachment must remain disabled');
        }
    }

    /**
     * @param  Collection<int,BigFiveNormObservation>  $observations
     * @return list<string>
     */
    private function domainKeys(Collection $observations): array
    {
        return $observations
            ->flatMap(static fn (BigFiveNormObservation $observation): array => array_keys((array) $observation->raw_domain_scores_json))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,BigFiveNormObservation>  $observations
     * @param  list<string>  $domainKeys
     * @return array<string,array{mean:float,sd:float,sample_n:int}>
     */
    private function domainMetrics(Collection $observations, array $domainKeys): array
    {
        $metrics = [];

        foreach ($domainKeys as $domainKey) {
            $values = $this->numericValues($observations, $domainKey);
            $mean = array_sum($values) / count($values);
            $variance = count($values) <= 1
                ? 0.0
                : array_sum(array_map(static fn (float $value): float => ($value - $mean) ** 2, $values)) / (count($values) - 1);
            $metrics[$domainKey] = [
                'mean' => round($mean, 6),
                'sd' => round(sqrt($variance), 6),
                'sample_n' => count($values),
            ];
        }

        ksort($metrics);

        return $metrics;
    }

    /**
     * @param  Collection<int,BigFiveNormObservation>  $observations
     * @param  list<string>  $domainKeys
     * @return array<string,array<string,int>>
     */
    private function internalPercentiles(Collection $observations, array $domainKeys): array
    {
        $percentiles = [];
        $rankSets = [];

        foreach ($domainKeys as $domainKey) {
            $rankSets[$domainKey] = $this->numericValues($observations, $domainKey);
            sort($rankSets[$domainKey], SORT_NUMERIC);
        }

        foreach ($observations as $observation) {
            $observationPercentiles = [];
            foreach ($domainKeys as $domainKey) {
                $value = (float) ((array) $observation->raw_domain_scores_json)[$domainKey];
                $lessOrEqualCount = count(array_filter(
                    $rankSets[$domainKey],
                    static fn (float $candidate): bool => $candidate <= $value,
                ));
                $observationPercentiles[$domainKey] = (int) round(($lessOrEqualCount / count($rankSets[$domainKey])) * 100);
            }
            ksort($observationPercentiles);
            $percentiles[(string) $observation->getKey()] = $observationPercentiles;
        }

        ksort($percentiles);

        return $percentiles;
    }

    /**
     * @param  Collection<int,BigFiveNormObservation>  $observations
     * @return list<float>
     */
    private function numericValues(Collection $observations, string $domainKey): array
    {
        $values = $observations
            ->map(static fn (BigFiveNormObservation $observation): mixed => ((array) $observation->raw_domain_scores_json)[$domainKey] ?? null)
            ->filter(static fn (mixed $value): bool => is_int($value) || is_float($value))
            ->map(static fn (int|float $value): float => (float) $value)
            ->values()
            ->all();

        if ($values === []) {
            throw new InvalidArgumentException('snapshot has no numeric values for '.$domainKey);
        }

        return $values;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($this->sortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function sortRecursive(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = $this->sortRecursive($child);
            }
        }

        return $value;
    }
}
