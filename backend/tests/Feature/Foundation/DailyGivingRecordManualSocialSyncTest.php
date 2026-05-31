<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Models\DailyGivingRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DailyGivingRecordManualSocialSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_social_sync_status_uses_existing_social_link_fields_only(): void
    {
        $record = DailyGivingRecord::factory()->completed()->make([
            'social_x_url' => null,
            'social_linkedin_url' => null,
            'social_weibo_url' => null,
            'social_xiaohongshu_url' => null,
            'social_other_links' => null,
        ]);

        $this->assertSame(DailyGivingRecord::MANUAL_SOCIAL_SYNC_NOT_RECORDED, $record->manualSocialSyncStatus());
        $this->assertSame([], $record->manualSocialSyncLinks());

        $record->forceFill([
            'social_x_url' => 'https://x.com/fermatmind/status/123',
            'social_linkedin_url' => ' ',
            'social_weibo_url' => null,
            'social_xiaohongshu_url' => '',
            'social_other_links' => [
                'newsletter' => 'https://example.com/foundation-daily-giving',
                'empty' => '',
            ],
        ]);

        $this->assertSame(DailyGivingRecord::MANUAL_SOCIAL_SYNC_RECORDED, $record->manualSocialSyncStatus());
        $this->assertSame([
            'x' => 'https://x.com/fermatmind/status/123',
            'other' => [
                'newsletter' => 'https://example.com/foundation-daily-giving',
            ],
        ], $record->manualSocialSyncLinks());
    }

    public function test_public_api_exposes_manual_social_links_without_automatic_sync_state(): void
    {
        $record = DailyGivingRecord::factory()->completed()->create([
            'social_x_url' => 'https://x.com/fermatmind/status/123',
            'social_linkedin_url' => 'https://linkedin.com/feed/update/456',
            'social_weibo_url' => 'https://weibo.com/789',
            'social_xiaohongshu_url' => 'https://xiaohongshu.com/explore/abc',
            'social_other_links' => [
                'newsletter' => 'https://example.com/foundation-daily-giving',
            ],
        ]);

        $response = $this->getJson("/api/v0.5/foundation/giving-records/{$record->record_code}");

        $response->assertOk();

        $data = $response->json('record');

        $this->assertSame('https://x.com/fermatmind/status/123', $data['social_x_url']);
        $this->assertSame('https://linkedin.com/feed/update/456', $data['social_linkedin_url']);
        $this->assertSame('https://weibo.com/789', $data['social_weibo_url']);
        $this->assertSame('https://xiaohongshu.com/explore/abc', $data['social_xiaohongshu_url']);
        $this->assertSame(['newsletter' => 'https://example.com/foundation-daily-giving'], $data['social_other_links']);
        $this->assertArrayNotHasKey('manual_social_sync_status', $data);
        $this->assertArrayNotHasKey('automatic_posting_state', $data);
        $this->assertArrayNotHasKey('external_api_response', $data);
    }

    public function test_ops_resource_documents_manual_only_social_sync_boundary(): void
    {
        $source = (string) file_get_contents(app_path('Filament/Ops/Resources/DailyGivingRecordResource.php'));

        $this->assertStringContainsString('Manual social sync MVP', $source);
        $this->assertStringContainsString('No automatic posting', $source);
        $this->assertStringContainsString('credential handling', $source);
        $this->assertStringContainsString('external API calls', $source);
        $this->assertStringContainsString('manualSocialSyncStatus()', $source);
    }
}
