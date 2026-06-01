<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class CareerLegacyFullJobsIndexConsumerAudit01Test extends TestCase
{
    public function test_legacy_full_jobs_index_consumer_audit_artifact_is_complete(): void
    {
        $reportPath = base_path('docs/seo/generated/career-legacy-full-jobs-index-consumer-audit-01.v1.json');

        $this->assertFileExists($reportPath);

        $report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('career_legacy_full_jobs_index_consumer_audit.v1', $report['schema_version'] ?? null);
        $this->assertSame('CAREER-LEGACY-FULL-JOBS-INDEX-CONSUMER-AUDIT-01', $report['task'] ?? null);
        $this->assertSame(
            'career_legacy_full_jobs_index_consumer_audit_completed_ready_for_directory_authority_drift_gate',
            $report['final_decision'] ?? null,
        );

        $this->assertSame('/api/v0.5/career/jobs', $report['legacy_endpoint']['path'] ?? null);
        $this->assertFalse($report['legacy_endpoint']['ten_k_directory_authority'] ?? true);

        $backendFiles = array_column($report['backend_consumers'] ?? [], 'file');
        foreach ([
            'backend/routes/api.php',
            'backend/app/Http/Controllers/API/V0_5/Career/CareerJobListController.php',
            'backend/app/Console/Commands/ReleaseVerifyPublicContent.php',
            'backend/app/Console/Commands/CareerValidateDisplayBatch.php',
            'backend/app/Console/Commands/CareerImportSelectedDisplayAssets.php',
            'backend/app/Console/Commands/CareerAlignSelectedOnetCrosswalks.php',
        ] as $expectedFile) {
            $this->assertContains($expectedFile, $backendFiles);
        }

        $frontendFiles = array_column($report['frontend_reference_consumers'] ?? [], 'file');
        foreach ([
            'lib/career/api/fetchCareerJobIndex.ts',
            'app/(localized)/[locale]/career/industries/page.tsx',
            'app/(localized)/[locale]/career/industries/[slug]/page.tsx',
        ] as $expectedFile) {
            $this->assertContains($expectedFile, $frontendFiles);
        }

        $this->assertTrue($report['non_consumers_confirmed']['frontend_career_jobs_page_uses_directory_endpoint'] ?? false);
        $this->assertTrue($report['non_consumers_confirmed']['sitemap_should_not_depend_on_legacy_full_index'] ?? false);
        $this->assertTrue($report['non_consumers_confirmed']['llms_should_not_depend_on_legacy_full_index'] ?? false);

        $this->assertContains('frontend_industry_pages_still_consume_full_jobs_index', $report['risk_summary']['p1'] ?? []);
        $this->assertContains('legacy_fetcher_can_be_reintroduced_into_directory_or_llms_paths', $report['risk_summary']['p1'] ?? []);

        foreach ([
            'production_write_performed',
            'database_mutation_performed',
            'cms_mutation_performed',
            'runtime_promotion_performed',
            'deploy_performed',
            'fap_web_change_performed',
            'search_channel_action_performed',
            'url_submission_performed',
            'external_search_api_call_performed',
        ] as $field) {
            $this->assertFalse($report['safety_boundaries'][$field] ?? true, $field);
        }

        $this->assertSame('CAREER-DIRECTORY-AUTHORITY-DRIFT-GATE-01', $report['next_task'] ?? null);
    }
}
