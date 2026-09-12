<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SEO\SitemapCache;
use App\Services\SeoCouncil\Platform12\Platform12DailyMissionSet;
use App\Services\SeoCouncil\Platform12\Platform12ProductionEvidenceReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoPlatform12A08ProductionEvidenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.seo_intel', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config()->set('seo_intel.connection', 'seo_intel');
        config()->set('cache.default', 'array');
        config()->set('public_content_observability.probe.cache_store', 'array');
        DB::purge('seo_intel');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        DB::purge('seo_intel');
        parent::tearDown();
    }

    public function test_gsc_reads_latest_scheduled_attempt_including_failure_and_valid_zero(): void
    {
        Schema::connection('seo_intel')->create('seo_gsc_sync_runs', function (Blueprint $table): void {
            $table->id();
            foreach (['trigger_mode', 'status', 'started_at', 'finished_at', 'receipt_json', 'rows_seen', 'failure_code'] as $field) {
                $table->text($field)->nullable();
            }
        });
        $at = CarbonImmutable::now('UTC');
        $table = DB::connection('seo_intel')->table('seo_gsc_sync_runs');
        $table->insert(['trigger_mode' => 'scheduled', 'status' => 'success', 'started_at' => $at->subMinutes(3),
            'finished_at' => $at->subMinutes(2), 'receipt_json' => json_encode(['schema_version' => 'seo.gsc_refresh_receipt.v2',
                'trigger_mode' => 'scheduled', 'unmapped_rows' => 0, 'rows_seen' => 0, 'data_max_date' => $at->subDay()->toDateString(),
                'quality_gate' => ['status' => 'pass']])]);
        $result = $this->read('gsc', $at);
        $this->assertSame(0, $result['row_count']);
        $this->assertSame('READY', $result['data_quality_state']);
        $table->insert(['trigger_mode' => 'scheduled', 'status' => 'failed', 'started_at' => $at->subMinute(),
            'finished_at' => $at, 'receipt_json' => null]);
        $capture = app(Platform12ProductionEvidenceReader::class)->capture(Platform12DailyMissionSet::IDS[0]);
        $this->assertSame('failed', $capture['input']['gsc']['scheduled_receipt_status']);
        $this->assertNotContains('gsc_scheduled_receipt', $capture['source_gaps']);
        Http::assertNothingSent();
    }

    public function test_missing_source_is_not_a_zero_or_fabricated_healthy_result(): void
    {
        $capture = app(Platform12ProductionEvidenceReader::class)->capture(Platform12DailyMissionSet::IDS[0]);
        $this->assertNull($capture['input']['gsc']);
        $this->assertSame('UNAVAILABLE', $capture['input']['runtime']['public_api_state']);
        $this->assertContains('public_api_health', $capture['source_gaps']);
        Http::assertNothingSent();
    }

    public function test_d1_uses_current_revision_observations_with_a_fixed_24_to_48_hour_cohort(): void
    {
        Schema::connection('seo_intel')->create('seo_current_decision_cards', function (Blueprint $table): void {
            $table->string('decision_revision_id');
        });
        Schema::connection('seo_intel')->create('seo_decision_cards', function (Blueprint $table): void {
            $table->string('decision_revision_id');
            $table->dateTime('first_observed_at');
            $table->dateTime('last_observed_at');
        });
        $at = CarbonImmutable::now('UTC');
        $this->assertSame(0, $this->read('d1', $at)['candidate_count']);
        foreach ([['one', 30, 2], ['two', 30, 29], ['old', 72, 1]] as [$id, $first, $last]) {
            DB::connection('seo_intel')->table('seo_current_decision_cards')->insert(['decision_revision_id' => $id]);
            DB::connection('seo_intel')->table('seo_decision_cards')->insert(['decision_revision_id' => $id,
                'first_observed_at' => $at->subHours($first), 'last_observed_at' => $at->subHours($last)]);
        }
        $this->assertSame(['availability' => 'AVAILABLE', 'candidate_count' => 2, 'observed_count' => 1], $this->read('d1', $at));
    }

    public function test_sitemap_reads_existing_cache_without_warming_and_blocks_entities(): void
    {
        app(SitemapCache::class)->put('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.test/en</loc></url></urlset>', 'etag', 'test-identity');
        $this->assertSame(['availability' => 'AVAILABLE', 'observation_count' => 1], $this->read('sitemap'));
        Cache::put(SitemapCache::XML_CACHE_KEY, '<!DOCTYPE urlset [<!ENTITY x SYSTEM "file:///unreadable">]><urlset>&x;</urlset>');
        $this->expectException(\RuntimeException::class);
        $this->read('sitemap');
    }

    public function test_empty_minimized_evidence_is_counted_but_missing_hmac_capability_is_not_passed(): void
    {
        (require database_path('migrations/seo_intel/2026_08_29_010000_create_seo_evidence_tables.php'))->up();
        config()->set('seo_agent_evidence.query_hmac_key', null);
        $safety = $this->read('evidenceSafety', CarbonImmutable::now('UTC'));
        $this->assertSame(0, $safety['scanned_count']);
        $this->assertSame('ABSENT', $safety['query_security']['pii_state']);
        $this->assertSame('UNAVAILABLE', $safety['query_security']['hmac_state']);
        Http::assertNothingSent();
    }

    public function test_unexpected_private_probe_response_is_not_invented_as_a_private_leak(): void
    {
        $this->assertSame(['tested_count' => 10, 'rejected_count' => 9], $this->read('negativeSetCounts',
            ['http_probe_count' => 10, 'accepted_http_probe_count' => 9, 'exposure_count' => 1, 'unobserved_count' => 0]));
        $this->expectExceptionMessage('LIVE_NEGATIVE_SET_UNEXPECTED_RESPONSE');
        $this->read('negativeSetCounts',
            ['http_probe_count' => 10, 'accepted_http_probe_count' => 9, 'exposure_count' => 0, 'unobserved_count' => 0]);
    }

    public function test_m2_maps_current_canonical_count_without_changing_denominators_or_audit(): void
    {
        $snapshot = [
            'source_state' => ['authority' => 'available', 'url_truth' => 'available', 'entity_bindings' => 'available'],
            'counts' => ['effective_public' => 10, 'url_truth_valid' => 8],
            'difference_classification' => ['canonical_host_or_path_error' => 2,
                'current_canonical_host_or_path_error' => 0, 'private_or_noindex_included' => 0],
        ];
        foreach ([0 => 'RECONCILIATION_INCOMPLETE_HOLD', 1 => 'WRONG_CANONICAL_HOLD'] as $count => $expected) {
            $snapshot['difference_classification']['current_canonical_host_or_path_error'] = $count;
            $mapped = $this->read('urlTruthEvidence', $snapshot);
            $this->assertSame($count, $mapped['wrong_canonical_count']);
            $this->assertSame(8, $mapped['current_url_truth_count']);
            $this->assertSame(0, $mapped['false_noindex_count']);
            $receipt = $this->evaluateM2($snapshot, $mapped);
            $this->assertSame($expected, $receipt['state']);
            $this->assertSame(10, $receipt['authority_reconciliation']['fixed_denominator']);
            $this->assertSame(2, $snapshot['difference_classification']['canonical_host_or_path_error']);
            $this->assertFalse($receipt['execution_allowed']);
            $this->assertSame(['url_truth' => false, 'canonical' => false, 'robots' => false, 'authority' => false], $receipt['writes']);
        }
        Http::assertNothingSent();
    }

    public function test_m2_missing_or_unavailable_current_count_never_falls_back_to_history_or_zero(): void
    {
        $base = ['source_state' => ['authority' => 'available', 'url_truth' => 'available', 'entity_bindings' => 'available'],
            'counts' => ['effective_public' => 10, 'url_truth_valid' => 8],
            'difference_classification' => ['canonical_host_or_path_error' => 2, 'private_or_noindex_included' => 0]];
        foreach (['missing', 'null', 'authority', 'entity_bindings', 'url_truth'] as $unavailable) {
            $snapshot = $base;
            if ($unavailable !== 'missing') {
                $snapshot['difference_classification']['current_canonical_host_or_path_error'] = null;
            }
            if (array_key_exists($unavailable, $snapshot['source_state'])) {
                $snapshot['source_state'][$unavailable] = 'measurement_hold';
            }
            $mapped = $this->read('urlTruthEvidence', $snapshot);
            $this->assertNull($mapped['wrong_canonical_count'] ?? null);
            $expected = in_array($unavailable, ['missing', 'null'], true) ? 'INPUT_HOLD' : 'URL_TRUTH_UNAVAILABLE_HOLD';
            $this->assertSame($expected, $this->evaluateM2($snapshot, $mapped)['state'], $unavailable);
        }
    }

    private function evaluateM2(array $snapshot, array $mapped): array
    {
        return app(\App\Services\SeoCouncil\Platform12\Evaluation\Platform12DailyUrlTruthEvaluator::class)->evaluate([
            'evaluated_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'authority' => ['availability' => $snapshot['source_state']['authority'] === 'available' ? 'AVAILABLE' : 'UNAVAILABLE',
                'revision_hash' => str_repeat('a', 64), 'current_public_count' => $snapshot['counts']['effective_public']],
            'url_truth' => $mapped,
            'clustering' => ['availability' => 'AVAILABLE', 'issue_count' => 0, 'clustered_issue_count' => 0,
                'dedupe_candidate_count' => 0, 'dedupe_unique_count' => 0],
            'd1_observation' => ['availability' => 'AVAILABLE', 'candidate_count' => 0, 'observed_count' => 0],
            'runtime_observation' => ['availability' => 'AVAILABLE', 'observation_count' => 10],
            'sitemap_observation' => ['availability' => 'AVAILABLE', 'observation_count' => 10],
        ]);
    }

    private function read(string $method, mixed ...$arguments): array
    {
        return (new \ReflectionMethod(Platform12ProductionEvidenceReader::class, $method))
            ->invoke(app(Platform12ProductionEvidenceReader::class), ...$arguments);
    }
}
