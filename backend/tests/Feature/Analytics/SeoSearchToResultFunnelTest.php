<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\SeoIntel\SearchToResultFunnelReadModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoSearchToResultFunnelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'seo_intel.connection' => 'seo_intel',
        ]);

        DB::purge('seo_intel');
        $this->createTables();
    }

    protected function tearDown(): void
    {
        DB::purge('seo_intel');

        parent::tearDown();
    }

    #[Test]
    public function it_joins_page_aggregates_to_canonical_product_event_names(): void
    {
        $url = 'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types';
        $hash = hash('sha256', $url);
        $this->insertUrlTruth($hash, $url, 'test_detail', true, 'backend_public_surface', 'zh-CN');
        $this->insertGsc($hash, 600, 24);
        $this->insertGsc($hash, 400, 16);
        $this->insertFunnel($hash, 30, 24, 20);
        $this->insertFunnel($hash, 20, 16, 15);

        $report = app(SearchToResultFunnelReadModel::class)->report(
            '2026-07-20',
            '2026-07-20',
        );

        $this->assertTrue($report['ok'] ?? false);
        $this->assertSame(1, $report['row_count'] ?? null);
        $this->assertSame($hash, data_get($report, 'rows.0.canonical_url_hash'));
        $this->assertSame('google', data_get($report, 'rows.0.source_engine'));
        $this->assertSame('live_gsc_api', data_get($report, 'rows.0.data_origin'));
        $this->assertSame(['live_gsc_api'], data_get($report, 'rows.0.data_origins'));
        $this->assertSame('test_detail', data_get($report, 'rows.0.page_family'));
        $this->assertSame('zh-CN', data_get($report, 'rows.0.locale'));
        $this->assertTrue((bool) data_get($report, 'rows.0.indexed_url'));
        $this->assertTrue((bool) data_get($report, 'rows.0.indexed_url_has_valid_product_start'));
        $this->assertSame(1000, data_get($report, 'rows.0.metrics.impressions'));
        $this->assertSame(40, data_get($report, 'rows.0.metrics.clicks'));
        $this->assertSame(50, data_get($report, 'rows.0.metrics.start_test_count'));
        $this->assertSame(40, data_get($report, 'rows.0.metrics.complete_test_count'));
        $this->assertSame(35, data_get($report, 'rows.0.metrics.view_result_count'));
        $this->assertSame(50, data_get($report, 'rows.0.metrics.valid_product_start_count'));
        $this->assertSame(50.0, data_get($report, 'rows.0.metrics.start_test_per_1000_impressions'));
        $this->assertSame(800000, data_get($report, 'rows.0.metrics.start_to_complete_rate_ppm'));
        $this->assertSame(875000, data_get($report, 'rows.0.metrics.complete_to_view_result_rate_ppm'));
        $this->assertSame(
            'seo_event_funnel_daily.start_attempt_count',
            data_get($report, 'product_event_mapping.start_test'),
        );
        $this->assertSame(
            'seo_event_funnel_daily.submit_attempt_count',
            data_get($report, 'product_event_mapping.complete_test'),
        );
        $this->assertSame(
            'seo_event_funnel_daily.view_result_count',
            data_get($report, 'product_event_mapping.view_result'),
        );
        $this->assertFalse((bool) data_get($report, 'negative_guarantees.gsc_purchase_truth', true));
        $this->assertFalse((bool) data_get($report, 'negative_guarantees.gsc_revenue_truth', true));
        $this->assertStringNotContainsString($url, json_encode($report, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_applies_date_page_family_and_source_engine_filters(): void
    {
        $testHash = hash('sha256', 'https://fermatmind.com/en/tests/mbti');
        $articleHash = hash('sha256', 'https://fermatmind.com/en/articles/mbti');
        $this->insertUrlTruth($testHash, 'https://fermatmind.com/en/tests/mbti', 'test_detail', true);
        $this->insertUrlTruth($articleHash, 'https://fermatmind.com/en/articles/mbti', 'article', true);
        $this->insertGsc($testHash, 100, 10, '2026-07-20', 'google');
        $this->insertGsc($articleHash, 200, 20, '2026-07-20', 'google');
        $this->insertGsc($testHash, 300, 30, '2026-07-21', 'google');
        $this->insertGsc($testHash, 400, 40, '2026-07-20', 'bing');
        $this->insertFunnel($testHash, 8, 6, 5, '2026-07-20', 'google');

        $report = app(SearchToResultFunnelReadModel::class)->report(
            '2026-07-20',
            '2026-07-20',
            'test_detail',
            'google',
        );

        $this->assertTrue($report['ok'] ?? false);
        $this->assertSame([
            'page_family' => 'test_detail',
            'source_engine' => 'google',
        ], $report['filters'] ?? null);
        $this->assertSame(1, $report['row_count'] ?? null);
        $this->assertSame($testHash, data_get($report, 'rows.0.canonical_url_hash'));
        $this->assertSame(100, data_get($report, 'totals.impressions'));
        $this->assertSame(8, data_get($report, 'totals.valid_product_start_count'));
    }

    #[Test]
    public function it_fails_closed_when_gsc_contains_a_forbidden_data_origin(): void
    {
        $url = 'https://fermatmind.com/en/tests/mbti';
        $hash = hash('sha256', $url);
        $this->insertUrlTruth($hash, $url, 'test_detail', true);
        $this->insertGsc($hash, 300, 12, dataOrigin: 'live_gsc_api');
        $this->insertGsc($hash, 200, 8, dataOrigin: 'fixture');
        $this->insertFunnel($hash, 10, 8, 7);

        $report = app(SearchToResultFunnelReadModel::class)->report(
            '2026-07-20',
            '2026-07-20',
        );

        $this->assertFalse($report['ok'] ?? true);
        $this->assertSame('blocked', $report['status'] ?? null);
        $this->assertContains('gsc_data_origin_not_allowed', $report['issues'] ?? []);
        $this->assertSame([], $report['rows'] ?? null);
        $this->assertSame(0, data_get($report, 'totals.impressions'));
        $this->assertSame(0, data_get($report, 'totals.start_test_count'));
    }

    #[Test]
    public function it_excludes_private_url_families_and_non_product_traffic(): void
    {
        $privateUrls = [
            'https://fermatmind.com/en/results/raw-result-id',
            'https://fermatmind.com/en/attempts/raw-attempt-id',
            'https://fermatmind.com/en/orders/raw-order-id',
            'https://fermatmind.com/en/recovery/raw-recovery-token',
            'https://fermatmind.com/en/payments/raw-payment-id',
            'https://fermatmind.com/en/tests/mbti/raw-take-id/take',
            'https://fermatmind.com/en/tests/mbti/checkout/raw-checkout-id',
            'https://fermatmind.com/en/tests/mbti/report/raw-report-id',
            'https://fermatmind.com/en/tests/mbti/share/raw-share-id',
            'https://fermatmind.com/en/account/raw-account-id',
        ];

        foreach ($privateUrls as $index => $url) {
            $hash = hash('sha256', $url);
            $this->insertUrlTruth($hash, $url, 'private_flow', true);
            $this->insertGsc($hash, 100 + $index, 1, canonicalUrl: $url);
            $this->insertFunnel($hash, 99, 88, 77);
        }

        $publicUrl = 'https://fermatmind.com/en/tests/big-five-personality-test-ocean-model';
        $publicHash = hash('sha256', $publicUrl);
        $this->insertUrlTruth($publicHash, $publicUrl, 'test_detail', true);
        $this->insertGsc($publicHash, 500, 25);
        $this->insertFunnel($publicHash, 9, 8, 7, trafficQuality: 'qa');
        $this->insertFunnel($publicHash, 5, 4, 3, environment: 'prod');

        $report = app(SearchToResultFunnelReadModel::class)->report(
            '2026-07-20',
            '2026-07-20',
        );
        $encoded = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->assertSame(1, $report['row_count'] ?? null);
        $this->assertSame(10, $report['private_url_exclusion_count'] ?? null);
        $this->assertSame(1, $report['non_product_traffic_exclusion_count'] ?? null);
        $this->assertSame($publicHash, data_get($report, 'rows.0.canonical_url_hash'));
        $this->assertSame(5, data_get($report, 'rows.0.metrics.start_test_count'));
        $this->assertSame(5, data_get($report, 'rows.0.metrics.valid_product_start_count'));

        foreach ([
            'raw-result-id',
            'raw-attempt-id',
            'raw-order-id',
            'raw-recovery-token',
            'raw-payment-id',
            'raw-take-id',
            'raw-checkout-id',
            'raw-report-id',
            'raw-share-id',
            'raw-account-id',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    #[Test]
    public function drifted_canonical_urls_never_validate_a_product_start(): void
    {
        $urls = [
            'http://fermatmind.com/en/tests/http-owner',
            'https://example.com/en/tests/off-domain-owner',
            'https://fermatmind.com/en/tests/query-owner?attempt=private',
            'https://user:secret@fermatmind.com/en/tests/credential-owner',
        ];

        foreach ($urls as $index => $url) {
            $hash = hash('sha256', $url);
            $this->insertUrlTruth($hash, $url, 'test_detail', true);
            $this->insertGsc($hash, 100 + $index, 10 + $index);
            $this->insertFunnel($hash, 4, 3, 2);
        }

        $report = app(SearchToResultFunnelReadModel::class)->report(
            '2026-07-20',
            '2026-07-20',
        );

        $this->assertTrue($report['ok'] ?? false);
        $this->assertSame(4, $report['row_count'] ?? null);
        $this->assertSame(16, data_get($report, 'totals.start_test_count'));
        $this->assertSame(0, data_get($report, 'totals.valid_product_start_count'));
        foreach ($report['rows'] ?? [] as $row) {
            $this->assertFalse((bool) ($row['indexed_url'] ?? true));
            $this->assertFalse((bool) ($row['indexed_url_has_valid_product_start'] ?? true));
        }
    }

    #[Test]
    public function empty_gsc_windows_and_empty_page_family_filters_fail_closed(): void
    {
        $emptyWindow = app(SearchToResultFunnelReadModel::class)->report(
            '2026-07-20',
            '2026-07-20',
        );

        $this->assertFalse($emptyWindow['ok'] ?? true);
        $this->assertSame('blocked', $emptyWindow['status'] ?? null);
        $this->assertContains('gsc_rows_insufficient', $emptyWindow['issues'] ?? []);
        $this->assertSame(0, data_get($emptyWindow, 'totals.impressions'));

        $url = 'https://fermatmind.com/en/tests/mbti';
        $hash = hash('sha256', $url);
        $this->insertUrlTruth($hash, $url, 'test_detail', true);
        $this->insertGsc($hash, 100, 10);

        $emptyFamily = app(SearchToResultFunnelReadModel::class)->report(
            '2026-07-20',
            '2026-07-20',
            'article',
        );

        $this->assertFalse($emptyFamily['ok'] ?? true);
        $this->assertSame('blocked', $emptyFamily['status'] ?? null);
        $this->assertContains('gsc_rows_insufficient', $emptyFamily['issues'] ?? []);
        $this->assertSame([], $emptyFamily['rows'] ?? null);
    }

    #[Test]
    public function non_indexable_or_non_authoritative_urls_never_count_as_valid_product_starts(): void
    {
        $noindexHash = hash('sha256', 'https://fermatmind.com/en/articles/noindex-owner');
        $frontendHash = hash('sha256', 'https://fermatmind.com/en/articles/frontend-owner');
        $this->insertUrlTruth(
            $noindexHash,
            'https://fermatmind.com/en/articles/noindex-owner',
            'article',
            false,
        );
        $this->insertUrlTruth(
            $frontendHash,
            'https://fermatmind.com/en/articles/frontend-owner',
            'article',
            true,
            'frontend_local',
        );
        $this->insertGsc($noindexHash, 100, 2);
        $this->insertGsc($frontendHash, 100, 4);
        $this->insertFunnel($noindexHash, 4, 3, 2);
        $this->insertFunnel($frontendHash, 6, 5, 4);

        $report = app(SearchToResultFunnelReadModel::class)->report(
            '2026-07-20',
            '2026-07-20',
        );

        $this->assertSame(2, $report['row_count'] ?? null);
        $this->assertSame(10, data_get($report, 'totals.start_test_count'));
        $this->assertSame(0, data_get($report, 'totals.valid_product_start_count'));
        $this->assertFalse((bool) data_get($report, 'rows.0.indexed_url'));
        $this->assertFalse((bool) data_get($report, 'rows.1.indexed_url'));
    }

    #[Test]
    public function missing_url_truth_and_non_final_gsc_rows_fail_closed(): void
    {
        $url = 'https://fermatmind.com/en/tests/private-hash-unknown';
        $hash = hash('sha256', $url);
        $this->insertUrlTruth(
            $hash,
            'https://fermatmind.com/en/tests/mismatched-public-owner',
            'test_detail',
            true,
        );
        $this->insertGsc($hash, 100, 10);
        $this->insertFunnel($hash, 7, 6, 5);

        $missingTruth = app(SearchToResultFunnelReadModel::class)->report(
            '2026-07-20',
            '2026-07-20',
        );

        $this->assertFalse($missingTruth['ok'] ?? true);
        $this->assertSame('blocked', $missingTruth['status'] ?? null);
        $this->assertContains('url_truth_missing_for_gsc_hash', $missingTruth['issues'] ?? []);
        $this->assertSame([], $missingTruth['rows'] ?? null);
        $this->assertStringNotContainsString($hash, json_encode($missingTruth, JSON_THROW_ON_ERROR));

        $this->insertUrlTruth($hash, $url, 'test_detail', true);
        $provisionalUrl = 'https://fermatmind.com/en/tests/provisional-owner';
        $provisionalHash = hash('sha256', $provisionalUrl);
        $this->insertUrlTruth($provisionalHash, $provisionalUrl, 'test_detail', true);
        $this->insertGsc($provisionalHash, 200, 20, dataState: 'provisional');

        $nonFinal = app(SearchToResultFunnelReadModel::class)->report(
            '2026-07-20',
            '2026-07-20',
        );

        $this->assertFalse($nonFinal['ok'] ?? true);
        $this->assertSame('blocked', $nonFinal['status'] ?? null);
        $this->assertContains('gsc_data_state_not_final', $nonFinal['issues'] ?? []);
        $this->assertSame([], $nonFinal['rows'] ?? null);
        $this->assertSame(0, data_get($nonFinal, 'totals.impressions'));
    }

    #[Test]
    public function command_is_read_only_and_invalid_windows_fail_closed(): void
    {
        $url = 'https://fermatmind.com/en/tests/mbti';
        $hash = hash('sha256', $url);
        $this->insertUrlTruth($hash, $url, 'test_detail', true);
        $this->insertGsc($hash, 100, 10);
        $this->insertFunnel($hash, 7, 6, 5);
        $before = $this->tableCounts();

        $exitCode = Artisan::call('seo-intel:search-to-result-funnel-report', [
            '--from' => '2026-07-20',
            '--to' => '2026-07-20',
            '--json' => true,
        ]);
        $output = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($output['read_only'] ?? false);
        $this->assertSame(7, data_get($output, 'totals.valid_product_start_count'));
        $this->assertSame($before, $this->tableCounts());

        $blockedExitCode = Artisan::call('seo-intel:search-to-result-funnel-report', [
            '--from' => '2026-07-21',
            '--to' => '2026-07-20',
            '--json' => true,
        ]);
        $blocked = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $blockedExitCode);
        $this->assertSame('blocked', $blocked['status'] ?? null);
        $this->assertContains('date_window_invalid', $blocked['issues'] ?? []);
        $this->assertSame($before, $this->tableCounts());
    }

    #[Test]
    public function missing_schema_fails_closed_without_fallback_or_writes(): void
    {
        Schema::connection('seo_intel')->drop('seo_urls');

        $report = app(SearchToResultFunnelReadModel::class)->report(
            '2026-07-20',
            '2026-07-20',
        );

        $this->assertFalse($report['ok'] ?? true);
        $this->assertSame('blocked', $report['status'] ?? null);
        $this->assertContains('seo_urls_missing', $report['issues'] ?? []);
        $this->assertSame([], $report['rows'] ?? null);
        $this->assertTrue($report['read_only'] ?? false);
    }

    private function createTables(): void
    {
        $schema = Schema::connection('seo_intel');
        $schema->create('seo_gsc_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->string('source_engine', 64);
            $table->string('data_state', 32)->default('final');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->json('metadata_json')->nullable();
        });
        $schema->create('seo_event_funnel_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->string('source_engine', 64);
            $table->string('traffic_quality', 32)->nullable();
            $table->string('environment', 32)->nullable();
            $table->unsignedInteger('start_attempt_count')->default(0);
            $table->unsignedInteger('submit_attempt_count')->default(0);
            $table->unsignedInteger('view_result_count')->default(0);
        });
        $schema->create('seo_urls', function (Blueprint $table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('source_authority', 64);
            $table->string('indexability_state', 64);
            $table->boolean('is_private_flow')->default(false);
        });
    }

    private function insertUrlTruth(
        string $hash,
        string $url,
        string $pageFamily,
        bool $indexable,
        string $sourceAuthority = 'backend_cms',
        string $locale = 'en',
    ): void {
        DB::connection('seo_intel')->table('seo_urls')->insert([
            'canonical_url_hash' => $hash,
            'canonical_url' => $url,
            'locale' => $locale,
            'page_entity_type' => $pageFamily,
            'source_authority' => $sourceAuthority,
            'indexability_state' => $indexable ? 'indexable' : 'noindex',
            'is_private_flow' => false,
        ]);
    }

    private function insertGsc(
        string $hash,
        int $impressions,
        int $clicks,
        string $date = '2026-07-20',
        string $sourceEngine = 'google',
        ?string $canonicalUrl = null,
        string $dataOrigin = 'live_gsc_api',
        string $dataState = 'final',
    ): void {
        DB::connection('seo_intel')->table('seo_gsc_daily')->insert([
            'report_date' => $date,
            'canonical_url_hash' => $hash,
            'canonical_url' => $canonicalUrl,
            'source_engine' => $sourceEngine,
            'data_state' => $dataState,
            'clicks' => $clicks,
            'impressions' => $impressions,
            'metadata_json' => json_encode([
                'data_origin' => $dataOrigin,
                'row_source' => $dataOrigin,
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    private function insertFunnel(
        string $hash,
        int $starts,
        int $completes,
        int $views,
        string $date = '2026-07-20',
        string $sourceEngine = 'google',
        string $trafficQuality = 'production_user',
        string $environment = 'production',
    ): void {
        DB::connection('seo_intel')->table('seo_event_funnel_daily')->insert([
            'report_date' => $date,
            'canonical_url_hash' => $hash,
            'source_engine' => $sourceEngine,
            'traffic_quality' => $trafficQuality,
            'environment' => $environment,
            'start_attempt_count' => $starts,
            'submit_attempt_count' => $completes,
            'view_result_count' => $views,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        return [
            'seo_gsc_daily' => DB::connection('seo_intel')->table('seo_gsc_daily')->count(),
            'seo_event_funnel_daily' => DB::connection('seo_intel')->table('seo_event_funnel_daily')->count(),
            'seo_urls' => DB::connection('seo_intel')->table('seo_urls')->count(),
        ];
    }
}
