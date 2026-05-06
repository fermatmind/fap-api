<?php

declare(strict_types=1);

namespace App\Services\BigFive\Norms;

use App\Models\BigFiveNormObservation;
use Illuminate\Support\Collection;

final class BigFiveNormAggregationDryRun
{
    /**
     * @param  array<string,mixed>  $options
     */
    public function aggregate(array $options = []): BigFiveNormAggregationResult
    {
        $minimumCellCount = max(1, (int) ($options['minimum_cell_count'] ?? 50));
        $query = BigFiveNormObservation::query()
            ->where('norm_eligibility_status', 'eligible')
            ->where('norm_excluded', false)
            ->whereIn('quality_level', ['A', 'B']);

        $observations = $query->get();
        $groups = $observations
            ->groupBy(static fn (BigFiveNormObservation $observation): string => self::groupKey($observation))
            ->map(fn (Collection $group, string $key): array => $this->summarizeGroup($key, $group, $minimumCellCount))
            ->values()
            ->all();

        return new BigFiveNormAggregationResult([
            'mode' => 'norm_aggregation_dry_run',
            'dry_run_only' => true,
            'runtime_attachment' => 'disabled',
            'public_percentile_display' => 'disabled',
            'public_norm_values_published' => false,
            'source' => 'append_only_big_five_norm_observations',
            'eligible_observation_count' => $observations->count(),
            'minimum_cell_count' => $minimumCellCount,
            'group_count' => count($groups),
        ], $groups);
    }

    private static function groupKey(BigFiveNormObservation $observation): string
    {
        return implode('|', [
            $observation->scale_code ?? 'unknown_scale',
            $observation->locale ?? 'unknown_locale',
            $observation->region ?? 'unknown_region',
        ]);
    }

    /**
     * @param  Collection<int,BigFiveNormObservation>  $group
     * @return array<string,mixed>
     */
    private function summarizeGroup(string $key, Collection $group, int $minimumCellCount): array
    {
        $first = $group->first();
        $cellCount = $group->count();
        $publishable = $cellCount >= $minimumCellCount;
        $domainKeys = $this->domainKeys($group);

        return [
            'group_key' => $key,
            'scale_code' => $first?->scale_code,
            'locale' => $first?->locale,
            'region' => $first?->region,
            'cell_count' => $cellCount,
            'quality_levels' => $group->pluck('quality_level')->unique()->sort()->values()->all(),
            'small_cell_suppressed' => ! $publishable,
            'public_output_allowed' => false,
            'mean_domain_scores' => $publishable ? $this->means($group, $domainKeys) : null,
            'sd_domain_scores' => $publishable ? $this->sampleStandardDeviations($group, $domainKeys) : null,
        ];
    }

    /**
     * @param  Collection<int,BigFiveNormObservation>  $group
     * @return list<string>
     */
    private function domainKeys(Collection $group): array
    {
        return $group
            ->flatMap(static fn (BigFiveNormObservation $observation): array => array_keys((array) $observation->raw_domain_scores_json))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,BigFiveNormObservation>  $group
     * @param  list<string>  $domainKeys
     * @return array<string,float>
     */
    private function means(Collection $group, array $domainKeys): array
    {
        $means = [];

        foreach ($domainKeys as $domainKey) {
            $values = $this->numericValues($group, $domainKey);
            $means[$domainKey] = round(array_sum($values) / count($values), 6);
        }

        return $means;
    }

    /**
     * @param  Collection<int,BigFiveNormObservation>  $group
     * @param  list<string>  $domainKeys
     * @return array<string,float>
     */
    private function sampleStandardDeviations(Collection $group, array $domainKeys): array
    {
        $standardDeviations = [];

        foreach ($domainKeys as $domainKey) {
            $values = $this->numericValues($group, $domainKey);
            $mean = array_sum($values) / count($values);
            $variance = count($values) <= 1
                ? 0.0
                : array_sum(array_map(static fn (float $value): float => ($value - $mean) ** 2, $values)) / (count($values) - 1);
            $standardDeviations[$domainKey] = round(sqrt($variance), 6);
        }

        return $standardDeviations;
    }

    /**
     * @param  Collection<int,BigFiveNormObservation>  $group
     * @return list<float>
     */
    private function numericValues(Collection $group, string $domainKey): array
    {
        return $group
            ->map(static fn (BigFiveNormObservation $observation): mixed => ((array) $observation->raw_domain_scores_json)[$domainKey] ?? null)
            ->filter(static fn (mixed $value): bool => is_int($value) || is_float($value))
            ->map(static fn (int|float $value): float => (float) $value)
            ->values()
            ->all();
    }
}
