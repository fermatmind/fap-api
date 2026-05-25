<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhParityScan00Test extends TestCase
{
    #[Test]
    public function generated_master_scan_artifact_lands_expected_read_only_contract(): void
    {
        $path = base_path('docs/seo/generated/global-en-zh-parity-scan-00.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('global-en-zh-parity-scan-00.v1', $payload['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-PARITY-SCAN-00', $payload['task'] ?? null);

        $this->assertTrue((bool) ($payload['no_mutation_performed'] ?? false));
        $this->assertFalse((bool) ($payload['cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['deploy_performed'] ?? true));
        $this->assertFalse((bool) ($payload['search_channel_action_performed'] ?? true));
        $this->assertFalse((bool) ($payload['url_submission_performed'] ?? true));
        $this->assertFalse((bool) ($payload['production_user_data_accessed'] ?? true));

        $this->assertIsArray($payload['scan_lanes'] ?? null);
        $this->assertIsArray($payload['page_family_counts'] ?? null);
        $this->assertIsArray($payload['p0_findings'] ?? null);
        $this->assertIsArray($payload['p1_findings'] ?? null);
        $this->assertIsArray($payload['p2_findings'] ?? null);
        $this->assertIsArray($payload['deferred_human_review_assets'] ?? null);
        $this->assertIsArray($payload['recommended_next_tasks'] ?? null);

        $this->assertArrayHasKey('final_decision', $payload);
        $this->assertArrayHasKey('next_task', $payload);
        $this->assertIsInt($payload['scanned_url_count'] ?? null);
        $this->assertGreaterThan(0, $payload['scanned_url_count']);

        $p0Ids = array_column($payload['p0_findings'], 'id');
        $this->assertContains('p0_sitemap_exposes_content_help_policy_404_urls', $p0Ids);
        $this->assertContains('p0_sitemap_exposes_career_job_detail_404_urls', $p0Ids);

        $this->assertSame(
            'GLOBAL-EN-ZH-PARITY-P0-FIX-TRAIN-01',
            $payload['next_task'] ?? null
        );
    }
}
