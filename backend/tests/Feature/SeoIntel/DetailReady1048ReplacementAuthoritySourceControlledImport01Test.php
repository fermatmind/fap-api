<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class DetailReady1048ReplacementAuthoritySourceControlledImport01Test extends TestCase
{
    public function test_controlled_import_report_records_apply_boundaries(): void
    {
        $path = base_path('docs/seo/generated/detail-ready-1048-replacement-authority-source-controlled-import-01.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('detail_ready_1048_replacement_authority_source_controlled_import.v1', $payload['schema_version'] ?? null);
        $this->assertSame('DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_CONTROLLED_IMPORT-01', $payload['task'] ?? null);
        $this->assertSame('source_controlled_import_path_completed_ready_for_deploy_and_explicit_apply', $payload['final_decision'] ?? null);
        $this->assertSame('career:import-detail-ready-replacement-authority-source', $payload['command'] ?? null);
        $this->assertSame('digital-forensics-analysts', $payload['target_slug'] ?? null);
        $this->assertSame('software-developers', $payload['manual_hold_slug_kept_excluded'] ?? null);
        $this->assertSame('DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_CONTROLLED_IMPORT_APPROVED', $payload['apply_confirmation_phrase'] ?? null);

        $writes = $payload['controlled_writes_after_future_apply_approval'] ?? [];
        $this->assertSame(2, $writes['occupation_crosswalks'] ?? null);
        $this->assertSame(1, $writes['career_job_display_assets'] ?? null);
        $this->assertSame(0, $writes['index_states'] ?? null);
        $this->assertSame(0, $writes['runtime_promotions'] ?? null);
        $this->assertSame(0, $writes['sitemap_llms_footer_exposure'] ?? null);

        $gates = $payload['safety_gates'] ?? [];
        foreach ([
            'apply_requires_exact_confirmation',
            'package_task_must_match_source_repair',
            'must_not_be_manual_hold',
            'must_not_be_blocked',
            'must_not_be_cn_proxy',
            'must_have_onet_soc_2019_crosswalk',
            'must_have_us_soc_crosswalk',
            'must_have_display_asset_v4_2',
            'must_have_zero_existing_indexable_index_states',
            'forbidden_public_payload_keys_rejected',
        ] as $field) {
            $this->assertTrue($gates[$field] ?? false, $field);
        }

        foreach (['no_cms_mutation', 'no_database_write', 'no_runtime_promotion', 'no_sitemap_llms_footer_exposure', 'no_publish', 'no_deploy', 'no_search_channel_action', 'no_url_submission', 'no_external_search_api_call', 'no_frontend_fallback_authority', 'no_pseo_generation'] as $field) {
            $this->assertTrue($payload[$field] ?? false, $field);
        }

        $this->assertSame('DEPLOY-READINESS | Deploy replacement authority source controlled import path', $payload['next_task'] ?? null);
    }
}
