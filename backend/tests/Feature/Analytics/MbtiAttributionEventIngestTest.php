<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MbtiAttributionEventIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_endpoint_persists_mbti_attribution_event_with_envelope_payload(): void
    {
        config()->set('fap.events.ingest_token', 'ingest_test_token');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ingest_test_token',
        ])->postJson('/api/v0.3/analytics/mbti-attribution-events', [
            'eventName' => 'landing_view',
            'anonymousId' => 'anon_pr7_001',
            'path' => '/zh/topics/mbti',
            'timestamp' => '2026-04-06T10:00:00+08:00',
            'payload' => [
                'entry_surface' => 'mbti_topic_detail',
                'source_page_type' => 'topic_detail',
                'target_action' => 'start_mbti_test_primary',
                'test_slug' => 'mbti-personality-test-16-personality-types',
                'form_code' => 'mbti_144',
                'landing_path' => '/zh/topics/mbti',
                'locale' => 'zh',
            ],
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('event_code', 'landing_view');

        $row = DB::table('events')
            ->where('event_code', 'landing_view')
            ->where('anon_id', 'anon_pr7_001')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(0, (int) ($row->org_id ?? 0));

        $meta = is_array($row->meta_json ?? null)
            ? $row->meta_json
            : (json_decode((string) ($row->meta_json ?? '{}'), true) ?: []);

        $this->assertSame('mbti_topic_detail', $meta['entry_surface'] ?? null);
        $this->assertSame('/zh/topics/mbti', $meta['landing_path'] ?? null);
    }

    public function test_ingest_endpoint_rejects_missing_or_invalid_ingest_token(): void
    {
        config()->set('fap.events.ingest_token', 'ingest_test_token');

        $response = $this->postJson('/api/v0.3/analytics/mbti-attribution-events', [
            'eventName' => 'landing_view',
            'payload' => [
                'entry_surface' => 'mbti_topic_detail',
            ],
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('error_code', 'UNAUTHORIZED');
    }
}
