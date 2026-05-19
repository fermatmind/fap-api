<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSearchChannelQueueContractTest extends TestCase
{
    #[Test]
    public function artifact_lists_channels_without_live_activation(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('search-channel-queue-contract.v1', $artifact['version'] ?? null);
        $this->assertContains('SEO-DASH-PROD-04B', $artifact['source_documents'] ?? []);

        foreach (['gsc', 'baidu', 'indexnow', 'so360', 'sogou', 'shenma'] as $channel) {
            $this->assertContains($channel, $artifact['channels'] ?? []);
        }

        foreach ([
            'live_gsc_enabled_in_this_pr',
            'live_baidu_enabled_in_this_pr',
            'live_indexnow_enabled_in_this_pr',
            'live_domestic_adapters_enabled_in_this_pr',
            'url_submission_performed',
            'credentials_added_in_this_pr',
            'env_edit_in_this_pr',
            'scheduler_enabled_in_this_pr',
            'collector_write_executed_in_this_pr',
            'metabase_deployed_in_this_pr',
            'production_crawler_log_read',
            'sitemap_changed_in_this_pr',
            'llms_changed_in_this_pr',
            'alternate_domestic_pages_allowed',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function eligibility_requires_backend_authority_publication_indexability_and_claim_safety(): void
    {
        $requires = $this->artifact()['eligibility_requires'] ?? [];

        foreach ([
            'backend_approved_source_authority',
            'canonical_url_present',
            'published_state',
            'indexable_state',
            'url_truth_supported_page_type',
            'not_private_flow',
            'not_query_string_only',
            'claim_boundary_safe',
            'later_channel_credential_and_quota_approval',
        ] as $gate) {
            $this->assertContains($gate, $requires);
        }
    }

    #[Test]
    public function forbidden_inputs_and_excluded_url_classes_are_locked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'frontend_fallback',
            'static_sitemap_fallback',
            'static_llms_fallback',
            'node2_local_db',
            'node2_local_laravel',
            'live_search_engine_response_as_page_truth',
            'production_crawler_logs',
            'business_db_raw_tables',
        ] as $source) {
            $this->assertContains($source, $artifact['forbidden_authority_inputs'] ?? []);
        }

        foreach ([
            'draft',
            'private',
            'noindex',
            'claim_unsafe',
            'unsupported_page_type',
            'missing_canonical',
            'non_backend_authoritative',
        ] as $class) {
            $this->assertContains($class, $artifact['excluded_url_classes'] ?? []);
        }
    }

    #[Test]
    public function future_queue_record_fields_are_sanitized(): void
    {
        $artifact = $this->artifact();
        $fields = $artifact['future_queue_record_safe_fields'] ?? [];

        foreach ([
            'canonical_url_hash',
            'masked_display_path',
            'locale',
            'page_entity_type',
            'source_authority',
            'indexability_state',
            'channel',
            'eligibility_status',
            'exclusion_reason',
            'dry_run_status',
            'submission_status',
            'last_checked_date',
        ] as $field) {
            $this->assertContains($field, $fields);
        }

        foreach ($artifact['forbidden_fields'] ?? [] as $forbidden) {
            $this->assertNotContains($forbidden, $fields);
        }
    }

    #[Test]
    public function docs_lock_no_live_api_no_submission_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/search-channel-queue-contract.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/search-channel-queue-contract.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'does not connect live gsc',
            'does not submit urls',
            'google / gsc readiness',
            'baidu push readiness',
            'indexnow readiness',
            '360 readiness',
            'sogou readiness',
            'shenma readiness',
            'draft, private, noindex, claim-unsafe',
            'must not create alternate pages',
            'next task: crawler-log-00',
            '"next_task": "crawler-log-00"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/search-channel-queue-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
