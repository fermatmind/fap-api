<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSearchChannelLiveReadinessScanTest extends TestCase
{
    #[Test]
    public function scan_artifact_locks_read_only_no_submit_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('search-channel-live-readiness-scan.v1', $artifact['version'] ?? null);
        $this->assertSame('SEARCH-CHANNEL-LIVE-00', $artifact['task'] ?? null);
        $this->assertSame('ready_for_search_channel_readiness_pr_train', $artifact['final_decision'] ?? null);

        foreach ([
            'production_db_queried_by_this_scan',
        ] as $flag) {
            $this->assertFalse((bool) data_get($artifact, 'accepted_verified_current_state.'.$flag), $flag.' must remain false');
        }

        foreach ([
            'sitemap_changed_in_this_train',
            'llms_changed_in_this_train',
            'live_search_channel_operation_already_run_by_this_train',
            'url_submission_performed',
            'live_external_api_call_performed',
            'production_crawler_log_read',
            'scheduler_enabled',
            'collector_write_executed',
            'production_env_edited',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function scan_confirms_research_truth_and_queue_contracts(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(9, data_get($artifact, 'accepted_verified_current_state.seo_urls'));
        $this->assertSame(9, data_get($artifact, 'accepted_verified_current_state.seo_url_entities'));
        $this->assertSame(2, data_get($artifact, 'accepted_verified_current_state.research_report_rows'));
        $this->assertTrue((bool) ($artifact['search_channel_queue_contract_exists'] ?? false));
        $this->assertSame('research_report', data_get($artifact, 'research_url_truth.page_entity_type'));
        $this->assertSame('backend_cms', data_get($artifact, 'research_url_truth.source_authority'));

        foreach ([
            'research_url_truth_observation',
            'research_seo_geo_search_channel_contract',
            'search_channel_queue_contract',
            'chinese_claim_boundary_linter',
        ] as $contract) {
            $this->assertContains($contract, $artifact['contracts_confirmed'] ?? []);
        }
    }

    #[Test]
    public function scan_excludes_unsafe_url_authority_and_splits_readiness_tasks(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'draft',
            'private',
            'noindex',
            'unapproved',
            'unsupported_route',
            'claim_unsafe',
        ] as $state) {
            $this->assertContains($state, data_get($artifact, 'research_url_truth.forbidden_states', []));
        }

        foreach ([
            'frontend_fallback',
            'static_sitemap_fallback',
            'static_llms_fallback',
            'production_crawler_logs',
            'live_search_engine_response_as_page_truth',
            'node2_local_db',
            'business_db_raw_tables',
        ] as $input) {
            $this->assertContains($input, $artifact['forbidden_authority_inputs'] ?? []);
        }

        $this->assertSame([
            'GSC-LIVE-00',
            'BAIDU-LIVE-00',
            'INDEXNOW-LIVE-00',
            'SEARCH-CHANNEL-LIVE-01-PREFLIGHT',
        ], $artifact['next_tasks'] ?? []);
    }

    #[Test]
    public function docs_lock_no_live_submission_language(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/search-channel-live-readiness-scan.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/search-channel-live-readiness-scan.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'does not submit urls',
            'no indexing request made',
            'no live external search channel api called',
            'no production crawler log read',
            'no scheduler enabled',
            'no collector write run',
            'no sitemap or `llms.txt` behavior changed',
            'ready_for_search_channel_readiness_pr_train',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/search-channel-live-readiness-scan.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
