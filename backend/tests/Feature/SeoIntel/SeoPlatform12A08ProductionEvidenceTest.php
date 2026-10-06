<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SEO\SitemapCache;
use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleFactory;
use App\Services\SeoAgentEvidence\Competitive\CompetitivePolicyObservationSet;
use App\Services\SeoAgentEvidence\Competitive\CompetitiveSourcePolicyRegistry;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoCouncil\Competitive\CompetitiveCloseoutBuilder;
use App\Services\SeoCouncil\Platform12\Evaluation\Platform12DailySecurityDriftEvaluator;
use App\Services\SeoCouncil\Platform12\Platform12DailyMissionSet;
use App\Services\SeoCouncil\Platform12\Platform12EvidenceSelection;
use App\Services\SeoCouncil\Platform12\Platform12ProductionEvidenceReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\SeoIntel\Concerns\BuildsCompetitiveEvidenceBundle;
use Tests\TestCase;

final class SeoPlatform12A08ProductionEvidenceTest extends TestCase
{
    use BuildsCompetitiveEvidenceBundle;

    private const CURRENT_SHA = 'aabbccddaabbccddaabbccddaabbccddaabbccdd';

    private array $lifecycleFiles = [];

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
        foreach ($this->lifecycleFiles as $path) {
            @unlink($path);
        }
        CarbonImmutable::setTestNow();
        DB::purge('seo_intel');
        parent::tearDown();
    }

    #[DataProvider('runtimeSnapshotMissions')]
    public function test_runtime_observation_is_loaded_before_the_frozen_capture_time(string $mission, string $source): void
    {
        Schema::connection('seo_intel')->create('seo_runtime_probe_receipts', function (Blueprint $table): void {
            $table->string('trigger_mode');
            $table->text('scheduled_for');
            $table->text('receipt_json');
        });
        $at = CarbonImmutable::parse('2026-10-06T04:21:22Z');
        CarbonImmutable::setTestNow($at);
        foreach ([0, 10, 20] as $minutes) {
            $receipt = [
                'schema_version' => \App\Services\SeoIntel\Runtime\ScheduledRuntimeProbeReceiptService::SCHEMA_VERSION,
                'trigger_mode' => 'scheduled', 'status' => 'success',
                'scheduled_for' => $at->subMinutes($minutes)->startOfMinute()->toAtomString(),
                'completed_at' => $at->subMinutes($minutes)->addSeconds(5)->toAtomString(),
                'production_calibration' => ['private_negative_set' => ['checked' => true,
                    'http_probe_count' => 22, 'accepted_http_probe_count' => 22,
                    'exposure_count' => 0, 'unobserved_count' => 0]],
            ];
            $receipt['receipt_hash'] = \App\Services\SeoIntel\Runtime\ScheduledRuntimeProbeReceiptService::contentHash($receipt);
            DB::connection('seo_intel')->table('seo_runtime_probe_receipts')->insert([
                'trigger_mode' => 'scheduled', 'scheduled_for' => $receipt['scheduled_for'],
                'receipt_json' => json_encode($receipt, JSON_THROW_ON_ERROR),
            ]);
        }
        // A natural receipt completes while the query is in progress.
        DB::listen(function ($query) use ($at): void {
            if (str_contains($query->sql, 'seo_runtime_probe_receipts') && str_starts_with($query->sql, 'select')) {
                CarbonImmutable::setTestNow($at->addSeconds(10));
            }
        });
        $capture = app(Platform12ProductionEvidenceReader::class)->capture($mission);
        $this->assertSame($at->addSeconds(10)->format('Y-m-d\TH:i:s\Z'), $capture['captured_at']);
        $this->assertNotContains($source, $capture['source_gaps']);
        $this->assertContains($source, array_column($capture['sources'], 'id'));
        Http::assertNothingSent();
    }

    public static function runtimeSnapshotMissions(): array
    {
        return [
            'M1' => [Platform12DailyMissionSet::IDS[0], 'scheduled_runtime_probe'],
            'M2' => [Platform12DailyMissionSet::IDS[1], 'scheduled_runtime_probe'],
            'M3' => [Platform12DailyMissionSet::IDS[2], 'private_route_negative_set'],
        ];
    }

    public function test_runtime_snapshot_expiring_during_read_is_not_fresh_at_the_frozen_time(): void
    {
        $at = CarbonImmutable::parse('2026-10-06T04:21:22Z');
        $receipt = ['schema_version' => \App\Services\SeoIntel\Runtime\ScheduledRuntimeProbeReceiptService::SCHEMA_VERSION,
            'trigger_mode' => 'scheduled', 'completed_at' => $at->subMinutes(20)->subSecond()->toAtomString(), 'status' => 'success'];
        $receipt['receipt_hash'] = \App\Services\SeoIntel\Runtime\ScheduledRuntimeProbeReceiptService::contentHash($receipt);
        // It was fresh at query start, then crossed the existing 20-minute
        // boundary before the envelope's frozen capture time was fixed.
        $window = ['state' => 'complete', 'fresh' => true, 'receipts' => [$receipt]];
        $result = $this->read('runtimeWindow', $at, $window);
        $this->assertFalse($result['fresh']);
        $this->assertSame('MEASUREMENT_HOLD', $result['state']);
    }

    public function test_runtime_snapshot_still_rejects_corruption_future_completion_and_missing_receipts(): void
    {
        $at = CarbonImmutable::parse('2026-10-06T04:21:22Z');
        $receipt = ['schema_version' => \App\Services\SeoIntel\Runtime\ScheduledRuntimeProbeReceiptService::SCHEMA_VERSION,
            'trigger_mode' => 'scheduled', 'completed_at' => $at->toAtomString(), 'status' => 'MEASUREMENT_HOLD'];
        $receipt['receipt_hash'] = \App\Services\SeoIntel\Runtime\ScheduledRuntimeProbeReceiptService::contentHash($receipt);
        $window = ['state' => 'MEASUREMENT_HOLD', 'receipts' => [$receipt]];
        $this->assertSame('MEASUREMENT_HOLD', $this->read('runtimeWindow', $at, $window)['state']);
        foreach (['corrupt', 'future', 'missing'] as $case) {
            $invalid = $window;
            if ($case === 'corrupt') {
                $invalid['receipts'][0]['receipt_hash'] = str_repeat('0', 64);
            } elseif ($case === 'future') {
                $invalid['receipts'][0]['completed_at'] = $at->addSecond()->toAtomString();
                $invalid['receipts'][0]['receipt_hash'] = \App\Services\SeoIntel\Runtime\ScheduledRuntimeProbeReceiptService::contentHash($invalid['receipts'][0]);
            } else {
                $invalid['receipts'] = [];
            }
            try {
                $this->read('runtimeWindow', $at, $invalid);
                $this->fail('An invalid runtime snapshot must remain HOLD: '.$case);
            } catch (\RuntimeException $error) {
                $this->assertSame($case === 'missing' ? 'RUNTIME_OBSERVATION_MISSING' : 'RUNTIME_RECEIPT_INVALID', $error->getMessage());
            }
        }
    }

    public function test_gsc_reads_latest_scheduled_attempt_including_failure_and_valid_zero(): void
    {
        Schema::connection('seo_intel')->create('seo_gsc_sync_runs', function (Blueprint $table): void {
            $table->id();
            foreach (['trigger_mode', 'status', 'created_at', 'started_at_utc', 'started_at', 'finished_at', 'receipt_json', 'rows_seen', 'failure_code'] as $field) {
                $table->text($field)->nullable();
            }
        });
        $at = CarbonImmutable::now('UTC');
        $table = DB::connection('seo_intel')->table('seo_gsc_sync_runs');
        $table->insert(['trigger_mode' => 'scheduled', 'status' => 'success', 'started_at' => $at->addHours(8), 'created_at' => $at->subMinutes(3),
            'finished_at' => $at->subMinutes(2), 'receipt_json' => json_encode(['schema_version' => 'seo.gsc_refresh_receipt.v2',
                'trigger_mode' => 'scheduled', 'unmapped_rows' => 0, 'rows_seen' => 0, 'data_max_date' => $at->subDay()->toDateString(),
                'quality_gate' => ['status' => 'pass']])]);
        $result = $this->read('gsc', $at);
        $this->assertSame(0, $result['row_count']);
        $this->assertSame('READY', $result['data_quality_state']);
        $table->insert(['trigger_mode' => 'scheduled', 'status' => 'failed', 'started_at' => $at->subMinute(), 'created_at' => $at->subMinute(),
            'finished_at' => $at, 'receipt_json' => null]);
        $capture = app(Platform12ProductionEvidenceReader::class)->capture(Platform12DailyMissionSet::IDS[0]);
        $this->assertSame('failed', $capture['input']['gsc']['scheduled_receipt_status']);
        $this->assertNotContains('gsc_scheduled_receipt', $capture['source_gaps']);
        $table->insert(['trigger_mode' => 'scheduled', 'status' => 'running',
            'started_at_utc' => $at->subSeconds(30)->format('Y-m-d H:i:s.u'),
            'started_at' => $at->addHours(8), 'created_at' => $at->subSeconds(30)]);
        $running = $this->read('gsc', $at);
        $this->assertSame('running', $running['scheduled_receipt_status']);
        $this->assertSame($at->subSeconds(30)->toAtomString(), $running['observed_at']);
        $table->insert(['trigger_mode' => 'scheduled', 'status' => 'running', 'started_at' => $at]);
        $unknown = app(Platform12ProductionEvidenceReader::class)->capture(Platform12DailyMissionSet::IDS[0]);
        $this->assertNull($unknown['input']['gsc']);
        $this->assertContains('gsc_scheduled_receipt', $unknown['source_gaps']);
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

    public function test_m3_verified_replacement_retires_only_same_cohort_history_and_preserves_immutable_rows(): void
    {
        CarbonImmutable::setTestNow('2026-10-06T00:00:00Z');
        $this->createLifecycleTable();
        $old = $this->lifecycleBundle(str_repeat('b', 40), now('UTC')->subDays(35));
        $current = $this->lifecycleBundle(self::CURRENT_SHA, now('UTC')->subHour());
        $staging = $this->lifecycleBundle(str_repeat('c', 40), now('UTC')->subDays(35), 'staging');
        foreach ([$old, $current, $staging] as $bundle) {
            $this->storeLifecycleBundle($bundle);
        }
        $this->writeLifecycleReceipt($current);
        $before = DB::connection('seo_intel')->table('seo_evidence_bundles')->get()->toJson();
        $selection = app(Platform12EvidenceSelection::class)->read(CarbonImmutable::now('UTC'), self::CURRENT_SHA);
        $this->assertSame('VALID', $selection['freshness']['current_reference_state']);
        $this->assertSame(3, $selection['freshness']['stored_count']);
        $this->assertSame(1, $selection['freshness']['superseded_count']);
        $this->assertSame(1, $selection['freshness']['expired_count']);
        $this->assertSame($old['bundle_hash'], $selection['freshness']['superseded_bundle_hashes']);
        $this->assertSame(Platform12EvidenceSelection::EXIT_REASON, $selection['freshness']['historical_exit_reason']);
        $this->assertSame($before, DB::connection('seo_intel')->table('seo_evidence_bundles')->get()->toJson());
        $this->assertFalse((bool) config('seo_agent_evidence.bundle_write_enabled'));
        $this->assertFalse((bool) config('seo_agent_evidence.agent_external_egress'));

        // Unrelated expired evidence still holds; the proven replaced cohort alone passes.
        $this->assertSame('HOLD', $this->evaluateLifecycle($selection['freshness'])['state']);
        DB::connection('seo_intel')->table('seo_evidence_bundles')->where('bundle_hash', $staging['bundle_hash'])->delete();
        $selection = app(Platform12EvidenceSelection::class)->read(CarbonImmutable::now('UTC'), self::CURRENT_SHA);
        $receipt = $this->evaluateLifecycle($selection['freshness']);
        $this->assertSame('READY', $receipt['state']);
        $this->assertSame($old['bundle_hash'], $receipt['evidence_freshness']['superseded_bundle_hashes']);
        $frozenEvidence = ['input' => ['evaluated_at' => '2026-10-06T00:00:00Z', 'evidence_freshness' => $selection['freshness']],
            'sources' => [], 'source_gaps' => [], 'captured_at' => '2026-10-06T00:00:00Z', 'expires_at' => '2026-10-06T00:10:00Z'];
        $this->assertTrue((new \ReflectionMethod(\App\Services\SeoCouncil\Platform12\Platform12FrozenMission::class, 'safeEvidence'))->invoke(null, $frozenEvidence));
        $frozenEvidence['input']['evidence_freshness']['superseded_bundle_hashes'] = '4111111111111111';
        $this->assertFalse((new \ReflectionMethod(\App\Services\SeoCouncil\Platform12\Platform12FrozenMission::class, 'safeEvidence'))->invoke(null, $frozenEvidence));
        $safety = (new \ReflectionMethod(Platform12ProductionEvidenceReader::class, 'evidenceSafety'))
            ->invoke(app(Platform12ProductionEvidenceReader::class), CarbonImmutable::now('UTC'), $selection['rows']);
        $this->assertSame(1, $safety['scanned_count']);
        $this->assertSame('ABSENT', $safety['query_security']['pii_state']);
    }

    #[DataProvider('invalidLifecycleReferences')]
    public function test_m3_missing_stale_or_corrupt_reference_cannot_retire_history(string $failure): void
    {
        CarbonImmutable::setTestNow('2026-10-06T00:00:00Z');
        $this->createLifecycleTable();
        $old = $this->lifecycleBundle(str_repeat('b', 40), now('UTC')->subDays(35));
        $at = match ($failure) {
            'expired_bundle' => now('UTC')->subDays(31),
            'future_bundle' => now('UTC')->addHour(),
            default => now('UTC')->subHour(),
        };
        $current = $this->lifecycleBundle(self::CURRENT_SHA, $at);
        $this->storeLifecycleBundle($old);
        if ($failure !== 'missing_bundle') {
            $this->storeLifecycleBundle($current);
        }
        if ($failure !== 'missing_receipt') {
            $this->writeLifecycleReceipt($current, $failure);
        }
        if ($failure === 'row_hash') {
            DB::connection('seo_intel')->table('seo_evidence_bundles')->where('bundle_id', $current['bundle_id'])
                ->update(['bundle_hash' => str_repeat('f', 64)]);
        }
        if ($failure === 'row_expiry') {
            DB::connection('seo_intel')->table('seo_evidence_bundles')->where('bundle_id', $current['bundle_id'])
                ->update(['expires_at' => now('UTC')->addYear()]);
        }
        $selection = app(Platform12EvidenceSelection::class)->read(CarbonImmutable::now('UTC'), self::CURRENT_SHA);
        $this->assertSame('UNAVAILABLE', $selection['freshness']['current_reference_state']);
        $this->assertSame(0, $selection['freshness']['superseded_count']);
        $this->assertContains($old['bundle_hash'], $selection['rows']->pluck('bundle_hash')->all());
        $receipt = $this->evaluateLifecycle($selection['freshness']);
        $this->assertSame('HOLD', $receipt['state']);
        $this->assertContains('CURRENT_EVIDENCE_REFERENCE_HOLD', $receipt['reason_codes']);
    }

    public static function invalidLifecycleReferences(): array
    {
        return array_map(static fn (string $failure): array => [$failure], [
            'missing_receipt', 'missing_bundle', 'receipt_hash', 'receipt_hold', 'receipt_sha',
            'receipt_bundle_hash', 'receipt_registry', 'row_hash', 'row_expiry', 'expired_bundle', 'future_bundle',
        ]);
    }

    public function test_m3_corrupt_history_stays_active_and_missing_reference_does_not_hide_denial(): void
    {
        CarbonImmutable::setTestNow('2026-10-06T00:00:00Z');
        $this->createLifecycleTable();
        $old = $this->lifecycleBundle(str_repeat('b', 40), now('UTC')->subDays(35));
        $current = $this->lifecycleBundle(self::CURRENT_SHA, now('UTC')->subHour());
        $this->storeLifecycleBundle($old);
        $this->storeLifecycleBundle($current);
        $this->writeLifecycleReceipt($current);
        DB::connection('seo_intel')->table('seo_evidence_bundles')->where('bundle_id', $old['bundle_id'])
            ->update(['bundle_json' => '{broken']);
        $selection = app(Platform12EvidenceSelection::class)->read(CarbonImmutable::now('UTC'), self::CURRENT_SHA);
        $this->assertSame(0, $selection['freshness']['superseded_count']);
        $this->assertSame(2, $selection['rows']->count());
        $safety = (new \ReflectionMethod(Platform12ProductionEvidenceReader::class, 'evidenceSafety'))
            ->invoke(app(Platform12ProductionEvidenceReader::class), CarbonImmutable::now('UTC'), $selection['rows']);
        $this->assertSame('UNKNOWN', $safety['query_security']['pii_state']);
        $empty = ['total_count' => 0, 'fresh_count' => 0, 'expired_count' => 0,
            'stored_count' => 0, 'superseded_count' => 0, 'current_reference_state' => 'UNAVAILABLE',
            'historical_exit_reason' => 'NONE', 'superseded_bundle_hashes' => '', 'selection_hash' => str_repeat('d', 64)];
        $this->assertSame('HOLD', $this->evaluateLifecycle($empty)['state']);
        $this->assertSame('DENY', $this->evaluateLifecycle($empty, true)['state']);
    }

    private function createLifecycleTable(): void
    {
        (require database_path('migrations/seo_intel/2026_08_29_010000_create_seo_evidence_tables.php'))->up();
    }

    private function storeLifecycleBundle(array $bundle): void
    {
        DB::connection('seo_intel')->table('seo_evidence_bundles')->insert([
            'bundle_id' => $bundle['bundle_id'], 'bundle_version' => $bundle['bundle_version'],
            'bundle_hash' => $bundle['bundle_hash'], 'mission_id' => $bundle['mission_id'],
            'page_family' => $bundle['page_family'], 'locale' => $bundle['locale'], 'source_type' => $bundle['source_type'],
            'expires_at' => CarbonImmutable::parse($bundle['expires_at'])->format('Y-m-d H:i:s'),
            'bundle_json' => json_encode($bundle, JSON_THROW_ON_ERROR), 'created_at' => $bundle['captured_at'],
        ]);
    }

    private function lifecycleBundle(string $sha, \Carbon\CarbonInterface $at, string $environment = 'production'): array
    {
        $input = $this->competitiveBundleInput($environment, $sha);
        $input['captured_at'] = $at->format('Y-m-d\TH:i:s\Z');
        $input['authority_revision'] = hash_file('sha256', base_path('content_assets/personality_public/current/manifest.json'));
        $registry = app(CompetitiveSourcePolicyRegistry::class);
        $policies = $registry->policies();
        $payload = $input['payload'];
        foreach ($payload['projections'] as &$projection) {
            $projection['capture']['captured_at'] = $input['captured_at'];
            $projection['source_policy_ref']['policy_hash'] = $policies[$projection['source_id']]['policy_hash'];
            $projection['source_policy_ref']['expires_at'] = $at->copy()->addDays(30)->format('Y-m-d\TH:i:s\Z');
            $projection = $this->sealCompetitiveValue($projection, 'projection_hash');
        }
        unset($projection);
        $payload['source_policy_set_hash'] = $registry->snapshot(Platform12EvidenceSelection::COHORT)['source_policy_set_hash'];
        foreach ($payload['policy_observations'] as &$observation) {
            $observation['policy_hash'] = $policies[$observation['source_id']]['policy_hash'];
            $observation['reviewed_at'] = $at->copy()->min(now('UTC'))->format('Y-m-d\TH:i:s\Z');
            $observation['valid_until'] = $at->copy()->min(now('UTC'))->addDays(30)->format('Y-m-d\TH:i:s\Z');
            $observation = app(CompetitivePolicyObservationSet::class)->seal($observation);
        }
        unset($observation);
        $payload['policy_observation_set_hash'] = app(CompetitivePolicyObservationSet::class)->hash($payload['policy_observations']);
        $finding = $payload['competitive_output']['findings'][0];
        $finding['evidence_refs'] = array_column($payload['projections'], 'projection_hash');
        $payload['competitive_output']['findings'][0] = $this->sealCompetitiveValue($finding, 'finding_hash');
        $payload['competitive_output'] = $this->sealCompetitiveValue($payload['competitive_output'], 'output_hash');
        $input['payload'] = $payload;

        return app(SeoEvidenceBundleFactory::class)->create($input);
    }

    private function writeLifecycleReceipt(array $bundle, string $failure = ''): void
    {
        $mode = ['source_state' => 'available', 'freshness_state' => 'fresh', 'bundle_verification' => 'valid',
            'context_status' => 'READY', 'hold_reason' => 'NONE', 'bundle_hash' => str_repeat('e', 64)];
        $ingestion = ['status' => 'READY', 'hold_reason' => 'NONE', 'bundle_verification' => 'valid',
            'competitive_output' => $bundle['payload']['competitive_output'],
            'policy_snapshot' => app(CompetitiveSourcePolicyRegistry::class)->snapshot(Platform12EvidenceSelection::COHORT),
            'measurement' => ['status' => 'READY', 'hold_reason' => 'NONE',
                'measurement_bundle_set_hash' => $bundle['payload']['measurement_bundle_set_hash'],
                'search_measurement' => array_replace($mode, ['bundle_hash' => $bundle['lineage_refs'][0]]),
                'cro_measurement' => array_replace($mode, ['bundle_hash' => $bundle['lineage_refs'][1]]), 'bundles' => []],
            'dependency_ingestion' => ['external_reads' => 12, 'bundle_hash' => $bundle['bundle_hash'],
                'release_ref' => $bundle['payload']['release_ref'],
                'policy_observations' => $bundle['payload']['policy_observations'],
                'policy_observation_set_hash' => $bundle['payload']['policy_observation_set_hash'], 'policy_revalidation_count' => 0]];
        $ordinarySources = config('seo_agent_evidence.allowed_sources', []);
        config()->set('seo_agent_evidence.allowed_sources', app(CompetitiveSourcePolicyRegistry::class)->policies());
        $builder = app(CompetitiveCloseoutBuilder::class);
        $receipt = $builder->finalizeRuntime($builder->buildRuntime($ingestion, self::CURRENT_SHA, 'production'), self::CURRENT_SHA);
        $this->assertTrue($builder->verify($receipt, self::CURRENT_SHA));
        $this->assertSame('CLOSED', $receipt['closeout_state']);
        if ($failure === 'receipt_hash') {
            $receipt['receipt_hash'] = str_repeat('f', 64);
        }
        if ($failure === 'receipt_hold') {
            $receipt = $builder->buildRuntime($ingestion, self::CURRENT_SHA, 'production');
        }
        if ($failure === 'receipt_sha') {
            $receipt['production_sha'] = str_repeat('c', 40);
        }
        if ($failure === 'receipt_bundle_hash') {
            $receipt['dependency_ingestion']['bundle_hash'] = str_repeat('f', 64);
        }
        if ($failure === 'receipt_registry') {
            $receipt['cohort_hash'] = str_repeat('f', 64);
            $receipt['receipt_hash'] = app(SeoEvidenceCanonicalHasher::class)->hashWithout($receipt, 'receipt_hash');
        }
        config()->set('seo_agent_evidence.allowed_sources', $ordinarySources);
        $path = storage_path('app/release-receipts/seo-competitive-evidence/'.self::CURRENT_SHA.'.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $this->assertFileDoesNotExist($path);
        $this->lifecycleFiles[] = $path;
        file_put_contents($path, json_encode($receipt, JSON_THROW_ON_ERROR));
    }

    private function evaluateLifecycle(array $freshness, bool $pii = false): array
    {
        return app(Platform12DailySecurityDriftEvaluator::class)->evaluate([
            'evaluated_at' => '2026-10-06T00:00:00Z',
            'private_routes' => ['tested_count' => 4, 'rejected_count' => 4],
            'query_security' => ['hmac_state' => 'VALID', 'key_version_state' => 'CURRENT', 'pii_state' => $pii ? 'PRESENT' : 'ABSENT'],
            'drift' => array_fill_keys(['role', 'binding', 'policy', 'tool', 'schema', 'prompt'], 'MATCH'),
            'evidence_freshness' => $freshness, 'injection' => ['prompt_state' => 'PASS', 'tool_metadata_state' => 'PASS'],
            'tools' => ['requested_count' => 0, 'authorized_count' => 0],
            'posture' => ['retention_state' => 'COMPLIANT', 'egress_state' => 'COMPLIANT'],
        ]);
    }
}
