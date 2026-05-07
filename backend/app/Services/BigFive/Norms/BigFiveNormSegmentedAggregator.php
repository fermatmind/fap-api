<?php

declare(strict_types=1);

namespace App\Services\BigFive\Norms;

use App\Models\BigFiveNormObservation;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class BigFiveNormSegmentedAggregator
{
    private const SEGMENT_FIELDS = [
        'locale',
        'region',
        'age_band',
        'gender_bucket',
        'occupation_bucket',
    ];

    /**
     * @param  array<string,mixed>  $options
     */
    public function aggregate(BigFiveNormSnapshot $snapshot, array $options = []): BigFiveNormSegmentedAggregationResult
    {
        $snapshotPayload = $snapshot->toArray();
        $this->assertSnapshotUsable($snapshotPayload);

        $minimumCellCount = max(1, (int) ($options['minimum_cell_count'] ?? 50));
        $observations = $this->observationsFromSnapshot($snapshotPayload);
        $segments = $observations
            ->groupBy(static fn (BigFiveNormObservation $observation): string => self::segmentKey($observation))
            ->map(fn (Collection $segment, string $key): array => $this->summarizeSegment($key, $segment, $minimumCellCount))
            ->sortBy('segment_key')
            ->values()
            ->all();

        return new BigFiveNormSegmentedAggregationResult([
            'mode' => 'big5_segmented_norm_aggregation',
            'dry_run_only' => true,
            'snapshot_version' => $snapshot->version(),
            'snapshot_hash' => $snapshot->hash(),
            'source' => 'immutable_norm_snapshot',
            'segment_fields' => self::SEGMENT_FIELDS,
            'observation_count' => $observations->count(),
            'segment_count' => count($segments),
            'minimum_cell_count' => $minimumCellCount,
            'small_cell_suppression' => 'required',
            'sparse_segment_rejection' => 'required',
            'public_output_allowed' => false,
            'public_percentile_display' => 'disabled',
            'runtime_attachment' => 'disabled',
            'aggregation_governance' => 'internal_only_no_public_percentile',
            'output_hash' => $this->hash([
                'snapshot_version' => $snapshot->version(),
                'snapshot_hash' => $snapshot->hash(),
                'minimum_cell_count' => $minimumCellCount,
                'segments' => $segments,
            ]),
        ], $segments);
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return Collection<int,BigFiveNormObservation>
     */
    private function observationsFromSnapshot(array $snapshot): Collection
    {
        $entries = (array) data_get($snapshot, 'observation_cut.entries', []);
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

        return $observations;
    }

    /**
     * @param  Collection<int,BigFiveNormObservation>  $segment
     * @return array<string,mixed>
     */
    private function summarizeSegment(string $key, Collection $segment, int $minimumCellCount): array
    {
        $first = $segment->first();
        $cellCount = $segment->count();
        $publishable = $cellCount >= $minimumCellCount;
        $domainKeys = $this->domainKeys($segment);

        return [
            'segment_key' => $key,
            'segments' => [
                'locale' => $first?->locale,
                'region' => $first?->region,
                'age_band' => $first?->age_band,
                'gender_bucket' => $first?->gender_bucket,
                'occupation_bucket' => $first?->occupation_bucket,
            ],
            'cell_count' => $cellCount,
            'minimum_cell_count' => $minimumCellCount,
            'quality_levels' => $segment->pluck('quality_level')->unique()->sort()->values()->all(),
            'small_cell_suppressed' => ! $publishable,
            'sparse_segment_rejected' => ! $publishable,
            'public_output_allowed' => false,
            'public_percentile_display' => 'disabled',
            'runtime_attachment' => 'disabled',
            'domain_metrics' => $publishable ? $this->domainMetrics($segment, $domainKeys) : null,
        ];
    }

    private static function segmentKey(BigFiveNormObservation $observation): string
    {
        return implode('|', [
            $observation->locale ?? 'unknown_locale',
            $observation->region ?? 'unknown_region',
            $observation->age_band ?? 'unknown_age',
            $observation->gender_bucket ?? 'unknown_gender',
            $observation->occupation_bucket ?? 'unknown_occupation',
        ]);
    }

    /**
     * @param  Collection<int,BigFiveNormObservation>  $segment
     * @return list<string>
     */
    private function domainKeys(Collection $segment): array
    {
        return $segment
            ->flatMap(static fn (BigFiveNormObservation $observation): array => array_keys((array) $observation->raw_domain_scores_json))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,BigFiveNormObservation>  $segment
     * @param  list<string>  $domainKeys
     * @return array<string,array{mean:float,sd:float,sample_n:int}>
     */
    private function domainMetrics(Collection $segment, array $domainKeys): array
    {
        $metrics = [];

        foreach ($domainKeys as $domainKey) {
            $values = $this->numericValues($segment, $domainKey);
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
     * @param  Collection<int,BigFiveNormObservation>  $segment
     * @return list<float>
     */
    private function numericValues(Collection $segment, string $domainKey): array
    {
        $values = $segment
            ->map(static fn (BigFiveNormObservation $observation): mixed => ((array) $observation->raw_domain_scores_json)[$domainKey] ?? null)
            ->filter(static fn (mixed $value): bool => is_int($value) || is_float($value))
            ->map(static fn (int|float $value): float => (float) $value)
            ->values()
            ->all();

        if ($values === []) {
            throw new InvalidArgumentException('snapshot segment has no numeric values for '.$domainKey);
        }

        return $values;
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
