<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbtiAction01bFix02ProdPreflightTest extends TestCase
{
    #[Test]
    public function generated_json_exists_and_parses(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(
            'seo-growth-mbti-action-01b-fix-02-prod-preflight.v1',
            $artifact['schema_version'] ?? null
        );
        $this->assertSame(
            'SEO-GROWTH-MBTI-ACTION-01B-FIX-02-PROD-PREFLIGHT',
            $artifact['task'] ?? null
        );
    }

    #[Test]
    public function safety_flags_lock_no_write_no_enqueue_no_submission_no_external_api_and_no_cms_mutation(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue($artifact['no_write_performed'] ?? false);
        $this->assertTrue($artifact['no_enqueue'] ?? false);
        $this->assertTrue($artifact['no_submission'] ?? false);
        $this->assertTrue($artifact['no_external_api_call'] ?? false);
        $this->assertTrue($artifact['no_cms_mutation'] ?? false);
        $this->assertFalse($artifact['sitemap_llms_authority_used'] ?? true);
    }

    #[Test]
    public function expected_candidate_urls_are_included(): void
    {
        $urls = $this->artifact()['expected_candidate_urls'] ?? [];

        foreach ([
            'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types',
            'https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report',
            'https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report',
        ] as $url) {
            $this->assertContains($url, $urls);
        }
    }

    #[Test]
    public function final_decision_and_next_task_are_present(): void
    {
        $artifact = $this->artifact();

        $this->assertIsString($artifact['final_decision'] ?? null);
        $this->assertNotSame('', $artifact['final_decision'] ?? '');
        $this->assertIsString($artifact['next_task'] ?? null);
        $this->assertNotSame('', $artifact['next_task'] ?? '');
    }

    #[Test]
    public function report_records_expected_candidate_presence_and_persisted_www_conflict(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue($artifact['expected_apex_candidates_present']['all_present'] ?? false);
        $this->assertFalse($artifact['www_candidates_present']['backend_authority_dry_run_www_candidates_present'] ?? true);
        $this->assertTrue($artifact['www_candidates_present']['persisted_research_www_rows_present'] ?? false);
        $this->assertTrue($artifact['conflict_risk']['cleanup_or_retire_step_needed'] ?? false);
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-action-01b-fix-02-prod-preflight.v1.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
