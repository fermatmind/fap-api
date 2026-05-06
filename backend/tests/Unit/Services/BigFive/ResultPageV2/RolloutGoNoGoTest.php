<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class RolloutGoNoGoTest extends TestCase
{
    private const PACKAGE_PATH = 'content_assets/big5/result_page_v2/qa/production_rollout_go_no_go/v0_1';

    public function test_go_no_go_package_exists_and_blocks_automatic_rollout(): void
    {
        $manifest = $this->loadJsonDocument('manifest.json');
        $report = $this->loadJsonDocument('big5_v2_production_rollout_go_no_go_report_v0_1.json');

        $this->assertSame('production_rollout_go_no_go', $manifest['package']);
        $this->assertSame('NO_GO', $manifest['production_decision']);
        $this->assertSame('NO_GO', $report['production_decision']);
        $this->assertFalse((bool) $report['rollout_go_recommended']);
        $this->assertTrue((bool) $report['human_approval_required_before_go']);
        $this->assertContains(
            'phase_0_keep_disabled_and_review_evidence',
            $report['recommended_rollout_phases'],
        );
        $this->assertDisabled($manifest);
        $this->assertDisabled($report);

        foreach ($manifest['files'] as $file) {
            $this->assertFileExists($this->packagePath($file));
        }
    }

    public function test_go_no_go_matrix_requires_human_approval_and_operator_assignment(): void
    {
        $matrix = $this->loadJsonDocument('big5_v2_production_rollout_go_no_go_matrix_v0_1.json');

        $rows = collect($matrix['matrix'])->keyBy('area');

        $this->assertSame('ready_disabled_by_default', $rows['rollout_gate']['status']);
        $this->assertSame('missing_by_design', $rows['human_production_rollout_approval']['status']);
        $this->assertTrue((bool) $rows['human_production_rollout_approval']['blocks_go']);
        $this->assertTrue((bool) $rows['incident_response']['blocks_go']);
        $this->assertSame('safe_disabled', $rows['production_safety']['status']);
        $this->assertDisabled($matrix);
    }

    public function test_dependency_graph_preserves_manual_approval_as_terminal_step(): void
    {
        $graph = $this->loadJsonDocument('big5_v2_production_rollout_dependency_graph_v0_1.json');

        $this->assertContains('production_rollout_go_no_go_report', $graph['nodes']);
        $this->assertContains('explicit_human_production_rollout_approval', $graph['nodes']);
        $this->assertContains(
            ['production_rollout_go_no_go_report', 'explicit_human_production_rollout_approval'],
            $graph['edges'],
        );
        $this->assertSame('NO_GO', $graph['terminal_decision']['automatic_rollout']);
        $this->assertDisabled($graph);
    }

    public function test_validation_links_rollout_readiness_without_enabling_production(): void
    {
        $validation = $this->loadJsonDocument('big5_v2_production_rollout_validation_v0_1.json');

        $this->assertSame('pass', $validation['validation_status']);
        $this->assertSame('NO_GO', $validation['production_decision']);
        $this->assertSame('blocked', $validation['checks']['production_rollout_enablement']);
        $this->assertSame('missing_by_design', $validation['checks']['explicit_human_rollout_approval']);
        $this->assertContains(
            'explicit_human_production_rollout_approval_missing',
            $validation['hard_blockers_before_real_rollout'],
        );
        $this->assertDisabled($validation);
    }

    public function test_default_runtime_and_rollout_config_remain_disabled(): void
    {
        $this->assertFalse((bool) config('big5_result_page_v2.production_runtime_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_configured'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_manual_approval_granted'));
        $this->assertSame(0, (int) config('big5_result_page_v2.production_rollout_percentage'));
        $this->assertSame(0, (int) config('big5_result_page_v2.production_rollout_max_percentage'));
        $this->assertSame('disabled', config('big5_result_page_v2.production_rollout_mode'));
    }

    public function test_files_do_not_enable_rollout_or_expose_blocked_metadata(): void
    {
        foreach (glob($this->packagePath('*')) ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }

            $normalized = preg_replace('/\s+/', '', (string) file_get_contents($file));

            $this->assertStringNotContainsString('"production_use_allowed":true', $normalized, $file);
            $this->assertStringNotContainsString('"ready_for_production":true', $normalized, $file);
            $this->assertStringNotContainsString('"production_rollout_enabled":true', $normalized, $file);
            $this->assertStringNotContainsString('"rollout_allowed":true', $normalized, $file);
            $this->assertStringNotContainsString('"internal_metadata"', $normalized, $file);
            $this->assertStringNotContainsString('"selector_basis"', $normalized, $file);
            $this->assertStringNotContainsString('"source_reference"', $normalized, $file);
            $this->assertStringNotContainsString('"review_status"', $normalized, $file);
            $this->assertStringNotContainsString('"qa_notes"', $normalized, $file);
            $this->assertStringNotContainsString('[objectObject]', $normalized, $file);
        }
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $lines = array_values(array_filter(array_map('trim', explode(
            "\n",
            (string) file_get_contents($this->packagePath('SHA256SUMS')),
        ))));

        $this->assertNotSame([], $lines);

        foreach ($lines as $line) {
            [$hash, $file] = preg_split('/\s+/', $line, 2) ?: ['', ''];
            $file = trim($file);

            $this->assertSame(
                hash_file('sha256', $this->packagePath($file)),
                $hash,
                $file,
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function loadJsonDocument(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($this->packagePath($file)), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function packagePath(string $file): string
    {
        return base_path(self::PACKAGE_PATH.'/'.$file);
    }

    /**
     * @param  array<string,mixed>  $document
     */
    private function assertDisabled(array $document): void
    {
        $this->assertSame('not_runtime', $document['runtime_use']);
        $this->assertFalse((bool) $document['production_use_allowed']);
        $this->assertFalse((bool) $document['ready_for_production']);
        $this->assertFalse((bool) $document['production_rollout_enabled']);
        $this->assertFalse((bool) $document['rollout_allowed']);
    }
}
