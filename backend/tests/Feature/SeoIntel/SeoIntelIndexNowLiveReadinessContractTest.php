<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelIndexNowLiveReadinessContractTest extends TestCase
{
    #[Test]
    public function artifact_locks_indexnow_as_signal_channel_not_authority(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('indexnow-live-readiness-contract.v1', $artifact['version'] ?? null);
        $this->assertSame('INDEXNOW-LIVE-00', $artifact['task'] ?? null);
        $this->assertSame('indexnow', $artifact['channel'] ?? null);
        $this->assertTrue((bool) data_get($artifact, 'authority_boundary.indexnow_is_url_update_signal_channel'));

        foreach ([
            'indexnow_is_content_authority',
            'indexnow_is_url_truth_authority',
            'indexnow_is_sitemap_authority',
            'indexnow_is_llms_authority',
            'indexnow_is_indexing_or_ranking_proof',
            'bypass_url_truth_allowed',
            'bypass_search_channel_queue_allowed',
        ] as $flag) {
            $this->assertFalse((bool) data_get($artifact, 'authority_boundary.'.$flag, true), $flag.' must remain false');
        }
    }

    #[Test]
    public function key_ownership_hosting_and_verification_are_required(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'documented_indexnow_key_owner',
            'documented_key_rotation_and_revocation_owner',
            'safe_secret_channel_only',
            'approved_key_file_hosting',
            'verified_key_file_content',
            'verified_key_url',
            'verified_allowed_hosts',
        ] as $requirement) {
            $this->assertContains($requirement, $artifact['key_requirements'] ?? []);
        }

        foreach ([
            'raw_key_in_docs_allowed',
            'raw_key_in_logs_allowed',
            'raw_key_in_generated_artifact_allowed',
            'public_token_leakage_allowed',
            'key_file_hosting_changed_in_this_pr',
        ] as $flag) {
            $this->assertFalse((bool) data_get($artifact, 'key_policy.'.$flag, true), $flag.' must remain false');
        }
    }

    #[Test]
    public function allowed_host_policy_rejects_unapproved_hosts(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue((bool) data_get($artifact, 'allowed_host_policy.allowed_hosts_must_be_explicit'));
        $this->assertSame('fermatmind_owned_canonical_public_hosts_only', data_get($artifact, 'allowed_host_policy.initial_policy'));

        foreach ([
            'unknown_hosts_allowed',
            'localhost_allowed',
            'private_network_hosts_allowed',
            'third_party_hosts_allowed',
            'canonical_host_mismatch_allowed',
        ] as $flag) {
            $this->assertFalse((bool) data_get($artifact, 'allowed_host_policy.'.$flag, true), $flag.' must remain false');
        }
    }

    #[Test]
    public function submission_requires_queue_approval_and_backend_truth(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'explicit_human_live_submission_approval',
            'indexnow_key_verified',
            'allowed_host_verified',
            'search_channel_queue_approved',
            'backend_cms_source_authority',
            'canonical_url_present',
            'published_state',
            'indexable_state',
            'url_truth_supported_page_type',
            'claim_boundary_safe',
        ] as $gate) {
            $this->assertContains($gate, $artifact['submission_requires'] ?? []);
        }

        foreach (['draft', 'private', 'noindex', 'claim_unsafe', 'stale_slug', 'non_backend_authoritative'] as $class) {
            $this->assertContains($class, $artifact['excluded_url_classes'] ?? []);
        }
    }

    #[Test]
    public function bulk_submission_contract_and_secret_outputs_are_sanitized(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue((bool) data_get($artifact, 'bulk_submission_contract.bounded_batches_required'));
        $this->assertTrue((bool) data_get($artifact, 'bulk_submission_contract.sanitized_logs_required'));
        $this->assertTrue((bool) data_get($artifact, 'bulk_submission_contract.queue_records_required'));
        $this->assertFalse((bool) data_get($artifact, 'bulk_submission_contract.raw_payload_storage_allowed', true));

        foreach (['indexnow_key', 'token', 'api_key', 'cookie', 'session', 'email', 'order_id', 'attempt_id', 'payment_id', 'raw_ip', 'raw_payload'] as $field) {
            $this->assertContains($field, $artifact['forbidden_sensitive_outputs'] ?? []);
        }
    }

    #[Test]
    public function no_live_submission_or_activation_is_allowed_in_this_pr(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'live_submission_performed',
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
    public function docs_lock_no_indexnow_submission_language_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/indexnow-live-readiness-contract.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/indexnow-live-readiness-contract.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'does not call live indexnow endpoints',
            'no live indexnow submission is allowed',
            'must not bypass cms/backend url truth',
            'key file content and key url must be verified',
            'bulk url submission must use bounded batches',
            'next task: search-channel-live-01-preflight',
            '"next_task": "search-channel-live-01-preflight"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/indexnow-live-readiness-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
