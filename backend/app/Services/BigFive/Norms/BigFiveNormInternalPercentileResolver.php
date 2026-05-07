<?php

declare(strict_types=1);

namespace App\Services\BigFive\Norms;

use Carbon\CarbonImmutable;

final class BigFiveNormInternalPercentileResolver
{
    /**
     * @param  array<string,mixed>  $recomputeResult
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $options
     */
    public function resolve(
        BigFiveNormSnapshot $snapshot,
        array $recomputeResult,
        array $context,
        array $options = [],
    ): BigFiveNormInternalPercentileDecision {
        $snapshotPayload = $snapshot->toArray();
        $baseMetadata = $this->metadata($snapshot, $recomputeResult);

        $failure = $this->failClosedReason($snapshotPayload, $recomputeResult, $context, $options);
        if ($failure !== null) {
            return new BigFiveNormInternalPercentileDecision(false, 'fail_closed', $failure, null, $baseMetadata);
        }

        $observationId = trim((string) ($context['observation_id'] ?? ''));
        $percentiles = data_get($recomputeResult, 'internal_percentiles.'.$observationId);
        if (! is_array($percentiles)) {
            return new BigFiveNormInternalPercentileDecision(false, 'fail_closed', 'missing_internal_percentiles', null, $baseMetadata);
        }

        ksort($percentiles);

        return new BigFiveNormInternalPercentileDecision(
            true,
            'resolved_internal_only',
            'internal_percentile_resolved',
            array_map(static fn (mixed $value): int => (int) $value, $percentiles),
            $baseMetadata + [
                'observation_id_hash' => hash('sha256', $observationId),
                'public_response_allowed' => false,
                'user_visible_percentile_allowed' => false,
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,mixed>  $recomputeResult
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $options
     */
    private function failClosedReason(array $snapshot, array $recomputeResult, array $context, array $options): ?string
    {
        if (($snapshot['schema_version'] ?? null) !== BigFiveNormSnapshotBuilder::SCHEMA_VERSION) {
            return 'unsupported_snapshot_schema';
        }

        if (($snapshot['snapshot_hash'] ?? '') === '' || ($snapshot['snapshot_version'] ?? '') === '') {
            return 'missing_snapshot_identity';
        }

        if (! is_array($snapshot['lineage'] ?? null) || data_get($snapshot, 'lineage.source') !== 'append_only_big_five_norm_observations') {
            return 'missing_snapshot_lineage';
        }

        if (data_get($snapshot, 'release_metadata.public_percentile_display') !== 'disabled') {
            return 'public_percentile_display_not_disabled';
        }

        if (data_get($snapshot, 'release_metadata.runtime_attachment') !== 'disabled') {
            return 'runtime_attachment_not_disabled';
        }

        if (data_get($recomputeResult, 'summary.snapshot_version') !== ($snapshot['snapshot_version'] ?? null)) {
            return 'snapshot_version_mismatch';
        }

        if (data_get($recomputeResult, 'summary.snapshot_hash') !== ($snapshot['snapshot_hash'] ?? null)) {
            return 'snapshot_hash_mismatch';
        }

        if (($options['snapshot_stale'] ?? false) === true || $this->isExpired(data_get($snapshot, 'release_metadata.expires_at'))) {
            return 'stale_snapshot';
        }

        if (($options['drift_status'] ?? data_get($options, 'drift.summary.status', 'clear')) === 'alert') {
            return 'drift_alert_active';
        }

        $segment = (array) ($context['segment'] ?? []);
        if (($segment['small_cell_suppressed'] ?? false) === true || ($segment['sparse_segment_rejected'] ?? false) === true) {
            return 'sparse_segment';
        }

        $cellCount = $this->intValue($segment['cell_count'] ?? null);
        $minimumCellCount = $this->intValue($segment['minimum_cell_count'] ?? null);
        if ($cellCount !== null && $minimumCellCount !== null && $cellCount < $minimumCellCount) {
            return 'sparse_segment';
        }

        if (trim((string) ($context['observation_id'] ?? '')) === '') {
            return 'missing_observation_id';
        }

        return null;
    }

    private function isExpired(mixed $expiresAt): bool
    {
        if (! is_string($expiresAt) || trim($expiresAt) === '') {
            return false;
        }

        return CarbonImmutable::parse($expiresAt)->isPast();
    }

    private function intValue(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /**
     * @param  array<string,mixed>  $recomputeResult
     * @return array<string,mixed>
     */
    private function metadata(BigFiveNormSnapshot $snapshot, array $recomputeResult): array
    {
        return [
            'mode' => 'big5_internal_percentile_resolution',
            'snapshot_version' => $snapshot->version(),
            'snapshot_hash' => $snapshot->hash(),
            'recompute_output_hash' => (string) data_get($recomputeResult, 'summary.output_hash', ''),
            'release_snapshot_linkage' => 'required',
            'drift_safe_lookup' => 'required',
            'fail_closed' => true,
            'public_percentile_display' => 'disabled',
            'frontend_exposure' => 'disabled',
            'user_visible_percentile_allowed' => false,
        ];
    }
}
