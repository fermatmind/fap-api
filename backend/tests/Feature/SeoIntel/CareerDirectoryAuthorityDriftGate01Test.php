<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class CareerDirectoryAuthorityDriftGate01Test extends TestCase
{
    public function test_career_directory_discoverability_counts_stay_aligned(): void
    {
        $reportPath = base_path('docs/seo/generated/career-directory-authority-drift-gate-01.v1.json');

        $this->assertFileExists($reportPath);

        $report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('career_directory_authority_drift_gate.v1', $report['schema_version'] ?? null);
        $this->assertSame('CAREER-DIRECTORY-AUTHORITY-DRIFT-GATE-01', $report['task'] ?? null);
        $this->assertSame(
            'career_directory_authority_drift_gate_completed_ready_for_llms_full_10k_budget_gate',
            $report['final_decision'] ?? null,
        );
        $this->assertSame('career.directory_authority.v1', $report['authority_source'] ?? null);

        $directoryCount = $report['directory_public_detail_count'] ?? null;
        $publicDetailIndexableCount = $report['public_detail_indexable_count'] ?? null;
        $localeCount = $report['locale_count'] ?? null;
        $localizedUrlCount = $report['localized_public_career_url_count'] ?? null;
        $sitemapCount = $report['sitemap_career_detail_url_count'] ?? null;
        $llmsCount = $report['llms_career_detail_url_count_expected'] ?? null;
        $llmsFullCount = $report['llms_full_career_detail_url_count_expected'] ?? null;

        $this->assertSame(1046, $directoryCount);
        $this->assertSame($directoryCount, $publicDetailIndexableCount);
        $this->assertSame(2, $localeCount);
        $this->assertSame($directoryCount * $localeCount, $localizedUrlCount);
        $this->assertSame($localizedUrlCount, $sitemapCount);
        $this->assertSame($sitemapCount, $llmsCount);
        $this->assertSame($sitemapCount, $llmsFullCount);

        foreach ([
            'directory_equals_public_detail_indexable',
            'localized_urls_equal_directory_times_locale_count',
            'sitemap_equals_localized_public_career_urls',
            'llms_equals_sitemap_career_urls',
            'llms_full_equals_sitemap_career_urls_when_complete_artifact_is_warm',
        ] as $field) {
            $this->assertTrue($report['count_invariants'][$field] ?? false, $field);
        }

        $this->assertSame([
            'software-developers',
            'digital-forensics-analysts',
            'computer-occupations-all-other',
        ], $report['held_slugs'] ?? null);

        foreach (['directory', 'detail_runtime', 'sitemap', 'llms', 'llms_full', 'footer', 'search_channel'] as $surface) {
            $this->assertTrue($report['held_slug_absence'][$surface] ?? false, $surface);
        }

        foreach ([
            'directory_public_detail_count_mismatch',
            'public_detail_indexable_count_mismatch',
            'sitemap_career_detail_url_count_mismatch',
            'llms_career_detail_url_count_mismatch',
            'held_slug_exposure',
            'frontend_fallback_authority',
            'runtime_fanout_authority',
        ] as $failureMode) {
            $this->assertContains($failureMode, $report['drift_gate_fails_on'] ?? []);
        }

        foreach ([
            'production_write_performed',
            'database_mutation_performed',
            'cms_mutation_performed',
            'runtime_promotion_performed',
            'sitemap_mutation_performed',
            'llms_mutation_performed',
            'deploy_performed',
            'fap_web_change_performed',
            'frontend_fallback_authority_used',
            'search_channel_action_performed',
            'url_submission_performed',
            'external_search_api_call_performed',
        ] as $field) {
            $this->assertFalse($report['safety_boundaries'][$field] ?? true, $field);
        }

        $this->assertSame('CAREER-LLMS-FULL-10K-BUDGET-GATE-01', $report['next_task'] ?? null);
    }
}
