<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\CareerAttributionDailyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerAttributionDailyBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_builds_career_rows_grouped_by_source_page_type_surface_subject_and_readiness_class(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $orgId = 21;
        $day = now()->startOfDay()->setDate(2026, 4, 10)->setTime(9, 0);

        $this->insertCareerEvent($orgId, 'career_job_index_result_click', $day, [
            'entry_surface' => 'career_job_index',
            'source_page_type' => 'job_index',
            'route_family' => 'jobs',
            'subject_kind' => 'job_slug',
            'subject_key' => 'data-scientists',
            'query_mode' => 'non_query',
        ], 'anon_a', 'session_a');
        $this->insertCareerEvent($orgId, 'career_job_index_result_click', $day->copy()->addMinute(), [
            'entry_surface' => 'career_job_index',
            'source_page_type' => 'job_index',
            'route_family' => 'jobs',
            'subject_kind' => 'job_slug',
            'subject_key' => 'data-scientists',
            'query_mode' => 'non_query',
        ], 'anon_a', 'session_a');
        $this->insertCareerEvent($orgId, 'career_job_search_result_click', $day->copy()->addMinutes(2), [
            'entry_surface' => 'career_job_search',
            'source_page_type' => 'job_index',
            'route_family' => 'jobs_search',
            'subject_kind' => 'job_slug',
            'subject_key' => 'software-developers',
            'query_mode' => 'query',
        ], 'anon_b', 'session_b');
        $this->insertCareerEvent($orgId, 'career_family_hub_view', $day->copy()->addMinutes(2), [
            'entry_surface' => 'career_family_hub',
            'source_page_type' => 'career_family_hub',
            'route_family' => 'family_hub',
            'subject_kind' => 'family_slug',
            'subject_key' => 'data-and-ai',
            'query_mode' => 'non_query',
        ], 'anon_family_view', 'session_family_view');
        $this->insertCareerEvent($orgId, 'career_family_hub_child_click', $day->copy()->addMinutes(3), [
            'entry_surface' => 'career_family_hub',
            'source_page_type' => 'career_family_hub',
            'route_family' => 'family_hub',
            'subject_kind' => 'job_slug',
            'subject_key' => 'registered-nurses',
            'query_mode' => 'non_query',
        ], 'anon_family_click', 'session_family_click');
        $this->insertCareerEvent($orgId, 'career_recommendation_matched_job_click', $day->copy()->addMinutes(4), [
            'entry_surface' => 'career_recommendation_detail',
            'source_page_type' => 'recommendation_detail',
            'route_family' => 'recommendation_detail',
            'subject_kind' => 'job_slug',
            'subject_key' => 'marketing-managers',
            'query_mode' => 'non_query',
        ], 'anon_c', 'session_c');
        $this->insertCareerEvent($orgId, 'career_recommendation_result_click', $day->copy()->addMinutes(5), [
            'entry_surface' => 'career_recommendation_index',
            'source_page_type' => 'recommendation_index',
            'route_family' => 'recommendations',
            'subject_kind' => 'recommendation_type',
            'subject_key' => 'intj',
            'query_mode' => 'non_query',
        ], 'anon_d', 'session_d');
        $this->insertCareerEvent($orgId, 'career_transition_preview_view', $day->copy()->addMinutes(6), [
            'entry_surface' => 'career_recommendation_detail_transition_preview',
            'source_page_type' => 'career_recommendation_detail',
            'route_family' => 'recommendation_detail',
            'subject_kind' => 'job_slug',
            'subject_key' => 'registered-nurses',
            'query_mode' => 'non_query',
        ], 'anon_e', 'session_e');
        $this->insertCareerEvent($orgId, 'career_transition_preview_target_click', $day->copy()->addMinutes(7), [
            'entry_surface' => 'career_recommendation_detail_transition_preview',
            'source_page_type' => 'career_recommendation_detail',
            'route_family' => 'recommendation_detail',
            'subject_kind' => 'job_slug',
            'subject_key' => 'registered-nurses',
            'query_mode' => 'non_query',
        ], 'anon_f', 'session_f');
        $this->insertCareerEvent($orgId, 'career_blocked_surface_exposed', $day->copy()->addMinutes(8), [
            'entry_surface' => 'career_blocked_surface',
            'source_page_type' => 'job_detail',
            'route_family' => 'job_detail',
            'subject_kind' => 'job_slug',
            'subject_key' => 'data-scientists',
            'query_mode' => 'non_query',
        ], 'anon_g', 'session_g');
        $this->insertCareerEvent($orgId, 'career_blocked_surface_exposed', $day->copy()->addMinutes(9), [
            'entry_surface' => 'career_blocked_surface',
            'source_page_type' => 'recommendation_detail',
            'route_family' => 'job_detail',
            'subject_kind' => 'job_slug',
            'subject_key' => 'data-scientists',
            'query_mode' => 'non_query',
        ], 'anon_h', 'session_h');
        $this->insertCareerEvent($orgId, 'career_alias_resolution_submit', $day->copy()->addMinutes(10), [
            'entry_surface' => 'career_alias_disambiguation',
            'source_page_type' => 'career_alias_disambiguation',
            'route_family' => 'alias_resolution',
            'subject_kind' => 'none',
            'subject_key' => '',
            'query_mode' => 'query',
        ], 'anon_alias_submit', 'session_alias_submit');
        $this->insertCareerEvent($orgId, 'career_alias_resolution_target_click', $day->copy()->addMinutes(11), [
            'entry_surface' => 'career_alias_disambiguation',
            'source_page_type' => 'career_alias_disambiguation',
            'route_family' => 'alias_resolution',
            'subject_kind' => 'job_slug',
            'subject_key' => 'data-scientists',
            'query_mode' => 'query',
        ], 'anon_alias_target_job', 'session_alias_target_job');
        $this->insertCareerEvent($orgId, 'career_alias_resolution_target_click', $day->copy()->addMinutes(12), [
            'entry_surface' => 'career_alias_disambiguation',
            'source_page_type' => 'career_alias_disambiguation',
            'route_family' => 'alias_resolution',
            'subject_kind' => 'family_slug',
            'subject_key' => 'computer-and-information-technology',
            'query_mode' => 'query',
        ], 'anon_alias_target_family', 'session_alias_target_family');
        $this->insertCareerEvent($orgId, 'career_alias_resolution_no_result', $day->copy()->addMinutes(13), [
            'entry_surface' => 'career_alias_disambiguation',
            'source_page_type' => 'career_alias_disambiguation',
            'route_family' => 'alias_resolution',
            'subject_kind' => 'none',
            'subject_key' => '',
            'query_mode' => 'query',
        ], 'anon_alias_no_result', 'session_alias_no_result');

        $result = app(CareerAttributionDailyBuilder::class)->refresh($day, $day, [$orgId], false);

        $this->assertSame(14, (int) ($result['upserted_rows'] ?? 0));

        $readyRow = DB::table('analytics_career_attribution_daily')
            ->where('day', $day->toDateString())
            ->where('org_id', $orgId)
            ->where('event_name', 'career_job_index_result_click')
            ->where('subject_key', 'data-scientists')
            ->first();

        $this->assertNotNull($readyRow);
        $this->assertSame('publish_ready', $readyRow->readiness_class);
        $this->assertSame('job_index', $readyRow->source_page_type);
        $this->assertSame(2, (int) $readyRow->event_count);
        $this->assertSame(1, (int) $readyRow->unique_anon_count);
        $this->assertSame(1, (int) $readyRow->unique_session_count);

        $blockedRow = DB::table('analytics_career_attribution_daily')
            ->where('event_name', 'career_job_search_result_click')
            ->where('subject_key', 'software-developers')
            ->first();

        $this->assertNotNull($blockedRow);
        $this->assertSame('blocked_override_eligible', $blockedRow->readiness_class);
        $this->assertSame('job_index', $blockedRow->source_page_type);
        $this->assertSame('query', $blockedRow->query_mode);
        $this->assertSame('jobs_search', $blockedRow->route_family);

        $familyHubViewRow = DB::table('analytics_career_attribution_daily')
            ->where('event_name', 'career_family_hub_view')
            ->where('subject_key', 'data-and-ai')
            ->first();

        $this->assertNotNull($familyHubViewRow);
        $this->assertSame('career_family_hub', $familyHubViewRow->surface);
        $this->assertSame('family_hub', $familyHubViewRow->source_page_type);
        $this->assertSame('family_hub', $familyHubViewRow->route_family);
        $this->assertSame('family_slug', $familyHubViewRow->subject_kind);
        $this->assertSame('unknown', $familyHubViewRow->readiness_class);

        $familyHubChildClickRow = DB::table('analytics_career_attribution_daily')
            ->where('event_name', 'career_family_hub_child_click')
            ->where('subject_key', 'registered-nurses')
            ->first();

        $this->assertNotNull($familyHubChildClickRow);
        $this->assertSame('career_family_hub', $familyHubChildClickRow->surface);
        $this->assertSame('family_hub', $familyHubChildClickRow->source_page_type);
        $this->assertSame('family_hub', $familyHubChildClickRow->route_family);
        $this->assertSame('job_slug', $familyHubChildClickRow->subject_kind);
        $this->assertSame('publish_ready', $familyHubChildClickRow->readiness_class);

        $matchedJobRow = DB::table('analytics_career_attribution_daily')
            ->where('event_name', 'career_recommendation_matched_job_click')
            ->where('subject_key', 'marketing-managers')
            ->first();

        $this->assertNotNull($matchedJobRow);
        $this->assertSame('blocked_not_safely_remediable', $matchedJobRow->readiness_class);
        $this->assertSame('recommendation_detail', $matchedJobRow->source_page_type);
        $this->assertSame('recommendation_detail', $matchedJobRow->route_family);

        $recommendationRow = DB::table('analytics_career_attribution_daily')
            ->where('event_name', 'career_recommendation_result_click')
            ->where('subject_key', 'intj')
            ->first();

        $this->assertNotNull($recommendationRow);
        $this->assertSame('recommendation_type', $recommendationRow->subject_kind);
        $this->assertSame('recommendation_index', $recommendationRow->source_page_type);
        $this->assertSame('unknown', $recommendationRow->readiness_class);

        $transitionPreviewViewRow = DB::table('analytics_career_attribution_daily')
            ->where('event_name', 'career_transition_preview_view')
            ->where('subject_key', 'registered-nurses')
            ->first();

        $this->assertNotNull($transitionPreviewViewRow);
        $this->assertSame('career_recommendation_detail_transition_preview', $transitionPreviewViewRow->surface);
        $this->assertSame('recommendation_detail', $transitionPreviewViewRow->source_page_type);
        $this->assertSame('recommendation_detail', $transitionPreviewViewRow->route_family);
        $this->assertSame('job_slug', $transitionPreviewViewRow->subject_kind);
        $this->assertSame('publish_ready', $transitionPreviewViewRow->readiness_class);

        $transitionPreviewClickRow = DB::table('analytics_career_attribution_daily')
            ->where('event_name', 'career_transition_preview_target_click')
            ->where('subject_key', 'registered-nurses')
            ->first();

        $this->assertNotNull($transitionPreviewClickRow);
        $this->assertSame('career_recommendation_detail_transition_preview', $transitionPreviewClickRow->surface);
        $this->assertSame('recommendation_detail', $transitionPreviewClickRow->source_page_type);
        $this->assertSame('recommendation_detail', $transitionPreviewClickRow->route_family);
        $this->assertSame('job_slug', $transitionPreviewClickRow->subject_kind);
        $this->assertSame('publish_ready', $transitionPreviewClickRow->readiness_class);

        $jobBlockedRow = DB::table('analytics_career_attribution_daily')
            ->where('event_name', 'career_blocked_surface_exposed')
            ->where('subject_key', 'data-scientists')
            ->where('source_page_type', 'job_detail')
            ->first();

        $recommendationBlockedRow = DB::table('analytics_career_attribution_daily')
            ->where('event_name', 'career_blocked_surface_exposed')
            ->where('subject_key', 'data-scientists')
            ->where('source_page_type', 'recommendation_detail')
            ->first();

        $this->assertNotNull($jobBlockedRow);
        $this->assertNotNull($recommendationBlockedRow);
        $this->assertSame(1, (int) $jobBlockedRow->event_count);
        $this->assertSame(1, (int) $recommendationBlockedRow->event_count);

        $aliasSubmitRow = DB::table('analytics_career_attribution_daily')
            ->where('event_name', 'career_alias_resolution_submit')
            ->first();

        $aliasJobClickRow = DB::table('analytics_career_attribution_daily')
            ->where('event_name', 'career_alias_resolution_target_click')
            ->where('subject_kind', 'job_slug')
            ->where('subject_key', 'data-scientists')
            ->first();

        $aliasFamilyClickRow = DB::table('analytics_career_attribution_daily')
            ->where('event_name', 'career_alias_resolution_target_click')
            ->where('subject_kind', 'family_slug')
            ->where('subject_key', 'computer-and-information-technology')
            ->first();

        $aliasNoResultRow = DB::table('analytics_career_attribution_daily')
            ->where('event_name', 'career_alias_resolution_no_result')
            ->first();

        $this->assertNotNull($aliasSubmitRow);
        $this->assertNotNull($aliasJobClickRow);
        $this->assertNotNull($aliasFamilyClickRow);
        $this->assertNotNull($aliasNoResultRow);

        $this->assertSame('alias_disambiguation', $aliasSubmitRow->source_page_type);
        $this->assertSame('alias_resolution', $aliasSubmitRow->route_family);
        $this->assertSame('unknown', $aliasSubmitRow->readiness_class);
        $this->assertSame('query', $aliasSubmitRow->query_mode);

        $this->assertSame('alias_disambiguation', $aliasJobClickRow->source_page_type);
        $this->assertSame('alias_resolution', $aliasJobClickRow->route_family);
        $this->assertSame('publish_ready', $aliasJobClickRow->readiness_class);
        $this->assertSame('query', $aliasJobClickRow->query_mode);

        $this->assertSame('alias_disambiguation', $aliasFamilyClickRow->source_page_type);
        $this->assertSame('alias_resolution', $aliasFamilyClickRow->route_family);
        $this->assertSame('unknown', $aliasFamilyClickRow->readiness_class);
        $this->assertSame('query', $aliasFamilyClickRow->query_mode);

        $this->assertSame('alias_disambiguation', $aliasNoResultRow->source_page_type);
        $this->assertSame('alias_resolution', $aliasNoResultRow->route_family);
        $this->assertSame('unknown', $aliasNoResultRow->readiness_class);
        $this->assertSame('query', $aliasNoResultRow->query_mode);
    }

    private function materializeCurrentFirstWaveFixture(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_readiness_summary_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--repair-safe-partials' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function insertCareerEvent(
        int $orgId,
        string $eventCode,
        \DateTimeInterface $occurredAt,
        array $meta,
        string $anonId,
        string $sessionId,
    ): void {
        $row = [
            'id' => (string) Str::uuid(),
            'event_code' => $eventCode,
            'event_name' => $eventCode,
            'org_id' => $orgId,
            'user_id' => null,
            'anon_id' => $anonId,
            'session_id' => $sessionId,
            'request_id' => 'req_'.substr(str_replace('-', '', (string) Str::uuid()), 0, 12),
            'attempt_id' => null,
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
            'scale_code' => 'CAREER',
            'channel' => 'web',
            'locale' => 'zh',
        ];

        if (Schema::hasColumn('events', 'scale_code_v2')) {
            $row['scale_code_v2'] = 'CAREER';
        }

        DB::table('events')->insert($row);
    }
}
