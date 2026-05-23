<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbtiAction01bSearchChannelEnqueueTest extends TestCase
{
    #[Test]
    public function generated_artifact_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-growth-mbti-action-01b-search-channel-enqueue.md'));

        $artifact = $this->artifact();

        $this->assertSame('seo-growth-mbti-action-01b-search-channel-enqueue.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-ACTION-01B', $artifact['task'] ?? null);
    }

    #[Test]
    public function approval_url_and_channel_are_locked(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue($artifact['approval_phrase_verified'] ?? false);
        $this->assertSame('https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types', $artifact['url'] ?? null);
        $this->assertSame('indexnow', $artifact['channel'] ?? null);
    }

    #[Test]
    public function safety_flags_confirm_no_bulk_enqueue_submission_or_mutation(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'bulk_enqueue_attempted',
            'live_submission_attempted',
            'external_api_call_attempted',
            'search_submission_attempted',
            'cms_mutation_attempted',
            'url_truth_write_attempted',
            'sitemap_mutation_attempted',
            'llms_mutation_attempted',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function deferred_candidates_include_zh_mbti_and_research_urls(): void
    {
        $urls = array_column($this->artifact()['candidates_forbidden_or_deferred'] ?? [], 'url');

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

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-action-01b-search-channel-enqueue.v1.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
