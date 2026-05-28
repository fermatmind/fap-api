<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class DetailReady1047DeltaAuthorityRepair01Test extends TestCase
{
    public function test_1047_delta_manifest_records_required_exclusion_count_mismatch(): void
    {
        $reportPath = base_path('docs/seo/generated/detail-ready-1047-delta-authority-repair-01.v1.json');
        $manifestPath = base_path('docs/seo/generated/detail-ready-1047-rollout-manifest.v1.json');

        $this->assertFileExists($reportPath);
        $this->assertFileExists($manifestPath);

        $report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('detail_ready_1047_delta_authority_repair.v1', $report['schema_version'] ?? null);
        $this->assertSame('DETAIL_READY_1047_DELTA_AUTHORITY_REPAIR-01', $report['task'] ?? null);
        $this->assertSame('blocked_1047_delta_still_contains_conflict_slug', $report['final_decision'] ?? null);

        $this->assertSame(30, $report['current_public_detail_count'] ?? null);
        $this->assertSame(1016, $report['clean_delta_count'] ?? null);
        $this->assertSame(1017, $report['requested_clean_delta_count'] ?? null);
        $this->assertSame(1046, $report['target_public_total'] ?? null);
        $this->assertSame(1047, $report['requested_target_public_total'] ?? null);
        $this->assertFalse($report['manifest_safe'] ?? true);

        $this->assertSame(['software-developers'], $report['excluded_manual_hold_slugs'] ?? null);
        $this->assertSame(['digital-forensics-analysts'], $report['excluded_conflict_slugs'] ?? null);
        $this->assertSame(1, $report['manual_hold_count'] ?? null);
        $this->assertSame(0, $report['review_needed_count'] ?? null);
        $this->assertSame(1, $report['runtime_projection_conflict_count'] ?? null);

        $this->assertSame('detail_ready_1047_rollout_manifest.v1', $manifest['schema_version'] ?? null);
        $this->assertSame('blocked', $manifest['status'] ?? null);
        $this->assertFalse($manifest['manifest_safe'] ?? true);
        $this->assertSame(30, $manifest['current_public_detail_count'] ?? null);
        $this->assertSame(1016, $manifest['clean_delta_count'] ?? null);
        $this->assertCount(30, $manifest['baseline_slugs'] ?? []);
        $this->assertCount(1016, $manifest['delta_slugs'] ?? []);
        $this->assertSame($manifest['delta_slugs'] ?? null, $manifest['rollback_group'] ?? null);

        $deltaSlugs = $manifest['delta_slugs'] ?? [];
        $this->assertNotContains('software-developers', $deltaSlugs);
        $this->assertNotContains('digital-forensics-analysts', $deltaSlugs);

        $this->assertSame(1, $manifest['blocked_count'] ?? null);
        $this->assertSame(1, $manifest['manual_hold_count'] ?? null);
        $this->assertSame(0, $manifest['review_needed_count'] ?? null);
        $this->assertSame(1, $manifest['runtime_projection_conflict_count'] ?? null);

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
        $this->assertTrue($report['software_developers_manual_hold_unchanged'] ?? false);
        $this->assertFalse($report['digital_forensics_replacement_used'] ?? true);
    }
}
