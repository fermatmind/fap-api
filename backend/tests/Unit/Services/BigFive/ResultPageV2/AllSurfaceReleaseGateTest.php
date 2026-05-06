<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class AllSurfaceReleaseGateTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/qa/production_all_surface_release_gate/v0_1';

    private const SURFACES = [
        'desktop',
        'mobile',
        'pdf',
        'share_card',
        'history',
        'compare',
    ];

    private const REQUIRED_CHECKS = [
        'rendered_qa',
        'metadata_leak',
        'fail_closed',
        'must_render',
        'must_not_render',
    ];

    public function test_release_gate_package_exists_without_production_enablement(): void
    {
        $manifest = $this->jsonFile('manifest.json');
        $report = $this->jsonFile('big5_v2_production_all_surface_release_gate_report_v0_1.json');
        $summary = $this->jsonFile('big5_v2_production_all_surface_release_gate_summary_v0_1.json');

        $this->assertSame('big5_v2_production_all_surface_release_gate', $manifest['package'] ?? null);
        foreach ([$manifest, $report, $summary] as $document) {
            $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
            $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
            $this->assertFalse((bool) ($document['production_rollout_enabled'] ?? true));
        }

        $this->assertSame('no_go', $report['production_status'] ?? null);
        $this->assertTrue((bool) ($report['production_disabled'] ?? false));
    }

    public function test_all_surfaces_have_required_release_gate_checks(): void
    {
        $report = $this->jsonFile('big5_v2_production_all_surface_release_gate_report_v0_1.json');
        $surfaces = (array) ($report['surfaces'] ?? []);

        $this->assertSame(self::SURFACES, array_keys($surfaces));
        $this->assertSame([], $this->validateReleaseGate($report));

        foreach ($surfaces as $surfaceKey => $surface) {
            $this->assertSame('pass', $surface['status'] ?? null, (string) $surfaceKey);
            $checks = (array) ($surface['checks'] ?? []);
            $this->assertSame(self::REQUIRED_CHECKS, array_keys($checks), (string) $surfaceKey);

            foreach ($checks as $checkKey => $check) {
                $this->assertSame('pass', $check['status'] ?? null, $surfaceKey.'.'.$checkKey);
                $this->assertNotSame([], $check['evidence'] ?? [], $surfaceKey.'.'.$checkKey);
            }
        }
    }

    public function test_release_gate_rejects_fake_pass_and_pending_surface(): void
    {
        $report = $this->jsonFile('big5_v2_production_all_surface_release_gate_report_v0_1.json');
        $fake = $report;
        $fake['surfaces']['pdf']['checks']['rendered_qa']['evidence'] = [];
        $pending = $report;
        $pending['surfaces']['history']['status'] = 'pending';
        $pending['surfaces']['history']['checks']['rendered_qa']['status'] = 'pass';

        $this->assertContains('pdf.rendered_qa.missing_evidence', $this->validateReleaseGate($fake));
        $this->assertContains('history.status_not_pass', $this->validateReleaseGate($pending));
    }

    public function test_regression_scan_exists_and_blocks_metadata_leaks(): void
    {
        $scan = $this->jsonFile('big5_v2_production_all_surface_regression_scan_v0_1.json');

        $this->assertSame('pass', $scan['scan_status'] ?? null);
        $this->assertSame(self::SURFACES, $scan['surfaces_scanned'] ?? null);
        foreach ([
            'internal_metadata',
            'selector_basis',
            'source_reference',
            'runtime_use',
            'production_use_allowed',
            'review_status',
            'qa_notes',
            '[object Object]',
        ] as $term) {
            $this->assertContains($term, $scan['forbidden_public_terms'] ?? []);
        }

        foreach ((array) ($scan['checks'] ?? []) as $check => $status) {
            $this->assertSame('pass', $status, (string) $check);
        }
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $entries = file(base_path(self::BASE_PATH.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($entries);
        $this->assertCount(5, $entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $path = base_path(self::BASE_PATH.'/'.$fileName);

            $this->assertFileExists($path);
            $this->assertSame($expectedHash, hash_file('sha256', $path), $fileName);
        }
    }

    /**
     * @param  array<string,mixed>  $report
     * @return list<string>
     */
    private function validateReleaseGate(array $report): array
    {
        $errors = [];
        foreach (self::SURFACES as $surfaceKey) {
            $surface = (array) data_get($report, 'surfaces.'.$surfaceKey, []);
            if (($surface['status'] ?? null) !== 'pass') {
                $errors[] = $surfaceKey.'.status_not_pass';
            }

            foreach (self::REQUIRED_CHECKS as $checkKey) {
                $check = (array) data_get($surface, 'checks.'.$checkKey, []);
                if (($check['status'] ?? null) !== 'pass') {
                    $errors[] = $surfaceKey.'.'.$checkKey.'.status_not_pass';
                }

                if (($check['evidence'] ?? []) === []) {
                    $errors[] = $surfaceKey.'.'.$checkKey.'.missing_evidence';
                }
            }
        }

        return $errors;
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $fileName): array
    {
        $decoded = json_decode(
            (string) file_get_contents(base_path(self::BASE_PATH.'/'.$fileName)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
