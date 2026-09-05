<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Sources;

use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProductionDetectorFoundationEvidenceSource implements DetectorFoundationEvidenceSource
{
    public function snapshot(CarbonImmutable $observedAt): array
    {
        $gsc = $this->gscFreshness($observedAt);
        $funnel = $this->funnelFreshness($observedAt);
        $truth = $this->urlTruthRevision();
        $available = $gsc['available'] && $funnel['available'] && $truth['available'];
        $thresholdExceeded = $gsc['threshold_exceeded'] || $funnel['threshold_exceeded'];

        return [
            'jobs' => [[
                'detector_id' => 'gsc_funnel_freshness',
                'evidence' => [
                    'source_state' => $available ? 'available' : 'unavailable',
                    'evidence_complete' => $available,
                    'direct_evidence' => $available,
                    'page_family' => 'other_public',
                    'locale' => 'en',
                    'indexability_state' => 'indexable',
                    'authority_revision' => $truth['authority_revision'],
                    'url_truth_revision' => $truth['url_truth_revision'],
                    'policy_version' => PageFamilyPolicyRegistry::VERSION,
                    'evidence_observed_at' => $observedAt->toIso8601String(),
                    'private_negative_set_checked' => true,
                    'affected_url_count' => $thresholdExceeded ? 1 : 0,
                    'gsc_freshness_threshold_exceeded' => $gsc['threshold_exceeded'],
                    'funnel_freshness_threshold_exceeded' => $funnel['threshold_exceeded'],
                    'verified_impact' => 'bounded',
                    'root_cause_or_error_code' => 'gsc_funnel_pipeline_freshness',
                ],
            ]],
            'metadata' => [
                'source_state' => $available ? 'available' : 'measurement_hold',
                'scope_kind' => 'platform_pipeline_freshness',
                'gsc' => $gsc,
                'funnel' => $funnel,
                'url_truth' => [
                    'available' => $truth['available'],
                    'current_public_count' => $truth['current_public_count'],
                ],
                'raw_rows_read' => false,
                'aggregate_fields_only' => true,
            ],
            'issues' => $available ? [] : ['detector_source_measurement_hold'],
        ];
    }

    /** @return array{available:bool,threshold_exceeded:bool,data_age_days:?int,receipt_age_days:?int} */
    private function gscFreshness(CarbonImmutable $observedAt): array
    {
        $connection = (string) config('seo_intel.connection', 'seo_intel');

        try {
            if (! \App\Support\SchemaBaseline::tableExists('seo_gsc_sync_runs', $connection)) {
                return $this->unavailableFreshness();
            }
            $row = DB::connection($connection)->table('seo_gsc_sync_runs')
                ->where('status', 'success')
                ->orderByDesc('end_date')
                ->first(['end_date', 'finished_at']);
            $dataAge = $this->ageDays($row?->end_date ?? null, $observedAt);
            $receiptAge = $this->ageDays($row?->finished_at ?? null, $observedAt);
            if ($dataAge === null || $receiptAge === null) {
                return $this->unavailableFreshness();
            }
            $maxAge = max(1, (int) config('seo_intel.gsc_data_quality.max_report_age_days', 10));

            return [
                'available' => true,
                'threshold_exceeded' => $dataAge > $maxAge || $receiptAge > $maxAge,
                'data_age_days' => $dataAge,
                'receipt_age_days' => $receiptAge,
            ];
        } catch (Throwable) {
            return $this->unavailableFreshness();
        }
    }

    /** @return array{available:bool,threshold_exceeded:bool,data_age_days:?int,receipt_age_days:?int} */
    private function funnelFreshness(CarbonImmutable $observedAt): array
    {
        try {
            if (! SchemaBaseline::tableExists('analytics_seo_conversion_daily')) {
                return $this->unavailableFreshness();
            }
            $row = DB::table('analytics_seo_conversion_daily')
                ->selectRaw('MAX(day) AS data_max_date')
                ->selectRaw('MAX(last_refreshed_at) AS last_refreshed_at')
                ->first();
            $dataAge = $this->ageDays($row?->data_max_date ?? null, $observedAt);
            $receiptAge = $this->ageDays($row?->last_refreshed_at ?? null, $observedAt);
            if ($dataAge === null || $receiptAge === null) {
                return $this->unavailableFreshness();
            }
            $maxAge = max(1, (int) config('seo_intel.detector_foundation.funnel_max_age_days', 2));

            return [
                'available' => true,
                'threshold_exceeded' => $dataAge > $maxAge || $receiptAge > $maxAge,
                'data_age_days' => $dataAge,
                'receipt_age_days' => $receiptAge,
            ];
        } catch (Throwable) {
            return $this->unavailableFreshness();
        }
    }

    /** @return array{available:bool,current_public_count:int,authority_revision:string,url_truth_revision:string} */
    private function urlTruthRevision(): array
    {
        $connection = (string) config('seo_intel.connection', 'seo_intel');

        try {
            if (! \App\Support\SchemaBaseline::tableExists('seo_urls', $connection)) {
                return $this->unavailableUrlTruthRevision();
            }
            $row = DB::connection($connection)->table('seo_urls')
                ->where('indexability_state', 'indexable')
                ->where('is_private_flow', false)
                ->selectRaw('COUNT(*) AS current_public_count')
                ->selectRaw('MAX(updated_at) AS revision_at')
                ->first();
            $count = max(0, (int) ($row?->current_public_count ?? 0));
            $revisionAt = is_string($row?->revision_at ?? null) ? (string) $row->revision_at : 'empty';
            $binding = hash('sha256', $count.'|'.$revisionAt);

            return [
                'available' => true,
                'current_public_count' => $count,
                'authority_revision' => 'authority-set-v1:'.substr($binding, 0, 32),
                'url_truth_revision' => 'url-truth-set-v1:'.substr($binding, 32, 32),
            ];
        } catch (Throwable) {
            return $this->unavailableUrlTruthRevision();
        }
    }

    private function ageDays(mixed $value, CarbonImmutable $observedAt): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return max(0, (int) CarbonImmutable::parse($value)->utc()->startOfDay()->diffInDays($observedAt->startOfDay(), false));
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{available:bool,threshold_exceeded:bool,data_age_days:?int,receipt_age_days:?int} */
    private function unavailableFreshness(): array
    {
        return [
            'available' => false,
            'threshold_exceeded' => false,
            'data_age_days' => null,
            'receipt_age_days' => null,
        ];
    }

    /** @return array{available:bool,current_public_count:int,authority_revision:string,url_truth_revision:string} */
    private function unavailableUrlTruthRevision(): array
    {
        return [
            'available' => false,
            'current_public_count' => 0,
            'authority_revision' => 'authority-set-v1:unavailable',
            'url_truth_revision' => 'url-truth-set-v1:unavailable',
        ];
    }
}
