<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class BigFiveResultPageV2AnalyticsHandoffTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/qa/analytics_handoff/v0_1';

    public function test_analytics_handoff_is_advisory_only(): void
    {
        $report = $this->jsonFile('big5_result_page_analytics_handoff_v0_1.json');
        $summary = $this->jsonFile('big5_result_page_analytics_handoff_summary_v0_1.json');

        foreach ([$report, $summary] as $document) {
            $this->assertSame('analytics_handoff_advisory', $document['mode'] ?? null);
            $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
            $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
            $this->assertTrue((bool) ($document['ready_for_analytics_review'] ?? false));
            $this->assertFalse((bool) ($document['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        }
    }

    public function test_metric_definitions_cover_growth_handoff_targets(): void
    {
        $report = $this->jsonFile('big5_result_page_analytics_handoff_v0_1.json');
        $summary = $this->jsonFile('big5_result_page_analytics_handoff_summary_v0_1.json');
        $metrics = (array) ($report['metric_definitions'] ?? []);

        $expectedKeys = [
            'full_report_view',
            'pdf_download',
            'share_create',
            'share_open',
            'second_test_rate',
            'returning_user_rate',
            'retention_d1',
            'retention_d7',
            'retention_d14',
            'retention_d28',
        ];

        $this->assertSame($expectedKeys, array_keys($metrics));
        $this->assertSame($expectedKeys, $summary['metric_keys'] ?? null);
        $this->assertSame(10, $summary['metric_count'] ?? null);

        foreach ($metrics as $metricKey => $metric) {
            $this->assertNotSame('', $metric['purpose'] ?? '', $metricKey);
            $this->assertNotSame('', $metric['numerator'] ?? '', $metricKey);
            $this->assertNotSame('', $metric['denominator'] ?? '', $metricKey);
            $this->assertContains('smoke_artifacts', $metric['required_exclusions'] ?? [], $metricKey);
            $this->assertContains('test_artifacts', $metric['required_exclusions'] ?? [], $metricKey);
        }
    }

    public function test_exclusion_policy_removes_smoke_test_and_generated_artifacts(): void
    {
        $report = $this->jsonFile('big5_result_page_analytics_handoff_v0_1.json');
        $summary = $this->jsonFile('big5_result_page_analytics_handoff_summary_v0_1.json');

        $expectedClasses = [
            'smoke_artifacts',
            'test_artifacts',
            'qa_artifacts',
            'synthetic_artifacts',
            'fixture_artifacts',
            'codex_artifacts',
            'generated_artifacts',
            'operator_only_artifacts',
            'internal_monitoring_artifacts',
        ];

        $this->assertSame($expectedClasses, data_get($report, 'exclusion_policy.excluded_artifact_classes'));
        $this->assertSame($expectedClasses, $summary['required_exclusion_classes'] ?? null);
        $this->assertContains('aggregate_counts', data_get($report, 'exclusion_policy.allowed_reporting_shape', []));
        $this->assertContains('aggregate_rates', data_get($report, 'exclusion_policy.allowed_reporting_shape', []));
        $this->assertContains('user_level_rows', data_get($report, 'exclusion_policy.disallowed_reporting_shape', []));
        $this->assertContains('direct_result_identifiers', data_get($report, 'exclusion_policy.disallowed_reporting_shape', []));
    }

    public function test_handoff_keeps_backend_authority_and_no_runtime_or_production_mutation(): void
    {
        $report = $this->jsonFile('big5_result_page_analytics_handoff_v0_1.json');
        $summary = $this->jsonFile('big5_result_page_analytics_handoff_summary_v0_1.json');

        $this->assertSame(0, data_get($report, 'source_readiness.share_safety_missing_count'));
        $this->assertSame(0, data_get($report, 'source_readiness.validation_error_count'));
        $this->assertSame(0, data_get($report, 'source_readiness.leak_hit_count'));
        $this->assertTrue((bool) data_get($report, 'source_readiness.readiness_pass'));

        foreach (($report['handoff_boundaries'] ?? []) as $key => $value) {
            $this->assertFalse((bool) $value, $key);
        }

        foreach (($summary['negative_guarantees'] ?? []) as $key => $value) {
            $this->assertFalse((bool) $value, $key);
        }

        $this->assertTrue((bool) ($summary['backend_authority'] ?? false));
        $this->assertFalse((bool) ($summary['frontend_copy_added'] ?? true));
        $this->assertTrue((bool) ($summary['production_blocked'] ?? false));
    }

    public function test_artifacts_are_redacted_and_do_not_store_private_or_internal_tokens(): void
    {
        $serialized = json_encode([
            $this->jsonFile('big5_result_page_analytics_handoff_v0_1.json'),
            $this->jsonFile('big5_result_page_analytics_handoff_summary_v0_1.json'),
            (string) file_get_contents(base_path(self::BASE_PATH.'/README.md')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        foreach ([
            'attempt_id',
            'private_url',
            'report_json',
            'report_full_json',
            'report_free_json',
            'Big Five Report Engine',
            'PR3B',
            'AttemptReadController',
            'payload',
            'registry',
            'raw_score',
            'raw_scores',
            'score_vector',
            'percentile',
            'percentiles',
            'internal_metadata',
            '[object Object]',
        ] as $forbiddenTerm) {
            $this->assertStringNotContainsString($forbiddenTerm, $serialized, $forbiddenTerm);
        }
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $entries = file(base_path(self::BASE_PATH.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($entries);
        $this->assertCount(3, $entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $path = base_path(self::BASE_PATH.'/'.$fileName);

            $this->assertFileExists($path);
            $this->assertSame($expectedHash, hash_file('sha256', $path));
        }
    }

    /**
     * @return array<int|string,mixed>
     */
    private function jsonFile(string $fileName): array
    {
        $json = file_get_contents(base_path(self::BASE_PATH.'/'.$fileName));
        $this->assertIsString($json);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
