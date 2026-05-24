<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbtiAction01bFix02WwwCanonicalCleanupPlanTest extends TestCase
{
    #[Test]
    public function generated_json_exists_and_parses(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(
            'seo-growth-mbti-action-01b-fix-02-www-canonical-cleanup-plan.v1',
            $artifact['schema_version'] ?? null
        );
        $this->assertSame(
            'SEO-GROWTH-MBTI-ACTION-01B-FIX-02-WWW-CANONICAL-CLEANUP-PLAN',
            $artifact['task'] ?? null
        );
    }

    #[Test]
    public function expected_stale_replacement_and_excluded_urls_are_listed(): void
    {
        $artifact = $this->artifact();

        $this->assertContains(
            'https://www.fermatmind.com/en/research/mbti-personality-types-salary-turnover-report',
            $artifact['stale_www_urls'] ?? []
        );
        $this->assertContains(
            'https://www.fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report',
            $artifact['stale_www_urls'] ?? []
        );
        $this->assertContains(
            'https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report',
            $artifact['replacement_apex_urls'] ?? []
        );
        $this->assertContains(
            'https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report',
            $artifact['replacement_apex_urls'] ?? []
        );
        $this->assertContains(
            'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types',
            $artifact['additional_safe_write_candidates'] ?? []
        );

        $excludedUrls = array_column($artifact['already_submitted_urls_excluded'] ?? [], 'url');
        $this->assertContains(
            'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
            $excludedUrls
        );
    }

    #[Test]
    public function safety_flags_lock_no_production_write_enqueue_submission_external_api_or_cms_mutation(): void
    {
        $artifact = $this->artifact();

        $this->assertFalse($artifact['production_write_performed'] ?? true);
        $this->assertFalse($artifact['collector_write_performed'] ?? true);
        $this->assertFalse($artifact['enqueue_performed'] ?? true);
        $this->assertFalse($artifact['live_submission_performed'] ?? true);
        $this->assertFalse($artifact['external_api_call_performed'] ?? true);
        $this->assertFalse($artifact['cms_mutation_performed'] ?? true);
        $this->assertFalse($artifact['sitemap_llms_authority_used'] ?? true);
        $this->assertFalse($artifact['frontend_fallback_authority_used'] ?? true);
    }

    #[Test]
    public function cleanup_strategy_duplicate_prevention_approval_phrase_and_next_task_exist(): void
    {
        $artifact = $this->artifact();

        $this->assertNotEmpty($artifact['cleanup_strategy'] ?? []);
        $this->assertNotEmpty($artifact['duplicate_cluster_prevention'] ?? []);
        $this->assertIsString($artifact['future_human_approval_phrase'] ?? null);
        $this->assertNotSame('', $artifact['future_human_approval_phrase'] ?? '');
        $this->assertIsString($artifact['next_task'] ?? null);
        $this->assertNotSame('', $artifact['next_task'] ?? '');
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-action-01b-fix-02-www-canonical-cleanup-plan.v1.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
