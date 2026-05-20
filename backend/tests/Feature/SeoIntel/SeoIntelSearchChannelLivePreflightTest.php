<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSearchChannelLivePreflightTest extends TestCase
{
    #[Test]
    public function preflight_artifact_records_current_state_without_production_reads(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('search-channel-live-preflight.v1', $artifact['version'] ?? null);
        $this->assertSame('SEARCH-CHANNEL-LIVE-01-PREFLIGHT', $artifact['task'] ?? null);
        $this->assertSame('SEARCH-CHANNEL-LIVE-READINESS-TRAIN Report', $artifact['report_title'] ?? null);
        $this->assertSame(9, data_get($artifact, 'accepted_verified_current_state.seo_urls'));
        $this->assertSame(9, data_get($artifact, 'accepted_verified_current_state.seo_url_entities'));
        $this->assertSame(2, data_get($artifact, 'accepted_verified_current_state.research_report_rows'));
        $this->assertFalse((bool) data_get($artifact, 'accepted_verified_current_state.production_db_queried_by_this_preflight', true));
        $this->assertFalse((bool) data_get($artifact, 'accepted_verified_current_state.production_crawler_logs_read', true));
    }

    #[Test]
    public function channel_contract_results_are_readiness_only(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('passed_readiness_contract', data_get($artifact, 'contract_results.gsc.status'));
        $this->assertSame('passed_readiness_contract', data_get($artifact, 'contract_results.baidu.status'));
        $this->assertSame('passed_readiness_contract', data_get($artifact, 'contract_results.indexnow.status'));

        $this->assertFalse((bool) data_get($artifact, 'contract_results.gsc.live_request_made', true));
        $this->assertFalse((bool) data_get($artifact, 'contract_results.gsc.indexing_request_made', true));
        $this->assertFalse((bool) data_get($artifact, 'contract_results.baidu.live_push_made', true));
        $this->assertFalse((bool) data_get($artifact, 'contract_results.indexnow.live_submission_made', true));
    }

    #[Test]
    public function url_eligibility_excludes_private_noindex_claim_unsafe_and_forbidden_sources(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'backend_cms_source_authority',
            'published_state',
            'public_state',
            'indexable_state',
            'canonical_url_present',
            'url_truth_supported_page_type',
            'claim_boundary_safe',
        ] as $gate) {
            $this->assertContains($gate, data_get($artifact, 'url_eligibility.research_urls_candidate_only_if', []));
        }

        foreach ([
            'test_take',
            'test_result',
            'order',
            'share',
            'pay',
            'report_private',
            'draft_research',
            'stale_slug',
            'noindex',
            'private',
            'claim_unsafe',
        ] as $class) {
            $this->assertContains($class, data_get($artifact, 'url_eligibility.excluded_url_classes', []));
        }

        foreach ([
            'frontend_fallback',
            'static_sitemap_fallback',
            'static_llms_fallback',
            'production_crawler_logs',
            'live_search_responses',
            'node2_local_db',
            'business_db_raw_tables',
        ] as $source) {
            $this->assertContains($source, data_get($artifact, 'url_eligibility.forbidden_authority_inputs', []));
        }
    }

    #[Test]
    public function queue_contract_exists_but_runtime_is_missing(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue((bool) data_get($artifact, 'search_channel_queue_readiness.contract_exists'));

        foreach ([
            'runtime_queue_exists',
            'runtime_queue_tables_or_models_confirmed',
            'runtime_canary_submitter_exists',
            'controlled_approval_state_exists',
        ] as $flag) {
            $this->assertFalse((bool) data_get($artifact, 'search_channel_queue_readiness.'.$flag, true), $flag.' must remain false');
        }

        $this->assertSame('blocked_search_channel_queue_runtime_missing', $artifact['final_decision'] ?? null);
        $this->assertSame('SEARCH-CHANNEL-QUEUE-01 runtime MVP', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function forbidden_operations_remain_false(): void
    {
        foreach ($this->artifact()['forbidden_operations'] ?? [] as $operation => $performed) {
            $this->assertFalse((bool) $performed, $operation.' must remain false');
        }
    }

    #[Test]
    public function report_has_required_sections_and_no_submit_decision(): void
    {
        $report = strtolower((string) file_get_contents(base_path('docs/seo/search-channel-live-preflight.md')));

        foreach ([
            '# search-channel-live-readiness-train report',
            '## 1. executive summary',
            '## 2. current seo / research url truth state',
            '## 3. gsc readiness contract result',
            '## 4. baidu readiness contract result',
            '## 5. indexnow readiness contract result',
            '## 6. url eligibility preflight',
            '## 7. search channel queue readiness',
            '## 8. what was not done',
            '## 9. final decision',
            '## 10. next task',
            'blocked_search_channel_queue_runtime_missing',
            'search-channel-queue-01 runtime mvp',
            'no urls were submitted',
            'no gsc live request was made',
            'no baidu push was made',
            'no indexnow post was made',
        ] as $required) {
            $this->assertStringContainsString($required, $report);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/search-channel-live-preflight.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
