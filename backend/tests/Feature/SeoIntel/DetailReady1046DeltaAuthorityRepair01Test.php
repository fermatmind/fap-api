<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class DetailReady1046DeltaAuthorityRepair01Test extends TestCase
{
    public function test_1046_delta_manifest_is_safe_and_does_not_apply(): void
    {
        $reportPath = base_path('docs/seo/generated/detail-ready-1046-delta-authority-repair-01.v1.json');
        $manifestPath = base_path('docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json');

        $this->assertFileExists($reportPath);
        $this->assertFileExists($manifestPath);

        $report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('detail_ready_1046_delta_authority_repair.v1', $report['schema_version'] ?? null);
        $this->assertSame('DETAIL_READY_1046_DELTA_AUTHORITY_REPAIR-01', $report['task'] ?? null);
        $this->assertSame('detail_ready_1046_delta_manifest_completed_ready_for_explicit_apply_preflight', $report['final_decision'] ?? null);

        $this->assertSame(30, $report['current_public_detail_count'] ?? null);
        $this->assertSame(1016, $report['clean_delta_count'] ?? null);
        $this->assertSame(1046, $report['target_public_total'] ?? null);
        $this->assertTrue($report['manifest_safe'] ?? false);

        $this->assertSame(['software-developers'], $report['excluded_manual_hold_slugs'] ?? null);
        $this->assertSame(['digital-forensics-analysts'], $report['excluded_conflict_slugs'] ?? null);
        $this->assertSame(['computer-occupations-all-other'], $report['excluded_already_indexable_replacement_slugs'] ?? null);
        $this->assertSame(0, $report['blocked_count'] ?? null);
        $this->assertSame(0, $report['manual_hold_count'] ?? null);
        $this->assertSame(0, $report['conflict_count'] ?? null);
        $this->assertSame(0, $report['review_needed_count'] ?? null);
        $this->assertSame(0, $report['runtime_projection_conflict_count'] ?? null);

        $this->assertSame('detail_ready_1046_rollout_manifest.v1', $manifest['schema_version'] ?? null);
        $this->assertSame('ready_for_explicit_apply_preflight', $manifest['status'] ?? null);
        $this->assertTrue($manifest['manifest_safe'] ?? false);
        $this->assertFalse($manifest['apply_allowed'] ?? true);
        $this->assertFalse($manifest['rollout_apply_allowed'] ?? true);
        $this->assertSame(30, $manifest['current_public_detail_count'] ?? null);
        $this->assertSame(1016, $manifest['clean_delta_count'] ?? null);
        $this->assertSame(1046, $manifest['target_public_total'] ?? null);
        $this->assertCount(30, $manifest['baseline_slugs'] ?? []);
        $this->assertCount(1016, $manifest['delta_slugs'] ?? []);
        $this->assertSame($manifest['delta_slugs'] ?? null, $manifest['rollback_group'] ?? null);

        $deltaSlugs = $manifest['delta_slugs'] ?? [];
        $this->assertNotContains('software-developers', $deltaSlugs);
        $this->assertNotContains('digital-forensics-analysts', $deltaSlugs);
        $this->assertNotContains('computer-occupations-all-other', $deltaSlugs);

        $this->assertSame(0, $manifest['blocked_count'] ?? null);
        $this->assertSame(0, $manifest['manual_hold_count'] ?? null);
        $this->assertSame(0, $manifest['conflict_count'] ?? null);
        $this->assertSame(0, $manifest['review_needed_count'] ?? null);
        $this->assertSame(0, $manifest['runtime_projection_conflict_count'] ?? null);

        foreach ([
            'apply_performed',
            'runtime_promotion_performed',
            'sitemap_llms_footer_exposure_performed',
            'search_channel_action_performed',
            'url_submission_performed',
            'external_search_api_call_performed',
            'fap_web_change_performed',
            'production_write_performed',
            'database_write_performed',
            'cms_mutation_performed',
            'deploy_performed',
        ] as $field) {
            $this->assertFalse($report[$field] ?? true, $field);
        }

        $this->assertFalse($manifest['apply_performed'] ?? true);
        $this->assertFalse($manifest['runtime_promotion_performed'] ?? true);
        $this->assertFalse($manifest['sitemap_llms_footer_exposure_performed'] ?? true);
        $this->assertFalse($manifest['search_channel_action_performed'] ?? true);
        $this->assertTrue($manifest['software_developers_manual_hold_unchanged'] ?? false);
        $this->assertFalse($manifest['digital_forensics_replacement_used'] ?? true);
        $this->assertFalse($manifest['replacement_search_performed'] ?? true);
    }
}
