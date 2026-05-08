<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class BigFiveResultPageV2AllSurfacePublicPilotReadinessTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/qa/all_surface_public_pilot_readiness/v0_1';

    public function test_all_surface_readiness_report_is_advisory_only(): void
    {
        $report = $this->jsonFile('big5_all_surface_public_pilot_readiness_report_v0_1.json');
        $summary = $this->jsonFile('big5_all_surface_public_pilot_readiness_summary_v0_1.json');

        foreach ([$report, $summary] as $document) {
            $this->assertSame('all_surface_public_pilot_readiness_report_advisory', $document['mode'] ?? null);
            $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
            $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
            $this->assertTrue((bool) ($document['ready_for_asset_review'] ?? false));
            $this->assertFalse((bool) ($document['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        }
    }

    public function test_all_surfaces_have_real_evidence_before_pass(): void
    {
        $report = $this->jsonFile('big5_all_surface_public_pilot_readiness_report_v0_1.json');
        $surfaces = (array) ($report['surface_status'] ?? []);

        $this->assertSame([
            'compare',
            'history',
            'pdf',
            'result_page_desktop',
            'result_page_mobile',
            'share_card',
        ], array_keys($this->ksort($surfaces)));

        foreach ($surfaces as $surfaceKey => $surface) {
            $this->assertSame('pass', $surface['status'] ?? null, $surfaceKey);
            $this->assertNotSame([], $surface['adapter_evidence'] ?? [], $surfaceKey);
            $this->assertNotSame([], $surface['rendered_qa_evidence'] ?? [], $surfaceKey);
            $this->assertNotSame([], $surface['fail_closed_evidence'] ?? [], $surfaceKey);
            $this->assertNotSame([], $surface['metadata_leak_evidence'] ?? [], $surfaceKey);
        }
    }

    public function test_public_pilot_and_production_decisions_are_separated(): void
    {
        $report = $this->jsonFile('big5_all_surface_public_pilot_readiness_report_v0_1.json');
        $summary = $this->jsonFile('big5_all_surface_public_pilot_readiness_summary_v0_1.json');

        $this->assertSame('ready_constrained', data_get($report, 'decisions.controlled_pilot.status'));
        $this->assertTrue((bool) data_get($report, 'decisions.controlled_pilot.can_enter'));
        $this->assertSame('go_result_page_only', data_get($report, 'decisions.result_page_only_public_pilot.status'));
        $this->assertTrue((bool) data_get($report, 'decisions.result_page_only_public_pilot.can_enter'));
        $this->assertSame('go_all_surfaces_public_pilot', data_get($report, 'decisions.all_surface_public_pilot.status'));
        $this->assertTrue((bool) data_get($report, 'decisions.all_surface_public_pilot.can_enter'));
        $this->assertSame('no_go', data_get($report, 'decisions.production.status'));
        $this->assertFalse((bool) data_get($report, 'decisions.production.can_enter'));

        $this->assertSame(6, $summary['surface_count'] ?? null);
        $this->assertSame(6, $summary['pass_surface_count'] ?? null);
        $this->assertSame(0, $summary['pending_surface_count'] ?? null);
        $this->assertSame(0, $summary['fake_pass_count'] ?? null);
        $this->assertTrue((bool) ($summary['all_surface_public_pilot_can_enter'] ?? false));
        $this->assertTrue((bool) ($summary['production_blocked'] ?? false));
        $this->assertTrue((bool) ($summary['cms_out_of_scope'] ?? false));
        $this->assertTrue((bool) ($summary['dynamic_norms_out_of_scope'] ?? false));
    }

    public function test_forbidden_public_terms_are_not_surface_evidence(): void
    {
        $report = $this->jsonFile('big5_all_surface_public_pilot_readiness_report_v0_1.json');
        $forbidden = (array) ($report['forbidden_public_terms'] ?? []);

        foreach ([
            'frontend_fallback',
            'internal_metadata',
            'selector_basis',
            'source_reference',
            'production_use_allowed',
            'runtime_use',
            'review_status',
            'qa_notes',
            '[object Object]',
        ] as $term) {
            $this->assertContains($term, $forbidden);
        }

        $surfaceEvidenceJson = json_encode($report['surface_status'] ?? [], JSON_THROW_ON_ERROR);
        foreach ($forbidden as $term) {
            $this->assertStringNotContainsString((string) $term, $surfaceEvidenceJson, (string) $term);
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
