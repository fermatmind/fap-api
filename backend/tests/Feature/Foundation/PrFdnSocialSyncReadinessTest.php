<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PrFdnSocialSyncReadinessTest extends TestCase
{
    #[Test]
    public function generated_readiness_artifact_locks_manual_social_sync_boundaries(): void
    {
        $reportPath = base_path('docs/seo/pr-fdn-social-sync-readiness.md');
        $generatedPath = base_path('docs/seo/generated/pr-fdn-social-sync-readiness.v1.json');

        $this->assertFileExists($reportPath);
        $this->assertFileExists($generatedPath);

        $payload = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('PR-FDN-SOCIAL-SYNC-READINESS', $payload['task'] ?? null);
        $this->assertSame(
            'pr_fdn_social_sync_readiness_completed_ready_for_manual_social_sync_mvp',
            $payload['final_decision'] ?? null
        );
        $this->assertSame('manual_social_url_recording_ready', $payload['readiness_state'] ?? null);

        $capabilities = $payload['current_capabilities'] ?? [];
        $this->assertTrue($capabilities['manual_social_url_storage'] ?? false);
        $this->assertTrue($capabilities['manual_admin_entry'] ?? false);
        $this->assertTrue($capabilities['public_api_exposes_social_links'] ?? false);
        $this->assertFalse($capabilities['automatic_posting'] ?? true);
        $this->assertFalse($capabilities['credential_storage'] ?? true);
        $this->assertFalse($capabilities['platform_api_clients'] ?? true);
        $this->assertFalse($capabilities['posting_queue'] ?? true);

        $this->assertSame('human_publish_manual_link_recording', $payload['recommended_mvp']['mode'] ?? null);
        $this->assertSame('manual_url_only', $payload['platform_readiness']['x'] ?? null);
        $this->assertSame('manual_url_only', $payload['platform_readiness']['linkedin'] ?? null);
        $this->assertSame('manual_url_only', $payload['platform_readiness']['weibo'] ?? null);
        $this->assertSame('manual_url_only', $payload['platform_readiness']['xiaohongshu'] ?? null);

        $this->assertTrue($payload['no_production_mutation'] ?? false);
        $this->assertTrue($payload['no_cms_mutation'] ?? false);
        $this->assertTrue($payload['no_deploy'] ?? false);
        $this->assertTrue($payload['no_search_channel_action'] ?? false);
        $this->assertTrue($payload['no_url_submission'] ?? false);
        $this->assertTrue($payload['no_external_api_call'] ?? false);
        $this->assertTrue($payload['no_credentials_handled'] ?? false);
        $this->assertTrue($payload['no_automatic_posting'] ?? false);
        $this->assertNotEmpty($payload['next_task'] ?? null);
    }
}
