<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use Tests\TestCase;

final class RiasecResultPageSelectorCoverageBatchTest extends TestCase
{
    public function test_selector_coverage_batch_is_staging_only_and_covers_expected_route_groups(): void
    {
        $manifest = $this->readJson($this->backendPath(
            'content_assets/riasec/result_page_v2/selector_ready_assets/selector_coverage_batch_20260622T0948Z/manifest.json'
        ));
        $assets = $this->readJsonLines($this->backendPath(
            'content_assets/riasec/result_page_v2/selector_ready_assets/selector_coverage_batch_20260622T0948Z/assets.jsonl'
        ));

        $this->assertSame('RIASEC-RESULT-SELECTOR-COVERAGE-BATCH-01', $manifest['task_id'] ?? null);
        $this->assertSame('staging_only', $manifest['runtime_use'] ?? null);
        $this->assertFalse((bool) ($manifest['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($manifest['ready_for_runtime'] ?? true));
        $this->assertFalse((bool) ($manifest['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($manifest['cms_write_performed'] ?? true));
        $this->assertFalse((bool) ($manifest['runtime_change_performed'] ?? true));
        $this->assertFalse((bool) ($manifest['frontend_fallback_allowed'] ?? true));
        $this->assertFalse((bool) ($manifest['private_payload_exported'] ?? true));
        $this->assertFalse((bool) ($manifest['selector_ready_candidate'] ?? true));
        $this->assertFalse((bool) ($manifest['import_allowed'] ?? true));
        $this->assertFalse((bool) ($manifest['runtime_selector_wiring_allowed'] ?? true));
        $this->assertTrue((bool) ($manifest['staging_review_only'] ?? false));

        $this->assertCount(5, $assets);
        $this->assertSame(5, (int) ($manifest['asset_count'] ?? 0));
        $this->assertSame([
            'profile_signature_registry' => 3,
            'method_boundary_registry' => 2,
        ], $manifest['registries'] ?? []);

        $routeGroups = array_values(array_map(
            static fn (array $asset): string => (string) data_get($asset, 'agent_metadata.route_group'),
            $assets
        ));
        sort($routeGroups);
        $this->assertSame([
            'clear_primary',
            'low_quality',
            'near_tie',
            'norm_unavailable',
            'top3',
        ], $routeGroups);

        $registryCounts = array_count_values(array_map(
            static fn (array $asset): string => (string) ($asset['registry_key'] ?? ''),
            $assets
        ));
        $this->assertSame(3, $registryCounts['profile_signature_registry'] ?? 0);
        $this->assertSame(2, $registryCounts['method_boundary_registry'] ?? 0);
    }

    public function test_selector_coverage_batch_assets_keep_selector_contract_and_public_payload_allowlist(): void
    {
        $assets = $this->readJsonLines($this->backendPath(
            'content_assets/riasec/result_page_v2/selector_ready_assets/selector_coverage_batch_20260622T0948Z/assets.jsonl'
        ));
        $forbiddenPublicFields = [
            'attempt_id',
            'user_id',
            'private_url',
            'private_path',
            'raw_score',
            'raw_scores',
            'score_vector',
            'dimension_vector',
            'percentile',
            'percentiles',
            'editor_notes',
            'qa_notes',
            'internal_metadata',
        ];

        foreach ($assets as $asset) {
            $this->assertSame('fap.riasec.result_page_v2.selector_asset.v0.1', $asset['version'] ?? null);
            $this->assertIsString($asset['asset_key'] ?? null);
            $this->assertIsString($asset['slot_key'] ?? null);
            $this->assertIsArray($asset['trigger'] ?? null);
            $this->assertIsInt($asset['priority'] ?? null);
            $this->assertIsString($asset['mutual_exclusion_group'] ?? null);
            $this->assertContains($asset['fallback_policy'] ?? null, [
                'omit_result_page_v2_modules',
                'omit_norm_comparison',
            ]);
            $this->assertSame('staging_candidate_not_imported', $asset['review_status'] ?? null);
            $this->assertSame('staging_only', data_get($asset, 'provenance.runtime_use'));
            $this->assertFalse((bool) data_get($asset, 'provenance.production_use_allowed', true));
            $this->assertFalse((bool) data_get($asset, 'agent_metadata.ready_for_runtime', true));
            $this->assertFalse((bool) data_get($asset, 'agent_metadata.ready_for_production', true));
            $this->assertFalse((bool) ($asset['shareable'] ?? true));

            $publicPayload = $asset['public_payload'] ?? null;
            $this->assertIsArray($publicPayload);
            foreach ($forbiddenPublicFields as $forbiddenPublicField) {
                $this->assertArrayNotHasKey($forbiddenPublicField, $publicPayload);
            }
        }
    }

    public function test_selector_coverage_batch_reports_pass_without_runtime_or_public_leaks(): void
    {
        $runRoot = 'content_assets/riasec/result_page_v2/agent_runs/selector_coverage_batch_20260622T0948Z';
        $validation = $this->readJson($this->backendPath($runRoot.'/validation_report.json'));
        $safety = $this->readJson($this->backendPath($runRoot.'/safety_report.json'));

        $this->assertSame('staging_only', $validation['runtime_use'] ?? null);
        $this->assertFalse((bool) ($validation['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($validation['ready_for_runtime'] ?? true));
        $this->assertFalse((bool) ($validation['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($validation['cms_write_performed'] ?? true));
        $this->assertFalse((bool) ($validation['runtime_change_performed'] ?? true));
        $this->assertFalse((bool) ($validation['frontend_fallback_allowed'] ?? true));
        $this->assertSame(5, (int) ($validation['final_asset_count'] ?? 0));
        $this->assertSame(0, (int) ($validation['error_count'] ?? -1));
        $this->assertTrue((bool) data_get($validation, 'selector_contract_checks.public_payload_allowlist_checked'));

        $this->assertSame('pass', $safety['status'] ?? null);
        $this->assertFalse((bool) ($safety['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($safety['private_payload_exported'] ?? true));
        $this->assertSame([], $safety['public_payload_forbidden_field_hits'] ?? null);
        $this->assertSame([], $safety['share_leak_hits'] ?? null);
        foreach (($safety['forbidden_claim_checks'] ?? []) as $blocked) {
            $this->assertFalse((bool) $blocked);
        }
    }

    private function backendPath(string $relativePath): string
    {
        return dirname(__DIR__, 4).'/'.$relativePath;
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonLines(string $path): array
    {
        $lines = array_values(array_filter(
            explode("\n", trim((string) file_get_contents($path))),
            static fn (string $line): bool => $line !== ''
        ));

        return array_map(function (string $line): array {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded);

            return $decoded;
        }, $lines);
    }
}
