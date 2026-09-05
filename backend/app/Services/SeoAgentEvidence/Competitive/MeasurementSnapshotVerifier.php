<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Competitive;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class MeasurementSnapshotVerifier
{
    private const REFRESHABLE = [
        'search_measurement' => ['GSC_NO_ELIGIBLE_ROWS', 'GSC_STALE', 'GSC_WINDOW_INCOMPLETE'],
        'commercial_funnel_cro' => ['CRO_READMODEL_UNHEALTHY', 'CRO_STALE', 'CRO_WINDOW_INCOMPLETE'],
    ];

    public function __construct(
        private readonly CompetitiveMeasurementReadiness $readiness,
        private readonly SeoEvidenceCanonicalHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function verify(string $releaseSha, string $pageFamily, string $environment): array
    {
        $assessment = $this->readiness->assess($releaseSha, $pageFamily, $environment);
        $assessment['environment'] = $environment;
        $assessment['search_measurement'] = $this->mode(
            (array) ($assessment['search_measurement'] ?? []),
            (array) data_get($assessment, 'bundles.search_measurement', []),
            $environment,
            'search_measurement',
        );
        $assessment['cro_measurement'] = $this->mode(
            (array) ($assessment['cro_measurement'] ?? []),
            (array) data_get($assessment, 'bundles.commercial_funnel_cro', []),
            $environment,
            'commercial_funnel_cro',
        );
        $assessment['measurement_snapshot_set_hash'] = $this->hasher->hash([
            $assessment['search_measurement']['snapshot_hash'],
            $assessment['cro_measurement']['snapshot_hash'],
        ]);

        return $assessment;
    }

    public function refreshable(string $modeId, string $reason): bool
    {
        return in_array($reason, self::REFRESHABLE[$modeId] ?? [], true);
    }

    public function hasTrustedBaseline(string $modeId, string $environment): bool
    {
        try {
            if ($modeId === 'search_measurement') {
                $connection = (string) config('seo_intel.connection', 'seo_intel');
                if (! SchemaBaseline::tableExists('seo_gsc_sync_runs', $connection)) {
                    return false;
                }
                $receipts = DB::connection($connection)->table('seo_gsc_sync_runs')
                    ->where('status', 'success')
                    ->whereNotNull('receipt_json')
                    ->orderByDesc('finished_at')
                    ->limit(20)
                    ->pluck('receipt_json');
                foreach ($receipts as $encoded) {
                    try {
                        $receipt = json_decode((string) $encoded, true, 64, JSON_THROW_ON_ERROR);
                    } catch (Throwable) {
                        continue;
                    }
                    if (is_array($receipt)
                        && ($receipt['window_days'] ?? null) === 90
                        && ($receipt['fetch_mode'] ?? null) === 'full_window'
                        && ($receipt['search_types'] ?? null) === ['web']
                        && data_get($receipt, 'quality_gate.status') === 'pass'
                        && ($receipt['unmapped_rows'] ?? null) === 0
                        && ($receipt['duplicate_natural_keys'] ?? null) === 0
                        && ($receipt['read_only_gsc'] ?? null) === true
                        && ($receipt['search_submission_allowed'] ?? null) === false
                        && data_get($receipt, 'restricted_egress.status') === 'restricted'
                        && (! isset($receipt['environment']) || $receipt['environment'] === $environment)) {
                        return true;
                    }
                }

                return false;
            }

            $connection = (string) config('database.default');
            if (! SchemaBaseline::tableExists('analytics_seo_conversion_refresh_runs', $connection)) {
                return false;
            }

            return DB::connection($connection)->table('analytics_seo_conversion_refresh_runs')
                ->where('status', 'success')
                ->where('org_scope_count', 1)
                ->whereNotNull('receipt_json')
                ->orderByDesc('completed_at')
                ->limit(20)
                ->pluck('receipt_json')
                ->contains(function (mixed $encoded) use ($environment): bool {
                    try {
                        $receipt = json_decode((string) $encoded, true, 64, JSON_THROW_ON_ERROR);

                        return is_array($receipt)
                            && in_array(($receipt['schema_version'] ?? null), [
                                'analytics-seo-conversion-refresh-receipt.v1',
                                'analytics-seo-conversion-refresh-receipt.v2',
                            ], true)
                            && (! isset($receipt['environment']) || $receipt['environment'] === $environment)
                            && ($receipt['status'] ?? null) === 'success'
                            && ($receipt['org_scope_mode'] ?? null) === 'bounded'
                            && ($receipt['org_scope_count'] ?? null) === 1
                            && ($receipt['public_org_zero_only'] ?? true) === true
                            && data_get($receipt, 'readback_receipt.status') === 'pass'
                            && isset($receipt['from'], $receipt['to'])
                            && CarbonImmutable::parse((string) $receipt['from'], 'UTC')
                                ->diffInDays(CarbonImmutable::parse((string) $receipt['to'], 'UTC')) >= 89;
                    } catch (Throwable) {
                        return false;
                    }
                });
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $mode @param array<string, mixed> $bundle @return array<string, mixed> */
    private function mode(array $mode, array $bundle, string $environment, string $modeId): array
    {
        $reason = (string) ($mode['hold_reason'] ?? 'INTERNAL_SAFE_HOLD');
        $mode['snapshot_hash'] = $this->hasher->hash([
            'environment' => $environment,
            'mode_id' => $modeId,
            'property_hash' => $modeId === 'search_measurement'
                ? hash('sha256', (string) config('seo_intel.gsc_property_url', 'unconfigured'))
                : null,
            'org_id' => $modeId === 'commercial_funnel_cro' ? 0 : null,
            'authority_revision' => (string) ($bundle['authority_revision'] ?? hash('sha256', $modeId.'|'.$reason)),
            'windows' => (array) data_get($bundle, 'payload.windows', []),
            'freshness' => (array) data_get($bundle, 'payload.freshness', []),
            'mapping_state' => data_get($bundle, 'payload.mapping_state'),
            'quality_gate_status' => data_get($bundle, 'payload.quality_gate_status'),
        ]);
        $mode['refresh_eligible'] = $this->refreshable($modeId, $reason);

        return $mode;
    }
}
