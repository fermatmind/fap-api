<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelObservationGovernanceCloseoutTest extends TestCase
{
    #[Test]
    public function closeout_contract_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/observation-governance-closeout.md'));
        $this->assertSame('observation-governance-closeout.v1', $this->artifact()['version'] ?? null);
        $this->assertSame('SEO-OBS-GOV-06', $this->artifact()['task'] ?? null);
        $this->assertSame('SEO-OBSERVATION-GOVERNANCE-PR-TRAIN-01', $this->artifact()['train'] ?? null);
    }

    #[Test]
    public function completed_prs_one_through_five_are_recorded(): void
    {
        $completed = collect($this->artifact()['completed_prs'] ?? []);

        foreach ([
            'SEO-OBS-GOV-01' => 'https://github.com/fermatmind/fap-api/pull/1556',
            'SEO-OBS-GOV-02' => 'https://github.com/fermatmind/fap-api/pull/1557',
            'SEO-OBS-GOV-03' => 'https://github.com/fermatmind/fap-api/pull/1558',
            'SEO-OBS-GOV-04' => 'https://github.com/fermatmind/fap-api/pull/1559',
            'SEO-OBS-GOV-05' => 'https://github.com/fermatmind/fap-api/pull/1560',
        ] as $id => $url) {
            $row = $completed->firstWhere('id', $id);

            $this->assertIsArray($row, $id.' must be recorded');
            $this->assertSame('completed_contract_only', $row['result'] ?? null);
            $this->assertSame($url, $row['pr_url'] ?? null);
        }
    }

    #[Test]
    public function governance_results_cover_all_contracts(): void
    {
        $results = $this->artifact()['governance_results'] ?? [];

        foreach ([
            'architecture_contract',
            'observation_queue_schema_contract',
            'issue_severity_contract',
            'entity_key_contract',
            'ops_seo_display_readiness',
        ] as $key) {
            $this->assertArrayHasKey($key, $results);
            $this->assertNotSame('', $results[$key]);
        }
    }

    #[Test]
    public function safety_confirmation_blocks_runtime_and_production_work(): void
    {
        foreach ([
            'runtime_writes_introduced',
            'migrations_added',
            'production_migrations_executed',
            'production_operations_performed',
            'production_env_changed',
            'scheduler_activated',
            'url_submission_performed',
            'production_crawler_logs_read',
            'cms_content_mutated',
            'seo_intel_written',
            'metabase_exposed',
            'fap_web_modified',
            'search_channel_queue_mutated',
            'collector_writes_enabled',
        ] as $flag) {
            $this->assertFalse((bool) ($this->artifact()['safety_confirmation'][$flag] ?? true), $flag.' must be false');
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

        $this->assertNotNull($sidecars->firstWhere('branch', 'codex/seo-obs-gov-01-observation-governance-contract'));
        $this->assertNotNull($sidecars->firstWhere('title', 'Future implementation train'));
    }

    #[Test]
    public function final_decision_and_next_task_are_locked(): void
    {
        $this->assertSame(
            'seo_observation_governance_contract_train_completed_ready_for_content_ops_claim_link_runtime',
            $this->artifact()['final_decision'] ?? null
        );
        $this->assertSame('CONTENT-OPS-CLAIM-LINK-RUNTIME-TRAIN-01', $this->artifact()['next_task'] ?? null);
    }

    #[Test]
    public function docs_lock_no_runtime_operations_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/observation-governance-closeout.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/observation-governance-closeout.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'seo-obs-gov-01',
            'seo-obs-gov-02',
            'seo-obs-gov-03',
            'seo-obs-gov-04',
            'seo-obs-gov-05',
            'no runtime writes',
            'no migrations',
            'no production operations',
            'no production env',
            'no scheduler',
            'no urls',
            'no production crawler logs',
            'no cms content',
            'no `seo_intel` data',
            'no metabase surface',
            'no `fap-web` files',
            'content-ops-claim-link-runtime-train-01',
            '"next_task": "CONTENT-OPS-CLAIM-LINK-RUNTIME-TRAIN-01"',
        ] as $required) {
            $this->assertStringContainsString(strtolower($required), $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/observation-governance-closeout.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
