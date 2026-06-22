<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class BigFiveResultPageV2FreeFullReportRuntimeQaReadinessTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/qa/free_full_report_runtime_qa_readiness/v0_1';

    public function test_free_full_report_runtime_qa_readiness_is_advisory_only(): void
    {
        $report = $this->jsonFile('big5_free_full_report_runtime_qa_readiness_report_v0_1.json');
        $summary = $this->jsonFile('big5_free_full_report_runtime_qa_readiness_summary_v0_1.json');

        foreach ([$report, $summary] as $document) {
            $this->assertSame('free_full_report_runtime_qa_readiness_advisory', $document['mode'] ?? null);
            $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
            $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
            $this->assertTrue((bool) ($document['ready_for_runtime_qa'] ?? false));
            $this->assertTrue((bool) ($document['ready_for_asset_review'] ?? false));
            $this->assertFalse((bool) ($document['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        }
    }

    public function test_all_free_full_report_surfaces_have_backend_authority_and_private_boundaries(): void
    {
        $report = $this->jsonFile('big5_free_full_report_runtime_qa_readiness_report_v0_1.json');
        $surfaces = (array) ($report['surface_status'] ?? []);

        $this->assertSame([
            'compare',
            'history',
            'pdf',
            'report_access_api',
            'report_api',
            'result_route',
            'share',
        ], array_keys($this->ksort($surfaces)));

        foreach ($surfaces as $surfaceKey => $surface) {
            $this->assertSame('pass', $surface['status'] ?? null, $surfaceKey);
            $this->assertTrue((bool) ($surface['backend_authority'] ?? false), $surfaceKey);
            $this->assertTrue((bool) ($surface['no_paywall_contradiction'] ?? false), $surfaceKey);
            $this->assertSame('pass', $surface['private_boundary'] ?? null, $surfaceKey);
            $this->assertNotSame([], $surface['evidence'] ?? [], $surfaceKey);
        }

        $this->assertSame([
            'pass' => 7,
            'pending' => 0,
            'fail' => 0,
        ], $report['status_counts'] ?? null);
    }

    public function test_free_full_report_summary_uses_closed_share_safety_and_zero_leak_evidence(): void
    {
        $report = $this->jsonFile('big5_free_full_report_runtime_qa_readiness_report_v0_1.json');
        $summary = $this->jsonFile('big5_free_full_report_runtime_qa_readiness_summary_v0_1.json');

        $this->assertSame(0, data_get($report, 'source_readiness.share_safety_missing_count'));
        $this->assertSame(13, data_get($report, 'source_readiness.share_safe_reading_mode_count'));
        $this->assertSame(0, data_get($report, 'source_readiness.validation_error_count'));
        $this->assertSame(0, data_get($report, 'source_readiness.leak_hit_count'));
        $this->assertTrue((bool) data_get($report, 'source_readiness.readiness_pass'));

        $this->assertSame(0, $summary['share_safety_missing_count'] ?? null);
        $this->assertSame(0, $summary['validation_error_count'] ?? null);
        $this->assertSame(0, $summary['leak_hit_count'] ?? null);
        $this->assertSame(7, $summary['surface_count'] ?? null);
        $this->assertSame(7, $summary['pass_surface_count'] ?? null);
        $this->assertTrue((bool) ($summary['no_paywall_contradiction'] ?? false));
        $this->assertTrue((bool) ($summary['private_boundary_pass'] ?? false));
        $this->assertTrue((bool) ($summary['backend_authority_pass'] ?? false));
        $this->assertTrue((bool) ($summary['production_blocked'] ?? false));
    }

    public function test_artifacts_are_redacted_and_do_not_store_private_or_internal_tokens(): void
    {
        $serialized = json_encode([
            $this->jsonFile('big5_free_full_report_runtime_qa_readiness_report_v0_1.json'),
            $this->jsonFile('big5_free_full_report_runtime_qa_readiness_summary_v0_1.json'),
            (string) file_get_contents(base_path(self::BASE_PATH.'/README.md')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        foreach ([
            'attempt_id',
            'private_url',
            'private URL',
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
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function ksort(array $values): array
    {
        ksort($values);

        return $values;
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
