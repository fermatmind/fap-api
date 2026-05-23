<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbtiAction01aPreflightDryRunPackageTest extends TestCase
{
    #[Test]
    public function package_doc_and_generated_artifact_exist_and_parse(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-growth-mbti-action-01a-preflight-dry-run-package.md'));

        $artifact = $this->artifact();

        $this->assertSame('seo-growth-mbti-action-01a-preflight-dry-run-package.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-ACTION-01A', $artifact['task'] ?? null);
    }

    #[Test]
    public function safety_flags_lock_no_write_no_enqueue_no_submission_no_cms_and_no_outreach(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue($artifact['no_write'] ?? false);
        $this->assertTrue($artifact['no_enqueue'] ?? false);
        $this->assertTrue($artifact['no_submission'] ?? false);
        $this->assertTrue($artifact['no_cms_mutation'] ?? false);
        $this->assertTrue($artifact['no_digital_pr_send'] ?? false);
    }

    #[Test]
    public function candidate_urls_include_the_four_mbti_growth_candidates(): void
    {
        $urls = array_column($this->artifact()['candidate_urls'] ?? [], 'url');

        foreach ([
            '/en/tests/mbti-personality-test-16-personality-types',
            '/zh/tests/mbti-personality-test-16-personality-types',
            '/en/research/mbti-personality-types-salary-turnover-report',
            '/zh/research/mbti-personality-types-salary-turnover-report',
        ] as $url) {
            $this->assertContains($url, $urls);
        }
    }

    #[Test]
    public function search_channel_enqueue_is_not_ready_without_dry_run_candidates(): void
    {
        $artifact = $this->artifact();
        $candidateCount = (int) ($artifact['search_channel_dry_run_result']['candidate_count'] ?? 0);

        if ($candidateCount === 0) {
            $this->assertNotSame('ready', $artifact['action_01b_readiness']['status'] ?? null);
        }

        $this->assertSame('blocked', $artifact['action_01b_readiness']['status'] ?? null);
        $this->assertContains('seo_urls_source_unavailable', $artifact['search_channel_dry_run_result']['issues'] ?? []);
    }

    #[Test]
    public function digital_pr_send_and_cms_internal_link_repair_are_not_auto_ready(): void
    {
        $artifact = $this->artifact();

        $this->assertNotSame('ready', $artifact['action_01c_readiness']['status'] ?? null);
        $this->assertFalse($artifact['action_01c_readiness']['send_auto_ready'] ?? true);

        $this->assertNotSame('ready', $artifact['action_01d_readiness']['status'] ?? null);
        $this->assertFalse($artifact['action_01d_readiness']['repair_auto_ready'] ?? true);
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
    public function public_checks_record_no_dataset_json_ld_and_no_stale_turnover_slug(): void
    {
        foreach ($this->artifact()['public_url_checks'] ?? [] as $check) {
            $this->assertFalse((bool) ($check['dataset_json_ld_present'] ?? true), (string) ($check['url'] ?? 'unknown'));
            $this->assertFalse((bool) ($check['stale_turnover_rate_slug_present'] ?? true), (string) ($check['url'] ?? 'unknown'));
        }
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-action-01a-preflight-dry-run-package.v1.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
