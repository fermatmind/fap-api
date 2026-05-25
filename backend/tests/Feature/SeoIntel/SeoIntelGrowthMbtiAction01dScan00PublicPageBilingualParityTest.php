<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbtiAction01dScan00PublicPageBilingualParityTest extends TestCase
{
    #[Test]
    public function generated_scan_artifact_exists_and_parses(): void
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-action-01d-scan-00-public-page-bilingual-parity.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('seo-growth-mbti-action-01d-scan-00-public-page-bilingual-parity.v1', $payload['schema_version'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-ACTION-01D-SCAN-00', $payload['task'] ?? null);
        $this->assertTrue((bool) ($payload['no_mutation_performed'] ?? false));
        $this->assertFalse((bool) ($payload['search_channel_action_performed'] ?? true));
        $this->assertFalse((bool) ($payload['cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['deploy_performed'] ?? true));
        $this->assertArrayHasKey('scanned_url_count', $payload);
        $this->assertArrayHasKey('page_family_counts', $payload);
        $this->assertArrayHasKey('mbti_core_pages', $payload);
        $this->assertIsArray($payload['p0_findings'] ?? null);
        $this->assertIsArray($payload['p1_findings'] ?? null);
        $this->assertIsArray($payload['p2_findings'] ?? null);
        $this->assertArrayHasKey('recommended_next_tasks', $payload);
        $this->assertArrayHasKey('final_decision', $payload);
        $this->assertArrayHasKey('next_task', $payload);
    }
}
