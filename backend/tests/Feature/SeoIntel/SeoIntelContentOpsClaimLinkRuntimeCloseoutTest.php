<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelContentOpsClaimLinkRuntimeCloseoutTest extends TestCase
{
    #[Test]
    public function closeout_contract_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/content-ops-claim-link-runtime-closeout.md'));

        $artifact = $this->artifact();

        $this->assertSame('content-ops-claim-link-runtime-closeout.v1', $artifact['version'] ?? null);
        $this->assertSame('CONTENT-OPS-CLAIM-LINK-CLOSEOUT', $artifact['task'] ?? null);
        $this->assertSame('CONTENT-OPS-CLAIM-LINK-RUNTIME-TRAIN-01', $artifact['train'] ?? null);
    }

    #[Test]
    public function completed_train_prs_are_recorded(): void
    {
        $completed = collect($this->artifact()['completed_prs'] ?? []);

        foreach ([
            'CONTENT-OPS-02A' => 'https://github.com/fermatmind/fap-api/pull/1562',
            'CONTENT-OPS-02B' => 'https://github.com/fermatmind/fap-api/pull/1563',
            'INTERNAL-LINK-01A' => 'https://github.com/fermatmind/fap-api/pull/1564',
            'INTERNAL-LINK-01B' => 'https://github.com/fermatmind/fap-api/pull/1565',
            'CLAIM-LINT-01A' => 'https://github.com/fermatmind/fap-api/pull/1566',
            'CLAIM-LINT-01B' => 'https://github.com/fermatmind/fap-api/pull/1567',
            'CONTENT-OPS-CLAIM-LINK-OPS-READINESS' => 'https://github.com/fermatmind/fap-api/pull/1568',
        ] as $id => $url) {
            $row = $completed->firstWhere('id', $id);

            $this->assertIsArray($row, $id.' must be recorded');
            $this->assertSame($url, $row['pr_url'] ?? null);
            $this->assertNotSame('', $row['result'] ?? '');
        }
    }

    #[Test]
    public function result_summary_covers_publish_links_claims_ops_and_ledger(): void
    {
        $results = $this->artifact()['results'] ?? [];

        foreach ([
            'content_publish_rehearsal',
            'internal_link_graph',
            'chinese_claim_linter',
            'ops_seo_readiness',
            'ledger',
        ] as $key) {
            $this->assertArrayHasKey($key, $results);
            $this->assertNotSame('', $results[$key]);
        }
    }

    #[Test]
    public function safety_confirmation_blocks_mutations_and_production_work(): void
    {
        foreach ([
            'cms_content_mutated',
            'article_published',
            'production_migrations_executed',
            'scheduler_enabled',
            'search_channel_enqueue_or_submission',
            'production_crawler_logs_read',
            'fap_web_modified',
            'metabase_exposed',
            'auto_rewrite_performed',
            'internal_links_created',
            'production_deploy_performed',
            'production_env_changed',
            'collector_writes_enabled',
            'search_engine_api_called',
            'seo_intel_written',
            'cms_write_controls_added',
            'ops_ui_implemented',
            'migrations_added',
        ] as $flag) {
            $this->assertFalse((bool) ($this->artifact()['safety_confirmation'][$flag] ?? true), $flag.' must be false');
        }
    }

    #[Test]
    public function validation_summary_and_sidecars_are_explicit(): void
    {
        $summary = $this->artifact()['validation_summary'] ?? [];

        foreach ([
            'focused_contract_and_runtime_tests',
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
            'translation_group_uuid missing globally',
            'Backend deploy public smoke blocker',
            'fap-web fallback authority risk',
            'Production ops/API TLS flakiness',
            'BigFive runtime freeze allowlist',
        ] as $title) {
            $this->assertNotNull($sidecars->firstWhere('title', $title), $title.' sidecar must be recorded');
        }
    }

    #[Test]
    public function final_decision_and_next_task_are_locked(): void
    {
        $this->assertSame(
            'content_ops_claim_link_runtime_train_completed_ready_for_seo_ops_sop',
            $this->artifact()['final_decision'] ?? null
        );
        $this->assertSame('SEO-OPS-SOP-01', $this->artifact()['next_task'] ?? null);
    }

    #[Test]
    public function docs_lock_no_runtime_operations_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/content-ops-claim-link-runtime-closeout.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/content-ops-claim-link-runtime-closeout.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'content-ops-02a',
            'content-ops-02b',
            'internal-link-01a',
            'internal-link-01b',
            'claim-lint-01a',
            'claim-lint-01b',
            'content-ops-claim-link-ops-readiness',
            'no cms content mutation',
            'no article publish',
            'no production migrations',
            'no scheduler',
            'no search channel enqueue or submission',
            'no production crawler logs',
            'no fap-web files',
            'no metabase surface',
            'no auto-rewrite',
            'no internal links',
            'seo-ops-sop-01',
            '"next_task": "SEO-OPS-SOP-01"',
        ] as $required) {
            $this->assertStringContainsString(strtolower($required), $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/content-ops-claim-link-runtime-closeout.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
