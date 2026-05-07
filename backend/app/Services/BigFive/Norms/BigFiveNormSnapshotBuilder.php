<?php

declare(strict_types=1);

namespace App\Services\BigFive\Norms;

use App\Models\BigFiveNormObservation;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class BigFiveNormSnapshotBuilder
{
    public const SCHEMA_VERSION = 'big5_norm_snapshot.v0.1';

    /**
     * @param  array<string,mixed>  $options
     */
    public function build(array $options = []): BigFiveNormSnapshot
    {
        $snapshotVersion = $this->requiredString($options, 'snapshot_version');
        $observations = BigFiveNormObservation::query()
            ->orderBy('observed_at')
            ->orderBy('id')
            ->get();

        return $this->fromObservations($observations, $options + ['snapshot_version' => $snapshotVersion]);
    }

    /**
     * @param  Collection<int,BigFiveNormObservation>  $observations
     * @param  array<string,mixed>  $options
     */
    public function fromObservations(Collection $observations, array $options): BigFiveNormSnapshot
    {
        $snapshotVersion = $this->requiredString($options, 'snapshot_version');
        $parentSnapshotVersion = $this->optionalString($options, 'parent_snapshot_version');
        $rollbackTargetSnapshotVersion = $this->optionalString($options, 'rollback_target_snapshot_version')
            ?? $parentSnapshotVersion;

        $eligible = $observations
            ->filter(static fn (BigFiveNormObservation $observation): bool => (
                $observation->norm_eligibility_status === 'eligible'
                && $observation->norm_excluded === false
                && in_array((string) $observation->quality_level, ['A', 'B'], true)
            ))
            ->sortBy([
                ['observed_at', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        $manifest = $this->observationManifest($eligible, $observations->count());
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'snapshot_version' => $snapshotVersion,
            'snapshot_kind' => 'immutable_big5_norm_snapshot',
            'immutability' => [
                'mutable' => false,
                'overwrite_allowed' => false,
                'rebuild_policy' => 'new_snapshot_version_required',
            ],
            'release_metadata' => [
                'release_state' => 'internal_candidate',
                'public_percentile_display' => 'disabled',
                'runtime_attachment' => 'disabled',
                'production_rollout' => 'disabled',
                'human_approval_required' => true,
            ],
            'lineage' => [
                'parent_snapshot_version' => $parentSnapshotVersion,
                'rollback_target_snapshot_version' => $rollbackTargetSnapshotVersion,
                'rollback_linkage' => $rollbackTargetSnapshotVersion !== null ? 'snapshot_revert' : 'none',
                'source' => 'append_only_big_five_norm_observations',
            ],
            'observation_cut' => $manifest,
            'content_versions' => $eligible->pluck('content_version')->filter()->unique()->sort()->values()->all(),
            'score_versions' => $eligible->pluck('score_version')->filter()->unique()->sort()->values()->all(),
            'segment_fields' => ['scale_code', 'locale', 'region', 'age_band', 'gender_bucket', 'occupation_bucket'],
            'input_manifest_hash' => $this->hash($manifest),
        ];

        $payload['snapshot_hash'] = $this->hash($payload);

        return new BigFiveNormSnapshot($payload);
    }

    /**
     * @param  Collection<int,BigFiveNormObservation>  $observations
     * @return array<string,mixed>
     */
    private function observationManifest(Collection $observations, int $sourceObservationCount): array
    {
        $entries = $observations
            ->map(static fn (BigFiveNormObservation $observation): array => [
                'id' => (string) $observation->getKey(),
                'score_trace_hash' => (string) $observation->score_trace_hash,
                'content_version' => (string) $observation->content_version,
                'score_version' => (string) $observation->score_version,
                'scale_code' => (string) $observation->scale_code,
                'locale' => $observation->locale,
                'region' => $observation->region,
                'age_band' => $observation->age_band,
                'gender_bucket' => $observation->gender_bucket,
                'occupation_bucket' => $observation->occupation_bucket,
            ])
            ->values()
            ->all();

        return [
            'included_observation_count' => count($entries),
            'excluded_observation_count' => $sourceObservationCount - count($entries),
            'entries' => $entries,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function canonicalJson(array $payload): string
    {
        $normalized = $this->sortRecursive($payload);

        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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

    /**
     * @param  array<string,mixed>  $options
     */
    private function requiredString(array $options, string $key): string
    {
        $value = $this->optionalString($options, $key);
        if ($value === null) {
            throw new InvalidArgumentException($key.' is required');
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function optionalString(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
