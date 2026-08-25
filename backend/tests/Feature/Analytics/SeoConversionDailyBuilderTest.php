<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\SeoConversionDailyBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsFunnelAnalyticsScenario;
use Tests\TestCase;

final class SeoConversionDailyBuilderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsFunnelAnalyticsScenario;

    public function test_build_uses_the_asia_shanghai_utc_half_open_window(): void
    {
        $payload = [
            'url' => '/en/tests/holland-career-interest-test-riasec',
            'lang' => 'en',
            'page_type' => 'test_detail',
            'scale_id' => 'RIASEC',
            'form_id' => '',
            'session_id' => 'riasec_boundary_session',
        ];
        $this->insertSeoEvent(0, 'landing_pv', CarbonImmutable::parse('2026-07-12 16:00:00', 'UTC'), $payload);
        $this->insertSeoEvent(0, 'landing_pv', CarbonImmutable::parse('2026-08-09 16:00:00', 'UTC'), $payload);

        $result = app(SeoConversionDailyBuilder::class)->build(
            CarbonImmutable::parse('2026-07-13'),
            CarbonImmutable::parse('2026-08-09'),
            [0],
        );

        $this->assertSame('Asia/Shanghai', $result['reporting_timezone'] ?? null);
        $this->assertSame('UTC', $result['storage_timezone'] ?? null);
        $this->assertSame('2026-07-12T16:00:00+00:00', $result['window_utc_start'] ?? null);
        $this->assertSame('2026-08-09T16:00:00+00:00', $result['window_utc_end_exclusive'] ?? null);
        $this->assertCount(1, $result['rows'] ?? []);
        $this->assertSame('2026-07-13', data_get($result, 'rows.0.day'));
        $this->assertSame(1, data_get($result, 'rows.0.landing_pv_count'));
    }

    public function test_refresh_aggregates_canonical_seo_conversion_events_by_safe_dimensions(): void
    {
        $day = CarbonImmutable::parse('2026-06-09 10:00:00');
        $orgId = 17;
        $sessionId = 'seo_sess_1234567890abcdef';
        $basePayload = [
            'url' => 'https://fermatmind.com/en/articles/personality-types?token=secret',
            'lang' => 'en',
            'page_type' => 'article',
            'source_url' => 'https://fermatmind.com/en/articles/personality-types?email=person@example.com',
            'source_article' => 'personality-types',
            'target_test' => '/en/tests/mbti-personality-test-16-personality-types?attempt_id=raw_attempt',
            'scale_id' => 'MBTI',
            'form_id' => 'mbti_144',
            'session_id' => $sessionId,
            'referrer' => 'https://www.google.com/search?q=mbti&email=person@example.com',
        ];

        foreach (['landing_pv', 'article_to_test_click', 'start_test', 'complete_test', 'view_result', 'return_public_content'] as $offset => $eventCode) {
            $this->insertSeoEvent($orgId, $eventCode, $day->addMinutes($offset), $basePayload);
        }

        $result = app(SeoConversionDailyBuilder::class)->refresh($day, $day, [$orgId], false);

        $this->assertSame(1, (int) ($result['upserted_rows'] ?? 0));
        $this->assertSame(0, (int) ($result['skipped_rows'] ?? 0));

        $row = DB::table('analytics_seo_conversion_daily')
            ->where('day', $day->toDateString())
            ->where('org_id', $orgId)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('https://fermatmind.com/en/articles/personality-types', (string) $row->url);
        $this->assertSame('https://fermatmind.com/en/articles/personality-types', (string) $row->source_url);
        $this->assertSame('/en/tests/mbti-personality-test-16-personality-types', (string) $row->target_test);
        $this->assertSame('personality-types', (string) $row->source_article);
        $this->assertSame('MBTI', (string) $row->scale_id);
        $this->assertSame('mbti_144', (string) $row->form_id);
        $this->assertSame('www.google.com', (string) $row->referrer_host);
        $this->assertSame('', (string) $row->session_id_hash);
        $this->assertSame(1, (int) $row->landing_pv_count);
        $this->assertSame(1, (int) $row->article_to_test_click_count);
        $this->assertSame(1, (int) $row->start_test_count);
        $this->assertSame(1, (int) $row->complete_test_count);
        $this->assertSame(1, (int) $row->view_result_count);
        $this->assertSame(1, (int) $row->return_public_content_count);
        $this->assertSame('pass', data_get($result, 'readback_receipt.status'));
        $this->assertFalse(data_get($result, 'readback_receipt.raw_session_or_business_identifiers_exposed'));
        $this->assertStringNotContainsString($sessionId, json_encode((array) $row, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('secret', json_encode((array) $row, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('person@example.com', json_encode((array) $row, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('raw_attempt', json_encode((array) $row, JSON_THROW_ON_ERROR));
    }

    public function test_article_to_test_click_does_not_increment_start_test(): void
    {
        $day = CarbonImmutable::parse('2026-06-09 11:00:00');

        $this->insertSeoEvent(0, 'article_to_test_click', $day, [
            'url' => 'https://fermatmind.com/en/articles/personality-types',
            'lang' => 'en',
            'page_type' => 'article',
            'source_article' => 'personality-types',
            'target_test' => '/en/tests/mbti-personality-test-16-personality-types',
            'scale_id' => 'MBTI',
            'form_id' => 'mbti_144',
            'session_id' => 'seo_sess_click_only',
        ]);

        app(SeoConversionDailyBuilder::class)->refresh($day, $day, [], false);

        $row = DB::table('analytics_seo_conversion_daily')->first();

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->article_to_test_click_count);
        $this->assertSame(0, (int) $row->start_test_count);
    }

    public function test_refresh_aggregates_result_ready_by_backend_resolved_public_article_id(): void
    {
        $day = CarbonImmutable::parse('2026-08-10 11:00:00');
        DB::table('articles')->insert([
            'id' => 53,
            'org_id' => 0,
            'slug' => 'personality-types',
            'locale' => 'en',
            'title' => 'Personality types',
            'content_md' => '# Personality types',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => $day->subDay(),
            'created_at' => $day->subDay(),
            'updated_at' => $day->subDay(),
        ]);
        $this->insertResultReadyEvent($day, '53');

        $result = app(SeoConversionDailyBuilder::class)->refresh($day, $day, [], false);

        $this->assertSame(1, (int) ($result['upserted_rows'] ?? 0));
        $this->assertSame(0, (int) ($result['skipped_rows'] ?? 0));
        $row = DB::table('analytics_seo_conversion_daily')->first();
        $this->assertNotNull($row);
        $this->assertSame(53, (int) $row->source_article_id);
        $this->assertSame('personality-types', (string) $row->source_article);
        $this->assertSame('/en/articles/personality-types', (string) $row->url);
        $this->assertSame(1, (int) $row->result_ready_count);
        $this->assertSame(0, (int) $row->view_result_count);
    }

    public function test_refresh_rejects_unresolved_result_ready_article_identity(): void
    {
        $day = CarbonImmutable::parse('2026-08-10 11:30:00');
        $this->insertResultReadyEvent($day, '999999');

        $result = app(SeoConversionDailyBuilder::class)->refresh($day, $day, [], false);

        $this->assertSame(0, (int) ($result['upserted_rows'] ?? 0));
        $this->assertSame(1, (int) ($result['skipped_rows'] ?? 0));
        $this->assertSame(0, DB::table('analytics_seo_conversion_daily')->count());
    }

    public function test_refresh_backfills_legacy_result_ready_from_locked_attempt_context(): void
    {
        $day = CarbonImmutable::parse('2026-08-09 11:00:00');
        $attemptId = (string) Str::uuid();
        DB::table('articles')->insert([
            'id' => 53,
            'org_id' => 0,
            'slug' => 'personality-types',
            'locale' => 'en',
            'title' => 'Personality types',
            'content_md' => '# Personality types',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => $day->subDay(),
            'created_at' => $day->subDay(),
            'updated_at' => $day->subDay(),
        ]);
        $this->insertAttempt($attemptId, 84, 'en', $day->subMinutes(5), $day->subMinute());
        DB::table('attempts')->where('id', $attemptId)->update([
            'answers_summary_json' => json_encode([
                'meta' => [
                    'source_page_type' => 'article_detail',
                    'content_id' => '53',
                    'source_slug' => 'personality-types',
                    'landing_path' => '/en/articles/personality-types?utm_source=google',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->insertResultReadyEvent($day, null, $attemptId, 84);

        $result = app(SeoConversionDailyBuilder::class)->refresh($day, $day, [84], false);

        $this->assertSame(1, (int) ($result['upserted_rows'] ?? 0));
        $this->assertSame(0, (int) ($result['skipped_rows'] ?? 0));
        $row = DB::table('analytics_seo_conversion_daily')->first();
        $this->assertNotNull($row);
        $this->assertSame(53, (int) $row->source_article_id);
        $this->assertSame(1, (int) $row->result_ready_count);
    }

    public function test_refresh_excludes_smoke_and_codex_probe_seo_conversion_events(): void
    {
        $day = CarbonImmutable::parse('2026-06-09 11:30:00');
        $smokeAttemptId = (string) Str::uuid();

        config([
            'analytics.smoke_attempt_exclusion.attempt_ids' => [$smokeAttemptId],
            'analytics.smoke_attempt_exclusion.anon_id_prefixes' => ['codex_probe_'],
        ]);

        $basePayload = [
            'url' => 'https://fermatmind.com/en/articles/personality-types',
            'lang' => 'en',
            'page_type' => 'article',
            'source_article' => 'personality-types',
            'target_test' => '/en/tests/big-five-personality-test-ocean-model',
            'scale_id' => 'BIG5_OCEAN',
            'form_id' => 'big5_90',
            'session_id' => 'seo_sess_real_user',
        ];

        $this->insertSeoEvent(0, 'landing_pv', $day, $basePayload);
        $this->insertSeoEvent(0, 'start_test', $day->addMinute(), [
            ...$basePayload,
            'session_id' => 'codex_probe_big5_live_result_smoke',
        ]);
        $this->insertSeoEvent(0, 'complete_test', $day->addMinutes(2), [
            ...$basePayload,
            'attempt_id' => $smokeAttemptId,
            'session_id' => 'seo_sess_configured_smoke',
        ]);
        $this->insertSeoEvent(0, 'view_result', $day->addMinutes(3), [
            ...$basePayload,
            'session_id' => 'seo_sess_traffic_quality_smoke',
            'traffic_quality' => 'smoke',
        ]);

        $result = app(SeoConversionDailyBuilder::class)->refresh($day, $day, [], false);

        $this->assertSame(1, (int) ($result['upserted_rows'] ?? 0));

        $row = DB::table('analytics_seo_conversion_daily')->first();

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->landing_pv_count);
        $this->assertSame(0, (int) $row->start_test_count);
        $this->assertSame(0, (int) $row->complete_test_count);
        $this->assertSame(0, (int) $row->view_result_count);
    }

    public function test_refresh_skips_private_urls_before_daily_storage(): void
    {
        $day = CarbonImmutable::parse('2026-06-09 12:00:00');

        $this->insertSeoEvent(0, 'view_result', $day, [
            'url' => 'https://fermatmind.com/en/results/raw-result-id',
            'lang' => 'en',
            'page_type' => 'result',
            'scale_id' => 'MBTI',
            'form_id' => 'mbti_144',
            'session_id' => 'seo_sess_private',
        ]);
        $this->insertSeoEvent(0, 'view_result', $day->addSecond(), [
            'url' => 'https://fermatmind.com/en/%72esults/encoded-result-id',
            'lang' => 'en',
            'page_type' => 'result',
            'scale_id' => 'MBTI',
            'form_id' => 'mbti_144',
            'session_id' => 'seo_sess_encoded_private',
        ]);

        $result = app(SeoConversionDailyBuilder::class)->refresh($day, $day, [], false);

        $this->assertSame(0, (int) ($result['upserted_rows'] ?? 0));
        $this->assertSame(2, (int) ($result['skipped_rows'] ?? 0));
        $this->assertSame(0, DB::table('analytics_seo_conversion_daily')->count());
    }

    public function test_refresh_command_supports_dry_run_without_writing_rows(): void
    {
        $day = CarbonImmutable::parse('2026-06-09 13:00:00');

        $this->insertSeoEvent(0, 'landing_pv', $day, [
            'url' => 'https://fermatmind.com/en/articles/personality-types',
            'lang' => 'en',
            'page_type' => 'article',
            'source_article' => 'personality-types',
            'session_id' => 'seo_sess_dry_run',
        ]);

        $this->artisan('analytics:refresh-seo-conversion-daily', [
            '--from' => $day->toDateString(),
            '--to' => $day->toDateString(),
            '--dry-run' => true,
        ])
            ->expectsOutput('attempted_rows=1')
            ->expectsOutput('upserted_rows=0')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('analytics_seo_conversion_daily')->count());
    }

    /**
     * @param  array<string,mixed>  $seoConversion
     */
    private function insertSeoEvent(int $orgId, string $eventCode, CarbonImmutable $occurredAt, array $seoConversion): void
    {
        $row = [
            'id' => (string) Str::uuid(),
            'event_code' => $eventCode,
            'event_name' => $eventCode,
            'org_id' => $orgId,
            'user_id' => null,
            'anon_id' => 'anon_'.substr(str_replace('-', '', (string) Str::uuid()), 0, 10),
            'session_id' => (string) ($seoConversion['session_id'] ?? 'seo_sess_fallback'),
            'request_id' => 'req_'.substr(str_replace('-', '', (string) Str::uuid()), 0, 12),
            'attempt_id' => null,
            'meta_json' => json_encode([
                'seo_conversion' => $seoConversion,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $occurredAt,
            'share_id' => null,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
            'scale_code' => (string) ($seoConversion['scale_id'] ?? ''),
            'channel' => 'web',
            'region' => 'US',
            'locale' => (string) ($seoConversion['lang'] ?? 'en'),
        ];

        if (Schema::hasColumn('events', 'scale_code_v2')) {
            $row['scale_code_v2'] = (string) ($seoConversion['scale_id'] ?? '');
        }

        if (Schema::hasColumn('events', 'scale_uid')) {
            $row['scale_uid'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        }

        DB::table('events')->insert($row);
    }

    private function insertResultReadyEvent(
        CarbonImmutable $occurredAt,
        ?string $sourceArticleId,
        ?string $attemptId = null,
        int $orgId = 0,
    ): void {
        $meta = [
            'scale_code' => 'MBTI',
            'form_code' => 'mbti_144',
            'locale' => 'en',
        ];
        if ($sourceArticleId !== null) {
            $meta['source_article_id'] = $sourceArticleId;
        }

        $row = [
            'id' => (string) Str::uuid(),
            'event_code' => 'result_ready',
            'event_name' => 'result_ready',
            'org_id' => $orgId,
            'user_id' => null,
            'anon_id' => null,
            'session_id' => null,
            'request_id' => null,
            'attempt_id' => $attemptId ?? (string) Str::uuid(),
            'meta_json' => json_encode($meta, JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt,
            'share_id' => null,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
            'scale_code' => 'MBTI',
            'channel' => 'web',
            'region' => 'US',
            'locale' => 'en',
        ];

        if (Schema::hasColumn('events', 'scale_code_v2')) {
            $row['scale_code_v2'] = 'MBTI';
        }
        if (Schema::hasColumn('events', 'scale_uid')) {
            $row['scale_uid'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        }

        DB::table('events')->insert($row);
    }
}
