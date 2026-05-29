<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class Career1046OpsScopeReconciliation01Test extends TestCase
{
    public function test_report_and_generated_artifact_reconcile_runtime_and_ops_scope(): void
    {
        $reportPath = base_path('docs/seo/career-1046-ops-scope-reconciliation-01.md');
        $artifactPath = base_path('docs/seo/generated/career-1046-ops-scope-reconciliation-01.v1.json');

        $this->assertFileExists($reportPath);
        $this->assertFileExists($artifactPath);

        $report = (string) file_get_contents($reportPath);
        $artifact = json_decode((string) file_get_contents($artifactPath), true, 512, JSON_THROW_ON_ERROR);

        foreach ([
            '## 1. Executive Summary',
            '## 2. Public Runtime 1046 Source',
            '## 3. CMS/Ops 378 Source',
            '## 4. SEO Ops 398 Blocker Source',
            '## 5. SEO智能 / seo_intel Read Model Status',
            '## 6. Source Table / Service Matrix',
            '## 7. Expected vs Unexpected Mismatch',
            '## 8. Production Risk Assessment',
            '## 9. Recommended Fixes',
            '## 10. Validation',
            '## 11. PR / Merge Result',
            '## 12. What Was Not Done',
            '## 13. Final Decision',
            '## 14. Next Task',
        ] as $heading) {
            $this->assertStringContainsString($heading, $report);
        }

        $this->assertSame('career_1046_ops_scope_reconciliation.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('CAREER-1046-OPS-SCOPE-RECONCILIATION-01', $artifact['task'] ?? null);
        $this->assertSame('career_1046_ops_scope_reconciliation_completed_requires_ops_read_model_repair', $artifact['final_decision'] ?? null);

        $this->assertSame(1046, $artifact['public_runtime_count'] ?? null);
        $this->assertSame(1046, data_get($artifact, 'public_runtime_locale_rows.en'));
        $this->assertSame(1046, data_get($artifact, 'public_runtime_locale_rows.zh-CN'));
        $this->assertSame(1046, $artifact['public_detail_indexable_count'] ?? null);
        $this->assertSame(2092, $artifact['sitemap_career_url_count'] ?? null);
        $this->assertSame(2092, $artifact['llms_career_url_count'] ?? null);
        $this->assertSame(2092, $artifact['llms_full_career_unique_url_count'] ?? null);

        $this->assertSame(378, $artifact['cms_ops_career_count'] ?? null);
        $this->assertSame('career_jobs', data_get($artifact, 'cms_ops_source.table'));
        $this->assertTrue((bool) data_get($artifact, 'cms_ops_source.excludes_runtime_projection'));
        $this->assertSame(378, $artifact['seo_ops_gap_count'] ?? null);
        $this->assertSame(398, $artifact['published_discovery_blocker_count'] ?? null);
        $this->assertSame(20, $artifact['career_guide_gap_count'] ?? null);

        $this->assertFalse($artifact['seo_intel_read_model_available'] ?? true);
        $this->assertSame(0, $artifact['url_truth_rows_visible'] ?? null);
        $this->assertFalse($artifact['search_channel_queue_visible'] ?? true);

        $this->assertSame([
            'expected_scope_mismatch',
            'legacy_cms_table_scope',
            'seo_ops_not_connected_to_runtime_projection',
            'seo_intel_read_model_unavailable_or_unpopulated',
        ], $artifact['mismatch_classification'] ?? null);
        $this->assertFalse($artifact['is_production_bug'] ?? true);
        $this->assertTrue($artifact['is_scope_mismatch'] ?? false);
        $this->assertTrue($artifact['is_read_model_stale'] ?? false);

        foreach ([
            'software-developers',
            'digital-forensics-analysts',
            'computer-occupations-all-other',
        ] as $slug) {
            $this->assertTrue((bool) data_get($artifact, "excluded_slugs_absent.{$slug}"), $slug);
        }

        foreach ([
            'production_write_performed',
            'database_mutation_performed',
            'cms_mutation_performed',
            'runtime_promotion_performed',
            'deploy_performed',
            'fap_web_runtime_change_performed',
            'fap_web_commit_performed',
            'search_channel_action_performed',
            'url_submission_performed',
            'external_search_api_call_performed',
            'raw_log_read_performed',
            'production_user_data_access_performed',
        ] as $field) {
            $this->assertFalse((bool) data_get($artifact, "safety_boundaries.{$field}"), $field);
        }
    }
}
