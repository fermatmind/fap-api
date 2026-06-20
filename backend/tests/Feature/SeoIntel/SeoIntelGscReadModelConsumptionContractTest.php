<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGscReadModelConsumptionContractTest extends TestCase
{
    #[Test]
    public function artifact_requires_live_gsc_origin_quality_gate_and_sanitized_fields_only(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('gsc-live-readmodel-consumption-contract.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-GSC-LIVE-READMODEL-CONSUMPTION-CONTRACT-01', $artifact['task'] ?? null);
        $this->assertTrue((bool) ($artifact['read_only_contract'] ?? false));
        $this->assertFalse((bool) ($artifact['runtime_behavior_changed'] ?? true));

        $this->assertSame('live_gsc_api', data_get($artifact, 'required_before_future_read_model_import.data_origin'));
        $this->assertSame('pass', data_get($artifact, 'required_before_future_read_model_import.data_quality_gate'));
        $this->assertSame('google', data_get($artifact, 'required_before_future_read_model_import.source_engine'));
        $this->assertTrue((bool) data_get($artifact, 'required_before_future_read_model_import.sanitized_fields_only'));

        foreach (['canonical_url_hash', 'query_hash'] as $requiredHash) {
            $this->assertContains($requiredHash, data_get($artifact, 'required_before_future_read_model_import.hashed_identifiers_required', []));
            $this->assertContains($requiredHash, $artifact['allowed_sanitized_fields'] ?? []);
        }
    }

    #[Test]
    public function raw_query_raw_url_credentials_and_tokens_are_forbidden(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'raw_query',
            'raw_url',
            'credential_path',
            'token',
            'access_token',
            'api_key',
            'client_email',
            'service_account_json',
            'cookie',
            'session',
            'raw_payload',
        ] as $forbiddenField) {
            $this->assertContains($forbiddenField, $artifact['forbidden_artifact_fields'] ?? []);
            $this->assertNotContains($forbiddenField, $artifact['allowed_sanitized_fields'] ?? []);
        }
    }

    #[Test]
    public function read_model_target_is_future_only_and_opportunity_queue_cannot_read_sidecar_artifacts_directly(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('seo_gsc_daily', data_get($artifact, 'future_read_model_target.table'));
        $this->assertSame('future_import_target_only', data_get($artifact, 'future_read_model_target.status'));
        $this->assertFalse((bool) data_get($artifact, 'future_read_model_target.importer_added_in_this_pr', true));
        $this->assertFalse((bool) data_get($artifact, 'future_read_model_target.write_allowed_in_this_pr', true));

        $this->assertFalse((bool) data_get($artifact, 'opportunity_queue_boundary.direct_sidecar_artifact_consumption_allowed', true));
        $this->assertSame(
            'seo_intel read model rows after gsc data_quality_gate=pass',
            data_get($artifact, 'opportunity_queue_boundary.allowed_source'),
        );

        foreach ([
            'queue_execution_allowed',
            'cms_draft_allowed',
            'search_submission_allowed',
        ] as $flag) {
            $this->assertFalse((bool) data_get($artifact, 'opportunity_queue_boundary.'.$flag, true), $flag.' must remain false');
        }
    }

    #[Test]
    public function negative_guarantees_block_runtime_writes_scheduler_cms_search_and_indexing(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'live_gsc_api_call',
            'credential_read_print_or_store',
            'seo_gsc_daily_import',
            'database_write',
            'scheduler_activation',
            'opportunity_queue_enqueue',
            'cms_write',
            'search_channel_enqueue',
            'search_provider_submission',
            'gsc_url_inspection_request_indexing',
            'sitemap_submission',
        ] as $flag) {
            $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.'.$flag, true), $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_no_import_no_queue_no_cms_no_search_language(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/gsc-live-readmodel-consumption-contract.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/gsc-live-readmodel-consumption-contract.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'data_origin=live_gsc_api',
            'data_quality_gate=pass',
            'sanitized fields only',
            'future backend read model import target',
            'must never consume sidecar artifacts directly',
            'no live gsc api call in this pr',
            'no credential read, print, storage, or mutation',
            'no opportunity queue enqueue',
            'no cms draft or cms write',
            'no search channel enqueue, approval, or submission',
        ] as $requiredText) {
            $this->assertStringContainsString($requiredText, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/gsc-live-readmodel-consumption-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
