<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class DetailReady1048ReplacementAuthorityIndexStateConflict01Test extends TestCase
{
    public function test_conflict_report_marks_digital_forensics_replacement_unsafe(): void
    {
        $reportPath = base_path('docs/seo/generated/detail-ready-1048-replacement-authority-index-state-conflict-01.v1.json');

        $this->assertFileExists($reportPath);

        $report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('detail_ready_1048_replacement_authority_index_state_conflict.v1', $report['schema_version'] ?? null);
        $this->assertSame('DETAIL_READY_1048_REPLACEMENT_AUTHORITY_INDEX_STATE_CONFLICT-01', $report['task'] ?? null);
        $this->assertSame('replacement_slug_not_safe_recommend_1047_target', $report['final_decision'] ?? null);
        $this->assertSame('digital-forensics-analysts', $report['target_slug'] ?? null);
        $this->assertSame('index_state_runtime_projection_conflict', $report['conflict_class'] ?? null);
        $this->assertFalse($report['replacement_safe'] ?? true);

        $observation = $report['production_observation'] ?? [];
        $this->assertTrue($observation['occupation_row_exists'] ?? false);
        $this->assertTrue($observation['crosswalk_exists'] ?? false);
        $this->assertTrue($observation['display_asset_exists'] ?? false);
        $this->assertSame(0, $observation['recommendation_snapshots_count'] ?? null);
        $this->assertSame(0, $observation['published_career_job_rows_count'] ?? null);
        $this->assertSame(2, $observation['index_state_rows_count'] ?? null);
        $this->assertSame(2, $observation['index_eligible_rows_count'] ?? null);
        $this->assertFalse($observation['runtime_publish_projection_item_present'] ?? true);
        $this->assertFalse($observation['runtime_detail_route_enabled'] ?? true);
        $this->assertFalse($observation['runtime_dataset_visible'] ?? true);
        $this->assertFalse($observation['runtime_release_gate_pass'] ?? true);
        $this->assertFalse($observation['public_dataset_cache_includes_target_slug'] ?? true);
        $this->assertSame(404, $observation['detail_api_status'] ?? null);
        $this->assertSame(404, $observation['frontend_detail_page_status'] ?? null);

        $importGate = $report['import_gate_result'] ?? [];
        $this->assertSame('fail', $importGate['decision'] ?? null);
        $this->assertFalse($importGate['did_write'] ?? true);

        $guardrails = $report['guardrails'] ?? [];
        foreach ([
            'production_write_performed',
            'cms_mutation_performed',
            'runtime_promotion_performed',
            'deploy_performed',
            'sitemap_exposure_performed',
            'llms_exposure_performed',
            'footer_exposure_performed',
            'search_channel_action_performed',
            'url_submission_performed',
            'external_search_api_call_performed',
            'fap_web_change_performed',
        ] as $field) {
            $this->assertFalse($guardrails[$field] ?? true, $field);
        }

        $this->assertTrue($report['software_developers_manual_hold_unchanged'] ?? false);
        $this->assertTrue($guardrails['software_developers_manual_hold_unchanged'] ?? false);
        $this->assertFalse($report['digital_forensics_production_state_modified'] ?? true);

        $recommendedPaths = array_map(
            static fn (array $item): string => (string) ($item['path'] ?? ''),
            array_filter($report['recommended_path'] ?? [], static fn (mixed $item): bool => is_array($item)),
        );

        $this->assertContains('1047_target', $recommendedPaths);
        $this->assertContains('software_developers_hold_decision', $recommendedPaths);
        $this->assertNotEmpty($report['next_task'] ?? null);
    }
}
