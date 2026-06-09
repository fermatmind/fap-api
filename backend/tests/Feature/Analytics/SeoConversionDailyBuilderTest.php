<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\SeoConversionDailyBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SeoConversionDailyBuilderTest extends TestCase
{
    use RefreshDatabase;

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

        foreach (['landing_pv', 'article_to_test_click', 'start_test', 'complete_test', 'view_result'] as $offset => $eventCode) {
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
        $this->assertSame(hash('sha256', $sessionId), (string) $row->session_id_hash);
        $this->assertSame(1, (int) $row->landing_pv_count);
        $this->assertSame(1, (int) $row->article_to_test_click_count);
        $this->assertSame(1, (int) $row->start_test_count);
        $this->assertSame(1, (int) $row->complete_test_count);
        $this->assertSame(1, (int) $row->view_result_count);
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

        $result = app(SeoConversionDailyBuilder::class)->refresh($day, $day, [], false);

        $this->assertSame(0, (int) ($result['upserted_rows'] ?? 0));
        $this->assertSame(1, (int) ($result['skipped_rows'] ?? 0));
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
}
