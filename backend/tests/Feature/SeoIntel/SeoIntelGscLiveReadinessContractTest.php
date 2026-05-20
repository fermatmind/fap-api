<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGscLiveReadinessContractTest extends TestCase
{
    #[Test]
    public function artifact_locks_gsc_as_feedback_not_authority(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('gsc-live-readiness-contract.v1', $artifact['version'] ?? null);
        $this->assertSame('GSC-LIVE-00', $artifact['task'] ?? null);
        $this->assertSame('gsc', $artifact['channel'] ?? null);
        $this->assertTrue((bool) data_get($artifact, 'authority_boundary.gsc_is_feedback_readiness_source'));

        foreach ([
            'gsc_is_content_authority',
            'gsc_is_url_truth_authority',
            'gsc_is_sitemap_authority',
            'gsc_is_llms_authority',
            'gsc_is_purchase_truth',
            'gsc_creates_urls',
            'gsc_overrides_cms_backend_url_truth',
        ] as $flag) {
            $this->assertFalse((bool) data_get($artifact, 'authority_boundary.'.$flag, true), $flag.' must remain false');
        }
    }

    #[Test]
    public function ownership_and_read_only_requirements_are_explicit(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'verified_search_console_property',
            'documented_property_owner',
            'documented_operator_responsibility',
            'approved_service_account_or_oauth_access',
            'safe_secret_channel_only',
        ] as $requirement) {
            $this->assertContains($requirement, $artifact['ownership_requirements'] ?? []);
        }

        $this->assertSame('last_16_months_max', data_get($artifact, 'read_only_data_window.maximum_contract_window'));
        $this->assertContains('last_28_days', data_get($artifact, 'read_only_data_window.preferred_initial_windows', []));
        $this->assertFalse((bool) data_get($artifact, 'read_only_data_window.raw_sensitive_query_output_allowed', true));
    }

    #[Test]
    public function url_inspection_is_read_only_status_check_without_submission(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue((bool) data_get($artifact, 'url_inspection_readiness.read_only_status_check_only'));
        $this->assertTrue((bool) data_get($artifact, 'url_inspection_readiness.requires_explicit_future_approval'));
        $this->assertSame('cms_backend_url_truth_approved_canonical_urls', data_get($artifact, 'url_inspection_readiness.allowed_url_source'));

        foreach ([
            'indexing_request_allowed',
            'url_submission_allowed',
            'queue_creation_by_gsc_allowed',
        ] as $flag) {
            $this->assertFalse((bool) data_get($artifact, 'url_inspection_readiness.'.$flag, true), $flag.' must remain false');
        }
    }

    #[Test]
    public function sitemap_check_does_not_change_runtime_or_url_truth(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue((bool) data_get($artifact, 'sitemap_authority_check.compare_gsc_feedback_to_backend_expectations'));

        foreach ([
            'changes_sitemap_generation',
            'changes_llms_generation',
            'changes_cms_publication_state',
            'changes_url_truth_eligibility',
        ] as $flag) {
            $this->assertFalse((bool) data_get($artifact, 'sitemap_authority_check.'.$flag, true), $flag.' must remain false');
        }
    }

    #[Test]
    public function no_live_api_or_secret_exposure_is_allowed_in_this_pr(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'live_api_call_in_this_pr',
            'url_submission_performed',
            'indexing_request_performed',
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

        foreach (['draft', 'private', 'noindex', 'claim_unsafe'] as $forbidden) {
            $this->assertContains($forbidden, $artifact['forbidden_url_classes'] ?? []);
        }

        foreach (['token', 'api_key', 'cookie', 'session', 'email', 'order_id', 'attempt_id', 'payment_id', 'raw_ip'] as $field) {
            $this->assertContains($field, $artifact['forbidden_sensitive_outputs'] ?? []);
        }
    }

    #[Test]
    public function docs_lock_gsc_no_submit_language_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/gsc-live-readiness-contract.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/gsc-live-readiness-contract.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'feedback and readiness source only',
            'must not create urls',
            'read-only/status check',
            'never request indexing',
            'does not connect a live gsc connector',
            'no live gsc api call',
            'next task: baidu-live-00',
            '"next_task": "baidu-live-00"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/gsc-live-readiness-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
