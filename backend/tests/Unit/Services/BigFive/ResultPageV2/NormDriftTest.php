<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\Norms\BigFiveNormDriftDetector;
use Tests\TestCase;

final class NormDriftTest extends TestCase
{
    private const QA_PATH = 'content_assets/big5/result_page_v2/qa/norm_drift_monitoring/v0_1';

    public function test_drift_detector_emits_recomputation_percentile_and_aggregation_alerts(): void
    {
        $result = (new BigFiveNormDriftDetector())->analyze(
            $this->recomputeResult('baseline', 20.0, 10.0, 100, ['obs-a' => ['O' => 40]]),
            $this->recomputeResult('current', 31.0, 16.0, 150, ['obs-a' => ['O' => 70]]),
            ['mean_delta' => 5.0, 'sd_delta' => 2.0, 'percentile_delta' => 15, 'sample_n_delta_ratio' => 0.25],
        )->toArray();

        $this->assertSame('big5_norm_drift_detection', data_get($result, 'summary.mode'));
        $this->assertSame('alert', data_get($result, 'summary.status'));
        $this->assertSame(4, data_get($result, 'summary.alert_count'));
        $this->assertSame(2, data_get($result, 'summary.recomputation_drift_alerts'));
        $this->assertSame(1, data_get($result, 'summary.percentile_anomaly_alerts'));
        $this->assertSame(1, data_get($result, 'summary.aggregation_anomaly_alerts'));
        $this->assertSame('disabled', data_get($result, 'summary.public_percentile_display'));
        $this->assertSame('disabled', data_get($result, 'summary.runtime_attachment'));
        $this->assertFalse((bool) data_get($result, 'summary.public_exposure_allowed'));
        $this->assertTrue((bool) data_get($result, 'rebuild_evidence.rebuild_review_required'));
        $this->assertContains('percentile_anomaly.percentile_drift.O', data_get($result, 'rebuild_evidence.alerts'));

        foreach ($result['alerts'] as $alert) {
            $this->assertFalse((bool) ($alert['public_exposure_allowed'] ?? true));
            $this->assertArrayNotHasKey('user_id', $alert);
            $this->assertArrayNotHasKey('anon_id', $alert);
        }
    }

    public function test_drift_detector_returns_clear_when_thresholds_are_not_crossed(): void
    {
        $result = (new BigFiveNormDriftDetector())->analyze(
            $this->recomputeResult('baseline', 20.0, 10.0, 100, ['obs-a' => ['O' => 50]]),
            $this->recomputeResult('current', 21.0, 10.5, 105, ['obs-a' => ['O' => 55]]),
        )->toArray();

        $this->assertSame('clear', data_get($result, 'summary.status'));
        $this->assertSame(0, data_get($result, 'summary.alert_count'));
        $this->assertFalse((bool) data_get($result, 'rebuild_evidence.rebuild_review_required'));
        $this->assertSame([], $result['alerts']);
    }

    public function test_rebuild_evidence_package_documents_disabled_public_runtime_state(): void
    {
        $manifest = $this->jsonFile('manifest.json');
        $report = $this->jsonFile('big5_v2_norm_drift_monitoring_report_v0_1.json');
        $alerts = $this->jsonFile('big5_v2_norm_drift_alerts_v0_1.json');

        $this->assertSame('big5_v2_norm_drift_monitoring', $manifest['package'] ?? null);
        $this->assertTrue((bool) ($report['drift_detection_required'] ?? false));
        $this->assertTrue((bool) ($report['rebuild_evidence_required'] ?? false));
        $this->assertTrue((bool) ($alerts['percentile_anomaly_alert_required'] ?? false));
        $this->assertSafetyDefaults($manifest);
        $this->assertSafetyDefaults($report);
        $this->assertSafetyDefaults($alerts);
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $entries = file(base_path(self::QA_PATH.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $this->assertSame($expectedHash, hash_file('sha256', base_path(self::QA_PATH.'/'.$fileName)), $fileName);
        }
    }

    /**
     * @param  array<string,array<string,int>>  $percentiles
     * @return array<string,mixed>
     */
    private function recomputeResult(string $version, float $mean, float $sd, int $observationCount, array $percentiles): array
    {
        return [
            'summary' => [
                'snapshot_version' => 'big5_norm_snapshot_'.$version,
                'snapshot_hash' => hash('sha256', $version),
                'output_hash' => hash('sha256', $version.'-output'),
                'observation_count' => $observationCount,
                'public_percentile_display' => 'disabled',
                'runtime_attachment' => 'disabled',
            ],
            'metrics' => [
                'O' => [
                    'mean' => $mean,
                    'sd' => $sd,
                    'sample_n' => $observationCount,
                ],
            ],
            'internal_percentiles' => $percentiles,
        ];
    }

    /**
     * @param  array<string,mixed>  $document
     */
    private function assertSafetyDefaults(array $document): void
    {
        $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
        $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($document['production_rollout_enabled'] ?? true));
        $this->assertFalse((bool) ($document['dynamic_norm_engine_attached'] ?? true));
        $this->assertFalse((bool) ($document['public_percentile_display_enabled'] ?? true));
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $fileName): array
    {
        $json = file_get_contents(base_path(self::QA_PATH.'/'.$fileName));
        $this->assertIsString($json, $fileName);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, $fileName);

        return $decoded;
    }
}
