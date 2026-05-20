<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelBaiduLiveReadinessContractTest extends TestCase
{
    #[Test]
    public function artifact_locks_baidu_to_distribution_not_authority(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('baidu-live-readiness-contract.v1', $artifact['version'] ?? null);
        $this->assertSame('BAIDU-LIVE-00', $artifact['task'] ?? null);
        $this->assertSame('baidu', $artifact['channel'] ?? null);
        $this->assertTrue((bool) data_get($artifact, 'authority_boundary.baidu_is_distribution_channel'));

        foreach ([
            'baidu_is_content_authority',
            'baidu_is_url_truth_authority',
            'baidu_is_sitemap_authority',
            'baidu_is_llms_authority',
            'baidu_only_page_truth_allowed',
            'alternate_baidu_page_set_allowed',
            'frontend_fallback_allowed_as_search_truth',
        ] as $flag) {
            $this->assertFalse((bool) data_get($artifact, 'authority_boundary.'.$flag, true), $flag.' must remain false');
        }
    }

    #[Test]
    public function ownership_token_and_endpoint_policies_are_explicit(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'verified_baidu_resource_platform_site',
            'documented_account_owner',
            'documented_operator_responsibility',
            'approved_push_endpoint_and_quota_policy',
            'safe_secret_channel_only',
            'documented_token_rotation_and_revocation_owner',
        ] as $requirement) {
            $this->assertContains($requirement, $artifact['ownership_requirements'] ?? []);
        }

        foreach ([
            'raw_token_in_docs_allowed',
            'raw_token_in_logs_allowed',
            'raw_endpoint_secret_in_generated_artifact_allowed',
            'public_token_exposure_allowed',
        ] as $flag) {
            $this->assertFalse((bool) data_get($artifact, 'token_endpoint_policy.'.$flag, true), $flag.' must remain false');
        }

        $this->assertTrue((bool) data_get($artifact, 'token_endpoint_policy.masked_readiness_state_allowed'));
    }

    #[Test]
    public function baidu_push_requires_search_channel_queue_and_claim_safety(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'search_channel_queue_approved',
            'backend_cms_source_authority',
            'canonical_url_present',
            'published_state',
            'indexable_state',
            'url_truth_supported_page_type',
            'claim_boundary_safe',
            'chinese_claim_boundary_linter_passed_when_applicable',
            'not_private_flow',
            'not_query_string_only',
            'not_stale_slug',
        ] as $gate) {
            $this->assertContains($gate, $artifact['eligibility_requires'] ?? []);
        }

        foreach (['draft', 'private', 'noindex', 'claim_unsafe', 'stale_slug', 'non_backend_authoritative'] as $class) {
            $this->assertContains($class, $artifact['excluded_url_classes'] ?? []);
        }
    }

    #[Test]
    public function forbidden_sources_and_sensitive_outputs_are_locked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'frontend_sitemap',
            'static_llms',
            'local_content_copies',
            'production_crawler_logs',
            'live_baidu_response_as_page_truth',
            'node2_local_db',
            'business_db_raw_tables',
        ] as $source) {
            $this->assertContains($source, $artifact['forbidden_authority_inputs'] ?? []);
        }

        foreach (['token', 'api_key', 'cookie', 'session', 'email', 'order_id', 'attempt_id', 'payment_id', 'raw_ip', 'raw_payload'] as $field) {
            $this->assertContains($field, $artifact['forbidden_sensitive_outputs'] ?? []);
        }
    }

    #[Test]
    public function no_push_or_live_activation_is_allowed_in_this_pr(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'baidu_push_performed',
            'live_api_call_in_this_pr',
            'url_submission_performed',
            'scheduler_enabled',
            'collector_write_executed',
            'live_connector_activated',
            'credentials_added_in_this_pr',
            'production_env_edited',
            'sitemap_changed_in_this_pr',
            'llms_changed_in_this_pr',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_no_baidu_push_language_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/baidu-live-readiness-contract.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/baidu-live-readiness-contract.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'does not call live baidu apis',
            'no baidu push is allowed',
            'must not create a baidu-only page set',
            'chinese claim boundary linter pass',
            'search channel queue records',
            'next task: indexnow-live-00',
            '"next_task": "indexnow-live-00"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/baidu-live-readiness-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
