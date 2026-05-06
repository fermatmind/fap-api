<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class BigFiveResultPageV2PublicPilotReadinessTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/qa/result_page_public_pilot_readiness/v0_1';

    public function test_public_pilot_readiness_policy_is_advisory_only(): void
    {
        $policy = $this->jsonFile('big5_result_page_public_pilot_readiness_policy_v0_1.json');
        $summary = $this->jsonFile('big5_result_page_public_pilot_readiness_summary_v0_1.json');

        foreach ([$policy, $summary] as $document) {
            $this->assertSame('result_page_public_pilot_readiness_policy_advisory', $document['mode'] ?? null);
            $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
            $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
            $this->assertTrue((bool) ($document['ready_for_asset_review'] ?? false));
            $this->assertFalse((bool) ($document['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
            $this->assertSame('ready_constrained', $document['controlled_pilot_status'] ?? null);
            $this->assertSame('go_result_page_only', $document['result_page_only_public_pilot_status'] ?? null);
            $this->assertSame('no_go', $document['production_status'] ?? null);
        }
    }

    public function test_policy_is_result_page_only_and_excludes_secondary_surfaces(): void
    {
        $policy = $this->jsonFile('big5_result_page_public_pilot_readiness_policy_v0_1.json');

        $this->assertSame([
            'result_page_desktop',
            'result_page_mobile',
        ], data_get($policy, 'scope.allowed_surfaces'));

        $this->assertSame([
            'pdf',
            'share_card',
            'history',
            'compare',
        ], data_get($policy, 'scope.excluded_surfaces'));

        foreach ($this->excludedSurfacesByKey($policy) as $surfaceKey => $surface) {
            $this->assertSame('disabled_or_pending', $surface['policy_status'] ?? null, $surfaceKey);
            $this->assertSame('pending_surface', $surface['rendered_status'] ?? null, $surfaceKey);
            $this->assertFalse((bool) ($surface['can_count_as_pass'] ?? true), $surfaceKey);
        }
    }

    public function test_result_page_evidence_supports_result_page_only_public_pilot_go(): void
    {
        $policy = $this->jsonFile('big5_result_page_public_pilot_readiness_policy_v0_1.json');
        $summary = $this->jsonFile('big5_result_page_public_pilot_readiness_summary_v0_1.json');

        $resultPageSurfaces = [];
        foreach ((array) ($policy['result_page_surface_evidence'] ?? []) as $surface) {
            $resultPageSurfaces[(string) ($surface['surface_key'] ?? '')] = $surface;
        }
        ksort($resultPageSurfaces);

        $this->assertSame(['result_page_desktop', 'result_page_mobile'], array_keys($resultPageSurfaces));
        foreach ($resultPageSurfaces as $surfaceKey => $surface) {
            $this->assertSame('pass', $surface['status'] ?? null, $surfaceKey);
            $this->assertNotSame([], $surface['evidence'] ?? [], $surfaceKey);
        }

        $this->assertSame(2, $summary['result_page_surface_pass_count'] ?? null);
        $this->assertSame(4, $summary['disabled_or_pending_surface_count'] ?? null);
        $this->assertSame(4, $summary['pending_surface_count'] ?? null);
        $this->assertSame(0, $summary['excluded_surface_pass_count'] ?? null);
        $this->assertTrue((bool) ($summary['public_pilot_rollout_gate_complete'] ?? false));
        $this->assertTrue((bool) ($summary['public_pilot_observability_complete'] ?? false));
        $this->assertTrue((bool) ($summary['public_pilot_smoke_fail_closed_complete'] ?? false));
        $this->assertTrue((bool) ($summary['fap_web_public_pilot_contract_complete'] ?? false));
        $this->assertTrue((bool) ($summary['final_go_no_go_report_complete'] ?? false));
        $this->assertTrue((bool) ($summary['result_page_only_public_pilot_can_enter'] ?? false));
        $this->assertFalse((bool) ($summary['public_pilot_full_surface_can_enter'] ?? true));
        $this->assertTrue((bool) ($summary['production_blocked'] ?? false));
    }

    public function test_final_go_no_go_report_allows_only_result_page_public_pilot(): void
    {
        $report = $this->jsonFile('big5_result_page_public_pilot_go_no_go_report_v0_1.json');

        $this->assertTrue((bool) data_get($report, 'decisions.controlled_pilot.can_enter'));
        $this->assertSame('go_result_page_only', data_get($report, 'decisions.result_page_only_public_pilot.status'));
        $this->assertTrue((bool) data_get($report, 'decisions.result_page_only_public_pilot.can_enter'));
        $this->assertSame([
            'result_page_desktop',
            'result_page_mobile',
        ], data_get($report, 'decisions.result_page_only_public_pilot.scope'));

        $this->assertSame('no_go', data_get($report, 'decisions.full_public_pilot.status'));
        $this->assertFalse((bool) data_get($report, 'decisions.full_public_pilot.can_enter'));
        $this->assertSame('no_go', data_get($report, 'decisions.production.status'));
        $this->assertFalse((bool) data_get($report, 'decisions.production.can_enter'));

        foreach (['pdf', 'share_card', 'history', 'compare'] as $surfaceKey) {
            $this->assertSame('disabled_or_pending', data_get($report, "surface_status.{$surfaceKey}.status"), $surfaceKey);
            $this->assertSame('pending_surface', data_get($report, "surface_status.{$surfaceKey}.rendered_status"), $surfaceKey);
            $this->assertFalse((bool) data_get($report, "surface_status.{$surfaceKey}.can_count_as_pass"), $surfaceKey);
        }
    }

    public function test_forbidden_public_terms_are_listed_without_becoming_evidence(): void
    {
        $policy = $this->jsonFile('big5_result_page_public_pilot_readiness_policy_v0_1.json');
        $forbidden = (array) ($policy['forbidden_public_terms'] ?? []);

        foreach ([
            'frontend_fallback',
            'internal_metadata',
            'selector_basis',
            'source_reference',
            'production_use_allowed',
            'runtime_use',
            '[object Object]',
        ] as $term) {
            $this->assertContains($term, $forbidden);
        }

        $evidenceJson = json_encode($policy['result_page_surface_evidence'] ?? [], JSON_THROW_ON_ERROR);
        foreach ($forbidden as $term) {
            $this->assertStringNotContainsString((string) $term, $evidenceJson);
        }
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $entries = file(base_path(self::BASE_PATH.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($entries);
        $this->assertCount(4, $entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $path = base_path(self::BASE_PATH.'/'.$fileName);

            $this->assertFileExists($path);
            $this->assertSame($expectedHash, hash_file('sha256', $path));
        }
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function excludedSurfacesByKey(array $policy): array
    {
        $surfaces = [];
        foreach ((array) ($policy['excluded_surface_policy'] ?? []) as $surface) {
            $surfaces[(string) ($surface['surface_key'] ?? '')] = $surface;
        }
        ksort($surfaces);

        return $surfaces;
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
