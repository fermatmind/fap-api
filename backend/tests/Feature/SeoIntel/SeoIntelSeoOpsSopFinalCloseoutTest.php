<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSeoOpsSopFinalCloseoutTest extends TestCase
{
    #[Test]
    public function closeout_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-ops-sop-final-closeout.md'));

        $artifact = $this->artifact();

        $this->assertSame('seo-ops-sop-final-closeout.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-OPS-SOP-01F', $artifact['task'] ?? null);
        $this->assertSame('SEO-OPS-SOP-PR-TRAIN-01', $artifact['train'] ?? null);
        $this->assertSame('docs_generated_test_state_only', $artifact['type'] ?? null);
    }

    #[Test]
    public function completed_prs_are_recorded_with_urls(): void
    {
        $completed = collect($this->artifact()['completed_prs'] ?? []);

        foreach ([
            'SEO-OPS-SOP-01A' => 'https://github.com/fermatmind/fap-api/pull/1571',
            'SEO-OPS-SOP-01B' => 'https://github.com/fermatmind/fap-api/pull/1572',
            'SEO-OPS-SOP-01C' => 'https://github.com/fermatmind/fap-api/pull/1573',
            'SEO-OPS-SOP-01D' => 'https://github.com/fermatmind/fap-api/pull/1574',
            'SEO-OPS-SOP-01E' => 'https://github.com/fermatmind/fap-api/pull/1575',
        ] as $id => $url) {
            $row = $completed->firstWhere('id', $id);

            $this->assertIsArray($row, $id.' must be recorded');
            $this->assertSame($url, $row['pr_url'] ?? null);
            $this->assertNotSame('', $row['result'] ?? '');
        }
    }

    #[Test]
    public function results_cover_all_sop_sections(): void
    {
        $results = $this->artifact()['results'] ?? [];

        foreach ([
            'architecture_authority_map',
            'daily_ops_runbook',
            'weekly_monthly_review',
            'approval_gates_no_go',
            'mbti_growth_loop_handoff',
            'ledger',
        ] as $key) {
            $this->assertArrayHasKey($key, $results);
            $this->assertNotSame('', $results[$key]);
        }
    }

    #[Test]
    public function safety_confirmation_blocks_runtime_and_production_work(): void
    {
        foreach ($this->artifact()['safety_confirmation'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function validation_summary_and_sidecars_are_explicit(): void
    {
        $summary = $this->artifact()['validation_summary'] ?? [];

        foreach ([
            'focused_contract_tests',
            'seo_intel_suite',
            'route_list',
            'pint',
            'json_validation',
            'yaml_validation',
            'diff_checks',
            'github_required_checks',
            'post_merge_revalidation',
        ] as $key) {
            $this->assertArrayHasKey($key, $summary);
        }

        $sidecars = collect($this->artifact()['sidecar_issues'] ?? []);

        foreach ([
            'Backend deploy public smoke blocker / local TLS flakiness',
            'translation_group_uuid missing globally',
            'seo_observation_queue contract-only',
            'issue governance fields contract-only',
            'fap-web fallback authority risk',
            'HRZone Digital PR canary observing',
            'post merge cleanup script unavailable',
        ] as $title) {
            $this->assertNotNull($sidecars->firstWhere('title', $title), $title.' sidecar must be recorded');
        }
    }

    #[Test]
    public function final_decision_and_next_task_are_locked(): void
    {
        $this->assertSame(
            'seo_ops_sop_completed_ready_for_mbti_growth_loop_00',
            $this->artifact()['final_decision'] ?? null
        );

        $this->assertSame(
            'SEO-GROWTH-MBTI-00｜Baseline Snapshot and Telemetry Contract',
            $this->artifact()['next_task'] ?? null
        );
    }

    #[Test]
    public function docs_lock_closeout_and_no_runtime_operations(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/seo-ops-sop-final-closeout.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/seo-ops-sop-final-closeout.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'seo-ops-sop-01a',
            'seo-ops-sop-01b',
            'seo-ops-sop-01c',
            'seo-ops-sop-01d',
            'seo-ops-sop-01e',
            'no runtime implementation',
            'no deployment',
            'no env edit',
            'no migration',
            'no scheduler',
            'no search submission',
            'no crawler log read',
            'no cms publish or mutation',
            'no fap-web modification',
            'no digital pr send',
            'no metabase exposure',
            'no pseo generation',
            'seo-growth-mbti-00',
            'seo_ops_sop_completed_ready_for_mbti_growth_loop_00',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-ops-sop-final-closeout.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
