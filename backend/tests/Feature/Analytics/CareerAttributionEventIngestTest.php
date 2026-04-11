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

    public function test_ingest_endpoint_accepts_transition_preview_events_without_collapsing_them_into_other_recommendation_events(): void
    {
        config()->set('fap.events.ingest_token', 'ingest_test_token');

        $viewResponse = $this->withHeaders([
            'Authorization' => 'Bearer ingest_test_token',
        ])->postJson('/api/v0.5/career/attribution/events', [
            'eventName' => 'career_transition_preview_view',
            'anonymousId' => 'anon_b17_001',
            'path' => '/en/career/recommendations/mbti/intj-a',
            'timestamp' => '2026-04-11T09:00:00+08:00',
            'payload' => [
                'entry_surface' => 'career_recommendation_detail_transition_preview',
                'source_page_type' => 'career_recommendation_detail',
                'target_action' => 'view_transition_preview',
                'landing_path' => '/en/career/recommendations/mbti/intj-a',
                'route_family' => 'recommendation_detail',
                'subject_kind' => 'job_slug',
                'subject_key' => 'registered-nurses',
                'query_mode' => 'non_query',
                'locale' => 'en',
            ],
        ]);

        $clickResponse = $this->withHeaders([
            'Authorization' => 'Bearer ingest_test_token',
        ])->postJson('/api/v0.5/career/attribution/events', [
            'eventName' => 'career_transition_preview_target_click',
            'anonymousId' => 'anon_b17_002',
            'path' => '/en/career/recommendations/mbti/intj-a',
            'timestamp' => '2026-04-11T09:01:00+08:00',
            'payload' => [
                'entry_surface' => 'career_recommendation_detail_transition_preview',
                'source_page_type' => 'career_recommendation_detail',
                'target_action' => 'open_transition_target_job',
                'landing_path' => '/en/career/recommendations/mbti/intj-a',
                'route_family' => 'recommendation_detail',
                'subject_kind' => 'job_slug',
                'subject_key' => 'registered-nurses',
                'query_mode' => 'non_query',
                'locale' => 'en',
            ],
        ]);

        $viewResponse->assertStatus(202)
            ->assertJsonPath('event_code', 'career_transition_preview_view');
        $clickResponse->assertStatus(202)
            ->assertJsonPath('event_code', 'career_transition_preview_target_click');

        $viewRow = DB::table('events')
            ->where('event_code', 'career_transition_preview_view')
            ->where('anon_id', 'anon_b17_001')
            ->first();
        $clickRow = DB::table('events')
            ->where('event_code', 'career_transition_preview_target_click')
            ->where('anon_id', 'anon_b17_002')
            ->first();

        $this->assertNotNull($viewRow);
        $this->assertNotNull($clickRow);
        $this->assertDatabaseMissing('events', [
            'event_code' => 'career_recommendation_result_click',
            'anon_id' => 'anon_b17_001',
        ]);
        $this->assertDatabaseMissing('events', [
            'event_code' => 'career_recommendation_matched_job_click',
            'anon_id' => 'anon_b17_002',
        ]);

        $viewMeta = is_array($viewRow->meta_json ?? null)
            ? $viewRow->meta_json
            : (json_decode((string) ($viewRow->meta_json ?? '{}'), true) ?: []);
        $clickMeta = is_array($clickRow->meta_json ?? null)
            ? $clickRow->meta_json
            : (json_decode((string) ($clickRow->meta_json ?? '{}'), true) ?: []);

        $this->assertSame('career_recommendation_detail_transition_preview', $viewMeta['entry_surface'] ?? null);
        $this->assertSame('view_transition_preview', $viewMeta['target_action'] ?? null);
        $this->assertSame('job_slug', $viewMeta['subject_kind'] ?? null);
        $this->assertSame('registered-nurses', $viewMeta['subject_key'] ?? null);

        $this->assertSame('career_recommendation_detail_transition_preview', $clickMeta['entry_surface'] ?? null);
        $this->assertSame('open_transition_target_job', $clickMeta['target_action'] ?? null);
        $this->assertSame('job_slug', $clickMeta['subject_kind'] ?? null);
        $this->assertSame('registered-nurses', $clickMeta['subject_key'] ?? null);
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
