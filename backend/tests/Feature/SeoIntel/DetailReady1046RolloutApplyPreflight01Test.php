<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class DetailReady1046RolloutApplyPreflight01Test extends TestCase
{
    public function test_preflight_artifact_records_backend_deploy_block_without_apply(): void
    {
        $path = base_path('docs/seo/generated/detail-ready-1046-rollout-apply-preflight-01.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('detail_ready_1046_rollout_apply_preflight.v1', $payload['schema_version'] ?? null);
        $this->assertSame('DETAIL_READY_1046_ROLLOUT_APPLY_PREFLIGHT-01', $payload['task'] ?? null);
        $this->assertSame('blocked_backend_deploy_required', $payload['final_decision'] ?? null);

        $this->assertSame(30, $payload['current_public_detail_count'] ?? null);
        $this->assertSame(1016, $payload['clean_delta_count'] ?? null);
        $this->assertSame(1046, $payload['target_public_total'] ?? null);
        $this->assertIsString($payload['production_backend_sha'] ?? null);
        $this->assertFalse($payload['required_sha_present'] ?? true);
        $this->assertTrue($payload['rollout_runtime_command_present'] ?? false);
        $this->assertFalse($payload['dry_run_performed'] ?? true);
        $this->assertFalse($payload['dry_run_ok'] ?? true);
        $this->assertNull($payload['would_promote_count'] ?? null);

        $this->assertSame(['software-developers'], $payload['excluded_manual_hold_slugs'] ?? null);
        $this->assertSame(['digital-forensics-analysts'], $payload['excluded_conflict_slugs'] ?? null);
        $this->assertSame(['computer-occupations-all-other'], $payload['excluded_already_indexable_replacement_slugs'] ?? null);
        $this->assertTrue($payload['software_developers_excluded'] ?? false);
        $this->assertTrue($payload['digital_forensics_analysts_excluded'] ?? false);

        $this->assertArrayNotHasKey('exact_future_apply_approval_phrase', $payload);

        foreach ([
            'runtime_promotion_performed',
            'production_write_performed',
            'database_write_performed',
            'cms_mutation_performed',
            'deploy_performed',
            'sitemap_llms_footer_exposure_performed',
            'search_channel_action_performed',
            'url_submission_performed',
            'external_search_api_call_performed',
            'fap_web_change_performed',
            'replacement_search_performed',
        ] as $field) {
            $this->assertFalse($payload[$field] ?? true, $field);
        }
    }
}
