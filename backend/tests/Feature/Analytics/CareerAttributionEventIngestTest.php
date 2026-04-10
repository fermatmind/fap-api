<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CareerAttributionEventIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_endpoint_persists_career_event_with_normalized_dimensions(): void
    {
        config()->set('fap.events.ingest_token', 'ingest_test_token');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ingest_test_token',
        ])->postJson('/api/v0.5/career/attribution/events', [
            'eventName' => 'career_job_search_submit',
            'anonymousId' => 'anon_b15_001',
            'sessionId' => 'session_b15_001',
            'requestId' => 'request_b15_001',
            'path' => '/zh/career/jobs?q=data',
            'timestamp' => '2026-04-10T19:00:00+08:00',
            'payload' => [
                'entry_surface' => 'career_job_index',
                'source_page_type' => 'job_index',
                'target_action' => 'submit_search',
                'landing_path' => '/zh/career/jobs?q=data',
                'route_family' => 'jobs_search',
                'subject_kind' => 'none',
                'query_mode' => 'query',
                'locale' => 'zh',
                'query_text' => 'data scientist',
            ],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('event_code', 'career_job_search_submit');

        $row = DB::table('events')
            ->where('event_code', 'career_job_search_submit')
            ->where('anon_id', 'anon_b15_001')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('CAREER', $row->scale_code);
        $this->assertSame('zh', $row->locale);

        $meta = is_array($row->meta_json ?? null)
            ? $row->meta_json
            : (json_decode((string) ($row->meta_json ?? '{}'), true) ?: []);

        $this->assertSame('career_job_index', $meta['entry_surface'] ?? null);
        $this->assertSame('jobs_search', $meta['route_family'] ?? null);
        $this->assertSame('query', $meta['query_mode'] ?? null);
        $this->assertArrayNotHasKey('query_text', $meta);
        $this->assertArrayNotHasKey('raw_payload', $meta);
    }

    public function test_ingest_endpoint_keeps_recommendation_interactions_distinct_from_job_interactions(): void
    {
        config()->set('fap.events.ingest_token', 'ingest_test_token');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ingest_test_token',
        ])->postJson('/api/v0.5/career/attribution/events', [
            'eventName' => 'career_recommendation_matched_job_click',
            'anonymousId' => 'anon_b15_002',
            'path' => '/zh/career/recommendations/mbti/intj',
            'timestamp' => '2026-04-10T19:05:00+08:00',
            'payload' => [
                'entry_surface' => 'career_recommendation_detail',
                'source_page_type' => 'recommendation_detail',
                'target_action' => 'matched_job_click',
                'landing_path' => '/zh/career/recommendations/mbti/intj',
                'route_family' => 'recommendation_detail',
                'subject_kind' => 'job_slug',
                'subject_key' => 'software-developers',
                'query_mode' => 'non_query',
                'locale' => 'zh',
            ],
        ]);

        $response->assertStatus(202);

        $row = DB::table('events')
            ->where('event_code', 'career_recommendation_matched_job_click')
            ->where('anon_id', 'anon_b15_002')
            ->first();

        $this->assertNotNull($row);
        $meta = is_array($row->meta_json ?? null)
            ? $row->meta_json
            : (json_decode((string) ($row->meta_json ?? '{}'), true) ?: []);

        $this->assertSame('career_recommendation_detail', $meta['entry_surface'] ?? null);
        $this->assertSame('recommendation_detail', $meta['route_family'] ?? null);
        $this->assertSame('job_slug', $meta['subject_kind'] ?? null);
        $this->assertSame('software-developers', $meta['subject_key'] ?? null);
    }

    public function test_ingest_endpoint_rejects_invalid_event_name(): void
    {
        config()->set('fap.events.ingest_token', 'ingest_test_token');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ingest_test_token',
        ])->postJson('/api/v0.5/career/attribution/events', [
            'eventName' => 'career_unknown_event',
            'payload' => [
                'entry_surface' => 'career_job_index',
                'source_page_type' => 'job_index',
                'route_family' => 'jobs',
                'subject_kind' => 'none',
                'query_mode' => 'non_query',
            ],
        ]);

        $response->assertStatus(422);
    }
}
