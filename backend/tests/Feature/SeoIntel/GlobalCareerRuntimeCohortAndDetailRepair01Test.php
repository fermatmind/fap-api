<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalCareerRuntimeCohortAndDetailRepair01Test extends TestCase
{
    #[Test]
    public function generated_report_exists_and_records_non_mutating_boundaries(): void
    {
        $payload = $this->payload();

        $this->assertSame('global-career-runtime-cohort-and-detail-repair-01.v1', $payload['schema_version'] ?? null);
        $this->assertSame('GLOBAL-CAREER-RUNTIME-COHORT-AND-DETAIL-REPAIR-01', $payload['task'] ?? null);
        $this->assertTrue((bool) ($payload['no_cms_mutation'] ?? false));
        $this->assertTrue((bool) ($payload['no_deploy'] ?? false));
        $this->assertTrue((bool) ($payload['no_search_channel_action'] ?? false));
        $this->assertTrue((bool) ($payload['no_url_submission'] ?? false));
        $this->assertTrue((bool) ($payload['no_external_search_api_call'] ?? false));
        $this->assertTrue((bool) ($payload['no_pseo_generation'] ?? false));
    }

    #[Test]
    public function required_diagnosis_sections_are_present(): void
    {
        $payload = $this->payload();

        foreach ([
            'career_count_meaning',
            'public_cohort_policy',
            'sampled_runtime_checks',
            'api_runtime_checks',
            'sitemap_exposure_check',
            'llms_exposure_check',
            'footer_exposure_check',
            'frontend_notfound_behavior',
            'claim_boundary_state',
            'recommended_fix',
            'final_decision',
            'next_task',
        ] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }
    }

    #[Test]
    public function current_policy_fails_closed_without_discoverability_exposure(): void
    {
        $payload = $this->payload();

        $this->assertSame(30, $payload['public_cohort_policy']['current_public_index_items'] ?? null);
        $this->assertTrue((bool) ($payload['public_cohort_policy']['non_cohort_slugs_fail_closed'] ?? false));
        $this->assertFalse((bool) ($payload['api_runtime_checks']['career_jobs_en']['contains_accountants_and_auditors'] ?? true));
        $this->assertFalse((bool) ($payload['api_runtime_checks']['career_jobs_en']['contains_software_developers'] ?? true));
        $this->assertSame(0, $payload['sitemap_exposure_check']['career_job_detail_url_count'] ?? null);
        $this->assertSame(0, $payload['llms_exposure_check']['llms_txt_career_job_detail_url_count'] ?? null);
        $this->assertSame(0, $payload['llms_exposure_check']['llms_full_txt_career_job_detail_url_count'] ?? null);
        $this->assertFalse((bool) ($payload['footer_exposure_check']['sampled_404_urls_exposed'] ?? true));
    }

    #[Test]
    public function authority_mismatch_is_explicit_and_not_papered_over(): void
    {
        $payload = $this->payload();

        $this->assertIsArray($payload['authority_mismatch_findings'] ?? null);
        $this->assertNotEmpty($payload['authority_mismatch_findings']);
        $this->assertSame(18, $payload['sampled_runtime_checks']['current_cohort_frontend_robots_summary']['en_index'] ?? null);
        $this->assertSame(12, $payload['sampled_runtime_checks']['current_cohort_frontend_robots_summary']['en_noindex'] ?? null);
        $this->assertSame(0, $payload['sampled_runtime_checks']['current_cohort_frontend_robots_summary']['zh_index'] ?? null);
        $this->assertSame(30, $payload['sampled_runtime_checks']['current_cohort_frontend_robots_summary']['zh_noindex'] ?? null);
        $this->assertSame('career_runtime_requires_controlled_cms_or_cohort_publish', $payload['final_decision'] ?? null);
        $this->assertSame('GLOBAL-CAREER-RUNTIME-COHORT-PUBLISH-AUTHORITY-ALIGNMENT-01', $payload['next_task'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $path = dirname(__DIR__, 3).'/docs/seo/generated/global-career-runtime-cohort-and-detail-repair-01.v1.json';

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
