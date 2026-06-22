<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class BigFiveResultPageV2SeoGeoControlHandoffTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/qa/seo_geo_control_handoff/v0_1';

    public function test_seo_geo_handoff_is_advisory_only(): void
    {
        $report = $this->jsonFile('big5_result_page_seo_geo_control_handoff_v0_1.json');
        $summary = $this->jsonFile('big5_result_page_seo_geo_control_handoff_summary_v0_1.json');

        foreach ([$report, $summary] as $document) {
            $this->assertSame('seo_geo_control_handoff_advisory', $document['mode'] ?? null);
            $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
            $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
            $this->assertTrue((bool) ($document['ready_for_seo_geo_review'] ?? false));
            $this->assertFalse((bool) ($document['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        }
    }

    public function test_control_surfaces_cover_big_five_seo_geo_plan_without_publish(): void
    {
        $report = $this->jsonFile('big5_result_page_seo_geo_control_handoff_v0_1.json');
        $summary = $this->jsonFile('big5_result_page_seo_geo_control_handoff_summary_v0_1.json');
        $surfaces = (array) ($report['control_surfaces'] ?? []);

        $expectedKeys = [
            'big_five_hub',
            'big_five_vs_mbti',
            'methodology',
            'ocean_trait_explainers',
        ];

        $this->assertSame($expectedKeys, array_keys($surfaces));
        $this->assertSame($expectedKeys, $summary['surface_keys'] ?? null);
        $this->assertSame(4, $summary['surface_count'] ?? null);

        foreach ($surfaces as $surfaceKey => $surface) {
            $this->assertSame('planned_control_only', $surface['status'] ?? null, $surfaceKey);
            $this->assertNotSame('', $surface['purpose'] ?? '', $surfaceKey);
            $this->assertNotSame([], $surface['primary_intents'] ?? [], $surfaceKey);
            $this->assertContains('seo_control_agent', [$surface['next_owner'] ?? null], $surfaceKey);
        }
    }

    public function test_geo_aeo_controls_and_blocked_actions_are_explicit(): void
    {
        $report = $this->jsonFile('big5_result_page_seo_geo_control_handoff_v0_1.json');
        $summary = $this->jsonFile('big5_result_page_seo_geo_control_handoff_summary_v0_1.json');

        $this->assertSame(4, $summary['geo_aeo_control_count'] ?? null);
        $this->assertSame(14, $summary['blocked_action_count'] ?? null);
        $this->assertContains('cms_write', $report['blocked_actions'] ?? []);
        $this->assertContains('frontend_copy_write', $report['blocked_actions'] ?? []);
        $this->assertContains('runtime_seo_metadata_change', $report['blocked_actions'] ?? []);
        $this->assertContains('search_submission', $report['blocked_actions'] ?? []);
        $this->assertContains('production_deploy', $report['blocked_actions'] ?? []);
        $this->assertSame('planned_control_only', data_get($report, 'geo_aeo_controls.status'));
    }

    public function test_handoff_keeps_backend_authority_and_no_runtime_or_production_mutation(): void
    {
        $report = $this->jsonFile('big5_result_page_seo_geo_control_handoff_v0_1.json');
        $summary = $this->jsonFile('big5_result_page_seo_geo_control_handoff_summary_v0_1.json');

        $this->assertSame(0, data_get($report, 'source_readiness.share_safety_missing_count'));
        $this->assertSame(0, data_get($report, 'source_readiness.validation_error_count'));
        $this->assertSame(0, data_get($report, 'source_readiness.leak_hit_count'));
        $this->assertTrue((bool) data_get($report, 'source_readiness.analytics_handoff_available'));
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
            $this->jsonFile('big5_result_page_seo_geo_control_handoff_v0_1.json'),
            $this->jsonFile('big5_result_page_seo_geo_control_handoff_summary_v0_1.json'),
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
