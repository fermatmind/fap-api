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

    public function test_ingest_endpoint_accepts_canonical_submit_attempt_payload(): void
    {
        config()->set('fap.events.ingest_token', 'ingest_test_token');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ingest_test_token',
        ])->postJson('/api/v0.3/analytics/mbti-attribution-events', [
            'eventName' => 'submit_attempt',
            'anonymousId' => 'anon_submit_001',
            'path' => '/en/tests/big-five-personality-test/take',
            'timestamp' => '2026-05-14T20:00:00Z',
            'payload' => [
                'slug' => 'big-five-personality-test',
                'test_slug' => 'big-five-personality-test',
                'scale_code' => 'BIG5_OCEAN',
                'form_code' => 'big5_90',
                'attempt_id' => 'attempt-big5-123',
                'answered_count' => 90,
                'durationMs' => 121000,
                'duration_ms' => 121000,
                'duration_bucket' => '1_3m',
                'landing_path' => '/en/tests/big-five-personality-test',
                'current_path' => '/en/tests/big-five-personality-test/take',
                'utm_source' => 'google',
                'session_id' => 'anon_submit_001',
                'locale' => 'en',
            ],
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('event_code', 'submit_attempt');

        $row = DB::table('events')
            ->where('event_code', 'submit_attempt')
            ->where('anon_id', 'anon_submit_001')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('BIG5_OCEAN', (string) ($row->scale_code ?? ''));
        $this->assertSame('attempt-big5-123', (string) ($row->attempt_id ?? ''));

        $meta = is_array($row->meta_json ?? null)
            ? $row->meta_json
            : (json_decode((string) ($row->meta_json ?? '{}'), true) ?: []);

        $this->assertSame('submit_attempt', (string) ($row->event_name ?? ''));
        $this->assertSame('big-five-personality-test', $meta['test_slug'] ?? null);
        $this->assertSame(90, (int) ($meta['raw_payload']['answered_count'] ?? 0));
        $this->assertSame(121000, (int) ($meta['raw_payload']['durationMs'] ?? 0));
        $this->assertSame('/en/tests/big-five-personality-test/take', $meta['raw_payload']['current_path'] ?? null);
    }

    public function test_seo_attribution_endpoint_accepts_canonical_conversion_funnel_and_sanitizes_dimensions(): void
    {
        config()->set('fap.events.ingest_token', 'ingest_test_token');

        $events = [
            'landing_pv' => '/en/articles/personality-types?token=secret',
            'article_to_test_click' => '/en/articles/personality-types?email=person@example.com',
            'start_test' => '/en/tests/mbti-personality-test-16-personality-types/take?attempt_id=raw_attempt',
            'complete_test' => '/en/tests/mbti-personality-test-16-personality-types/take?result_id=raw_result',
            'view_result' => '/en/tests/mbti-personality-test-16-personality-types?order_id=raw_order',
        ];

        foreach ($events as $eventName => $path) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ingest_test_token',
            ])->postJson('/api/v0.5/seo/attribution/events', [
                'eventName' => $eventName,
                'path' => $path,
                'timestamp' => '2026-06-09T02:00:00Z',
                'payload' => [
                    'url' => 'https://fermatmind.com'.$path,
                    'lang' => 'en',
                    'page_type' => $eventName === 'landing_pv' ? 'article' : 'test',
                    'source_url' => 'https://fermatmind.com/en/articles/personality-types?token=secret',
                    'source_article' => 'personality-types',
                    'target_test' => '/en/tests/mbti-personality-test-16-personality-types?session=secret',
                    'scale_id' => 'MBTI',
                    'form_id' => 'mbti_144',
                    'session_id' => 'seo_sess_1234567890abcdef',
                    'referrer' => 'https://www.google.com/search?q=mbti&email=person@example.com',
                ],
            ]);

            $response->assertStatus(202);
            $response->assertJsonPath('event_code', $eventName);
        }

        $this->assertSame(1, DB::table('events')->where('event_code', 'article_to_test_click')->count());
        $this->assertSame(1, DB::table('events')->where('event_code', 'start_test')->count());

        $row = DB::table('events')
            ->where('event_code', 'article_to_test_click')
            ->first();

        $this->assertNotNull($row);

        $meta = is_array($row->meta_json ?? null)
            ? $row->meta_json
            : (json_decode((string) ($row->meta_json ?? '{}'), true) ?: []);

        $this->assertSame('article_to_test_click', (string) ($row->event_name ?? ''));
        $this->assertSame('/en/articles/personality-types', $meta['path'] ?? null);
        $this->assertSame('https://fermatmind.com/en/articles/personality-types', $meta['seo_conversion']['url'] ?? null);
        $this->assertSame('https://fermatmind.com/en/articles/personality-types', $meta['seo_conversion']['source_url'] ?? null);
        $this->assertSame('/en/tests/mbti-personality-test-16-personality-types', $meta['seo_conversion']['target_test'] ?? null);
        $this->assertSame('seo_sess_1234567890abcdef', $meta['seo_conversion']['session_id'] ?? null);
        $this->assertStringNotContainsString('secret', json_encode($meta, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('person@example.com', json_encode($meta, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('raw_attempt', json_encode($meta, JSON_THROW_ON_ERROR));
    }

    public function test_seo_attribution_endpoint_rejects_private_paths_and_raw_business_identifiers(): void
    {
        config()->set('fap.events.ingest_token', 'ingest_test_token');

        $privatePath = $this->withHeaders([
            'Authorization' => 'Bearer ingest_test_token',
        ])->postJson('/api/v0.5/seo/attribution/events', [
            'eventName' => 'start_test',
            'path' => '/en/results/private-result-id',
            'payload' => [
                'url' => '/en/results/private-result-id',
                'lang' => 'en',
                'page_type' => 'result',
                'scale_id' => 'MBTI',
                'form_id' => 'mbti_144',
                'session_id' => 'seo_sess_1234567890abcdef',
            ],
        ]);

        $privatePath->assertStatus(422);

        $rawIdentifier = $this->withHeaders([
            'Authorization' => 'Bearer ingest_test_token',
        ])->postJson('/api/v0.5/seo/attribution/events', [
            'eventName' => 'complete_test',
            'path' => '/en/tests/mbti-personality-test-16-personality-types/take',
            'payload' => [
                'url' => '/en/tests/mbti-personality-test-16-personality-types/take',
                'lang' => 'en',
                'page_type' => 'test',
                'scale_id' => 'MBTI',
                'form_id' => 'mbti_144',
                'session_id' => 'seo_sess_1234567890abcdef',
                'order_id' => 'ord_raw_123',
            ],
        ]);

        $rawIdentifier->assertStatus(422);

        $this->assertSame(0, DB::table('events')->count());
    }

    public function test_ingest_endpoint_accepts_purchase_payload_with_non_pii_order_identifier(): void
    {
        config()->set('fap.events.ingest_token', 'ingest_test_token');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ingest_test_token',
        ])->postJson('/api/v0.3/analytics/mbti-attribution-events', [
            'eventName' => 'purchase_success',
            'anonymousId' => 'anon_purchase_001',
            'path' => '/zh/orders/ord_masked_001',
            'payload' => [
                'order_no' => 'ord_12...0001',
                'transaction_id' => 'ord_12...0001',
                'attemptIdMasked' => 'attempt...0001',
                'orderNoMasked' => 'ord_12...0001',
                'amount' => 88,
                'currency' => 'CNY',
                'provider' => 'alipay',
                'scale_code' => 'MBTI',
                'form_code' => 'mbti_144',
                'locale' => 'zh',
            ],
        ]);

        $response->assertStatus(202);

        $row = DB::table('events')
            ->where('event_code', 'purchase_success')
            ->where('anon_id', 'anon_purchase_001')
            ->first();

        $this->assertNotNull($row);

        $meta = is_array($row->meta_json ?? null)
            ? $row->meta_json
            : (json_decode((string) ($row->meta_json ?? '{}'), true) ?: []);

        $this->assertSame('ord_12...0001', $meta['raw_payload']['transaction_id'] ?? null);
        $this->assertSame('CNY', $meta['raw_payload']['currency'] ?? null);
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

    public function test_ingest_endpoint_rejects_when_ingest_token_is_not_configured(): void
    {
        config()->set('fap.events.ingest_token', '');

        $response = $this->postJson('/api/v0.3/analytics/mbti-attribution-events', [
            'eventName' => 'landing_view',
            'payload' => [
                'entry_surface' => 'mbti_topic_detail',
            ],
        ]);

        $response->assertStatus(503);
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('error_code', 'INGEST_DISABLED');

        $this->assertSame(0, DB::table('events')->count());
    }

    public function test_ingest_endpoint_rejects_unauthenticated_caller_supplied_org_header(): void
    {
        config()->set('fap.events.ingest_token', 'ingest_test_token');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ingest_test_token',
            'X-Org-Id' => '42',
        ])->postJson('/api/v0.3/analytics/mbti-attribution-events', [
            'eventName' => 'landing_view',
            'payload' => [
                'entry_surface' => 'mbti_topic_detail',
            ],
        ]);

        $response->assertStatus(404);

        $this->assertSame(0, DB::table('events')->count());
    }

    public function test_ingest_endpoint_rejects_unexpected_payload_shape(): void
    {
        config()->set('fap.events.ingest_token', 'ingest_test_token');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ingest_test_token',
        ])->postJson('/api/v0.3/analytics/mbti-attribution-events', [
            'eventName' => 'landing_view',
            'payload' => [
                'entry_surface' => 'mbti_topic_detail',
                'org_id' => 42,
            ],
        ]);

        $response->assertStatus(422);

        $this->assertSame(0, DB::table('events')->count());
    }

    public function test_ingest_endpoint_rejects_email_inside_tracking_payload(): void
    {
        config()->set('fap.events.ingest_token', 'ingest_test_token');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ingest_test_token',
        ])->postJson('/api/v0.3/analytics/mbti-attribution-events', [
            'eventName' => 'purchase_success',
            'payload' => [
                'transaction_id' => 'person@example.com',
            ],
        ]);

        $response->assertStatus(422);

        $this->assertSame(0, DB::table('events')->count());
    }

    public function test_ingest_endpoint_rejects_malformed_identifiers(): void
    {
        config()->set('fap.events.ingest_token', 'ingest_test_token');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ingest_test_token',
        ])->postJson('/api/v0.3/analytics/mbti-attribution-events', [
            'eventName' => 'landing_view',
            'anonymousId' => ['not-scalar'],
            'payload' => [
                'entry_surface' => 'mbti_topic_detail',
            ],
        ]);

        $response->assertStatus(422);

        $this->assertSame(0, DB::table('events')->count());
    }
}
