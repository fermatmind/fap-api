<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Decision\SeoDecisionCardReadService;
use App\Services\SeoIntel\Decision\SeoDecisionLifecycleMaterializer;
use App\Services\SeoIntel\Decision\SeoOpportunityCardGenerator;
use App\Services\SeoIntel\Decision\SeoOpportunityEvidence;
use App\Services\SeoIntel\Decision\SeoWeeklyDecisionReceiptService;
use App\Services\SeoIntel\Decision\SeoWeeklyDecisionReceiptValidator;
use App\Services\SeoIntel\Decision\SeoWeeklyDecisionSelector;
use App\Services\SeoIntel\Decision\SeoWeeklyPlanningReadService;
use App\Services\SeoIntel\OpsDashboard\SeoOpportunityQueueReadService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class SeoOpportunityCardGeneratorTest extends TestCase
{
    private const SLOT = '2026-09-24T13:45:03Z';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(self::SLOT);
        config([
            'app.git_sha' => str_repeat('a', 40),
            'database.connections.seo_intel' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'seo_intel.connection' => 'seo_intel', 'cache.default' => 'array',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
            'seo_intel.search_channel_queue.allowed_page_entity_types' => ['article'],
            'seo_intel.search_channel_queue.approved_source_authorities' => ['backend_cms'],
            'seo_intel.search_channel_queue.forbidden_page_entity_types' => [],
            'seo_intel.search_channel_queue.forbidden_source_authorities' => [],
        ]);
        DB::purge('seo_intel');
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('seo_intel');
        foreach ([
            '2026_05_17_000100_create_seo_urls_table.php', '2026_05_17_000200_create_seo_url_entities_table.php',
            '2026_05_17_000900_create_seo_gsc_daily_table.php', '2026_08_23_140000_expand_gsc_read_models.php',
            '2026_08_25_020000_expand_url_truth_current_bindings.php', '2026_08_25_050000_expand_gsc_sync_run_receipts.php',
            '2026_08_27_010000_create_seo_change_ledger_tables.php', '2026_08_27_020000_create_seo_decision_card_authority.php',
            '2026_08_27_030000_create_seo_weekly_decision_receipts.php', '2026_08_27_040000_expand_weekly_decision_receipt_capability.php',
        ] as $migration) {
            (require database_path('migrations/seo_intel/'.$migration))->up();
        }
        DB::setDefaultConnection($default);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::disconnect('seo_intel');
        parent::tearDown();
    }

    public static function sizes(): array
    {
        return [[0, 0], [1, 1], [5, 5], [7, 5]];
    }

    public function test_natural_discovery_finishes_before_writer_transaction(): void
    {
        $this->seedPage(1);
        $discoveryLevels = [];
        DB::listen(function (QueryExecuted $event) use (&$discoveryLevels): void {
            if ($event->connectionName === 'seo_intel' && str_contains($event->sql, 'seo_gsc_daily')
                && preg_match('/limit 5000\\b/i', $event->sql) === 1) {
                $discoveryLevels[] = $event->connection->transactionLevel();
            }
        });

        $this->assertSame(1, $this->record()['generation_summary']['created']);
        $this->assertNotEmpty($discoveryLevels);
        // SQLite's GSC snapshot has its own transaction; it must not nest in
        // the natural writer transaction (which would produce level 2).
        $this->assertSame([1], array_values(array_unique($discoveryLevels)));
        $discoveryCount = count($discoveryLevels);
        $this->assertTrue($this->record()['idempotent_replay']);
        $this->assertCount($discoveryCount, $discoveryLevels);
    }

    #[DataProvider('sizes')]
    public function test_empty_authority_to_complete_natural_receipts_is_bounded(int $pages, int $expected): void
    {
        for ($i = 0; $i < $pages; $i++) {
            $this->seedPage($i);
        }
        $r = $this->record();
        $this->assertSame($expected, $r['generation_summary']['created']);
        $this->assertSame(min(3, $expected), $r['decision_count']);
        $this->assertSame($expected, $this->table('seo_change_ledgers')->count());
        $this->assertSame($expected, $this->table('seo_current_decision_cards')->count());
        $this->assertSame($expected + min(3, $expected), $this->table('seo_decision_cards')->count());
        $this->assertSame(1, $this->table('seo_weekly_decision_receipts')->count());
        $this->assertSame(1, $this->table('seo_weekly_decision_capability_receipts')->count());
        $this->assertSame(max(0, $pages - 5), $r['generation_summary']['cap_unprocessed_pages']);
        $this->assertSame(['draft'], $this->table('seo_change_ledgers')->distinct()->pluck('current_state')->all() ?: ['draft']);
        $before = $this->bytes();
        config(['app.git_sha' => str_repeat('b', 40)]);
        $this->assertTrue($this->record()['idempotent_replay']);
        $this->assertSame($before, $this->bytes());
    }

    public function test_query_set_aggregation_merges_signals_and_exact_duplicates_without_zero_ranks(): void
    {
        $this->seedPage(1);
        $row = (array) $this->table('seo_gsc_daily')->first();
        unset($row['id']);
        $this->table('seo_gsc_daily')->insert($row);
        $proof = $this->evidence();
        $this->assertSame(70, $proof['metrics']['impressions']);
        $this->assertEquals(0.0, $proof['metrics']['ctr']);
        $this->assertEquals(10.0, $proof['metrics']['average_position']);
        $this->assertCount(2, $proof['signals']);
        $this->assertSame(1, $this->record()['generation_summary']['created']);
        $this->table('seo_gsc_daily')->where('id', 1)->update(['average_position_milli' => null]);
        $this->assertSame('conflicting_source_dimensions', $this->evidence()['reason']);
        $this->table('seo_gsc_daily')->where('id', '>', 7)->delete();
        $this->assertSame('ranking_coverage_incomplete', $this->evidence()['reason']);
    }

    public static function holds(): array
    {
        return [['truncated'], ['missing_proof'], ['untrusted'], ['too_new'], ['too_old'], ['missing_date'], ['missing_rank'], ['partial'], ['mixed_property']];
    }

    #[DataProvider('holds')]
    public function test_invalid_evidence_cannot_generate_cards(string $case): void
    {
        $this->seedPage(1);
        if (in_array($case, ['truncated', 'missing_proof', 'partial'], true)) {
            $run = $this->table('seo_gsc_sync_runs')->first();
            $p = json_decode($run->receipt_json, true);
            if ($case === 'missing_proof') {
                unset($p['completeness']);
            }
            if ($case === 'truncated') {
                $p['completeness']['truncated'] = true;
            }
            if ($case === 'partial') {
                $p['completeness']['pagination_complete'] = false;
            }
            $this->replaceRun($run->sync_run_uid, $p);
        } elseif ($case === 'untrusted') {
            $this->table('seo_gsc_daily')->update(['metadata_json' => '{"data_origin":"fixture"}']);
        } elseif (in_array($case, ['too_new', 'too_old'], true)) {
            $this->table('seo_gsc_sync_runs')->update(['end_date' => $case === 'too_new' ? '2026-09-24' : '2026-09-01']);
        } elseif ($case === 'missing_date') {
            $this->table('seo_gsc_daily')->where('id', 1)->delete();
        } elseif ($case === 'missing_rank') {
            $this->table('seo_gsc_daily')->where('id', 1)->update(['average_position_milli' => null]);
        } else {
            $uid = $this->seedRun('2026-09-15', '2026-09-21', 'other');
            $this->table('seo_gsc_daily')->where('id', 1)->update(['sync_run_uid' => $uid]);
        }
        $receipt = $this->record();
        $this->assertSame(0, $receipt['generation_summary']['created']);
        $this->assertNotEmpty($receipt['generation_summary']['hold_reasons']);
        $this->assertSame(0, $this->table('seo_change_ledgers')->count());
    }

    public static function invalidPages(): array
    {
        return [['private'], ['noindex'], ['draft'], ['alias'], ['fallback'], ['missing'], ['ambiguous'], ['stale'], ['expired']];
    }

    #[DataProvider('invalidPages')]
    public function test_page_authority_fails_closed(string $case): void
    {
        $hash = $this->seedPage(1);
        if ($case === 'private') {
            $this->table('seo_urls')->update(['is_private_flow' => true]);
        }
        if ($case === 'noindex') {
            $this->table('seo_urls')->update(['indexability_state' => 'noindex']);
        }
        if ($case === 'draft') {
            $this->table('seo_url_entities')->update(['authority_status' => 'draft']);
        }
        if ($case === 'alias') {
            $this->table('seo_urls')->update(['metadata_json' => '{"redirect_only":true}']);
        }
        if ($case === 'fallback') {
            $this->table('seo_urls')->update(['metadata_json' => '{"frontend_fallback":true}']);
        }
        if ($case === 'missing') {
            $this->table('seo_url_entities')->delete();
        }
        if ($case === 'stale') {
            $this->table('seo_url_entities')->update(['authority_revision' => str_repeat('f', 64)]);
        }
        if ($case === 'expired') {
            $this->table('seo_url_entities')->update(['attributes_json' => '{"expires_at":"2026-01-01"}']);
        }
        if ($case === 'ambiguous') {
            $row = (array) $this->table('seo_url_entities')->first();
            unset($row['id']);
            $row['current_binding_key'] = null;
            $this->table('seo_url_entities')->insert($row);
        }
        $this->assertArrayHasKey('reason', (new SeoOpportunityEvidence)->target($hash, 'en', CarbonImmutable::now()));
        $this->assertSame(0, $this->record()['decision_count']);
    }

    public function test_version_briefs_history_and_read_only_ops_are_immutable_and_redacted(): void
    {
        $this->seedPage(1);
        $r = $this->record();
        $before = $this->bytes();
        $read = new SeoWeeklyPlanningReadService;
        $first = $read->additions()['this_week_selected'];
        $this->table('seo_change_ledgers')->update(['source_json' => '{"raw_query":"secret-query","private_url":"/admin/private"}']);
        $this->assertSame($first, $read->additions()['this_week_selected']);
        $card = (new SeoDecisionCardReadService)->snapshot()['items'][0];
        (new SeoDecisionLifecycleMaterializer)->materialize($card, 'in_progress', 'human-start', ['evidence_fresh' => true]);
        $this->assertSame($r['decision_revision_ids'][0], $read->additions()['this_week_selected']['decisions'][0]['decision_revision_id']);
        $this->assertNotSame($r['decision_revision_ids'][0], (new SeoDecisionCardReadService)->snapshot()['items'][0]['decision_revision_id']);
        $stable = $this->bytes();
        $html = view('filament.ops.components.ops-seo-workbench-workspace')->render();
        $this->assertSame($stable, $this->bytes());
        foreach (['/en/articles/page-1', 'impressions', '70', 'seo_gsc_daily', 'ledger', '14', '候选'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $serialized = json_encode($read->additions());
        $this->assertStringNotContainsString('secret-query', $serialized);
        $this->assertStringNotContainsString('query_hashes', $serialized);
        $this->assertStringNotContainsString('/admin/private', $serialized);
        $this->assertFalse($read->additions()['permissions']['publish_allowed']);
        $this->assertNotSame($before, $stable);
    }

    public function test_same_evidence_does_not_extend_expiry_and_later_week_keeps_identity_and_human_state(): void
    {
        $this->seedPage(1);
        $this->record();
        $before = $this->table('seo_decision_cards')->get()->toJson();
        $discovery = (new SeoOpportunityQueueReadService)->planningDiscovery();
        DB::connection('seo_intel')->transaction(fn () => (new SeoOpportunityCardGenerator)->generate(CarbonImmutable::now()->addMinute(), str_repeat('b', 40), fn () => null, $discovery));
        $this->assertSame($before, $this->table('seo_decision_cards')->get()->toJson());
        $old = (new SeoDecisionCardReadService)->snapshot()['items'][0];
        (new SeoDecisionLifecycleMaterializer)->materialize($old, 'in_progress', 'manual-progress', ['evidence_fresh' => true]);
        CarbonImmutable::setTestNow('2026-10-01T13:45:03Z');
        $this->seedData(1, '2026-09-28');
        $r = $this->record();
        $new = (new SeoDecisionCardReadService)->snapshot()['items'][0];
        $this->assertSame($old['decision_card_id'], $new['decision_card_id']);
        $this->assertSame('in_progress', $new['status']);
        $this->assertFalse($new['executable']);
        $this->assertSame(0, $r['decision_count']);
        $this->assertSame(0, $r['generation_summary']['refreshed']);
    }

    public function test_expiry_and_revoked_source_prevent_selection_without_writes(): void
    {
        $this->seedPage(1);
        $this->record();
        $this->table('seo_gsc_sync_runs')->update(['status' => 'failed']);
        $before = $this->bytes();
        $this->assertSame(0, $this->selector()->snapshot()['count']);
        $this->assertSame('source_revoked', (new SeoDecisionCardReadService)->snapshot()['items'][0]['hold_reason']);
        $this->assertSame($before, $this->bytes());
        $this->table('seo_gsc_sync_runs')->update(['status' => 'success']);
        CarbonImmutable::setTestNow('2026-10-15T13:45:00Z');
        $this->assertSame(0, $this->selector()->snapshot()['count']);
    }

    public function test_empty_table_lock_denies_a_competing_invocation_before_any_writes(): void
    {
        $this->seedPage(1);
        $lock = Cache::lock(SeoWeeklyDecisionReceiptService::LOCK_KEY, 120);
        $this->assertTrue($lock->get());
        try {
            $this->record();
            $this->fail('Concurrent call should fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('locked', $e->getMessage());
        } finally {
            $lock->release();
        }
        $this->assertSame(0, $this->table('seo_change_ledgers')->count());
        $this->assertSame(1, $this->record()['generation_summary']['created']);
    }

    public function test_timeout_or_late_readback_failure_rolls_back_the_entire_generation(): void
    {
        $this->seedPage(1);
        $calls = 0;
        try {
            $discovery = (new SeoOpportunityQueueReadService)->planningDiscovery();
            DB::connection('seo_intel')->transaction(fn () => (new SeoOpportunityCardGenerator)->generate(CarbonImmutable::now(), str_repeat('a', 40), function () use (&$calls) {
                if (++$calls > 12) {
                    throw new RuntimeException('deadline');
                }
            }, $discovery));
            $this->fail('Expected deadline.');
        } catch (RuntimeException $e) {
            $this->assertSame('deadline', $e->getMessage());
        }
        $this->assertSame(0, $this->table('seo_change_ledgers')->count());
        DB::connection('seo_intel')->statement("CREATE TRIGGER corrupt_receipt AFTER INSERT ON seo_weekly_decision_capability_receipts BEGIN UPDATE seo_weekly_decision_capability_receipts SET receipt_hash = 'broken'; END");
        try {
            $this->record();
            $this->fail('Expected readback failure.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('readback failed', $e->getMessage());
        }
        foreach (['seo_change_ledgers', 'seo_decision_cards', 'seo_current_decision_cards', 'seo_change_ledger_events', 'seo_weekly_decision_receipts', 'seo_weekly_decision_capability_receipts'] as $t) {
            $this->assertSame(0, $this->table($t)->count(), $t);
        }
    }

    public function test_corrupt_existing_window_is_not_replaced_and_manual_is_no_write(): void
    {
        $this->seedPage(1);
        $this->record();
        $this->table('seo_weekly_decision_capability_receipts')->update(['receipt_hash' => str_repeat('0', 64)]);
        $before = $this->bytes();
        try {
            $this->record();
            $this->fail('Corrupt replay must fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('integrity', $e->getMessage());
        }
        $service = new SeoWeeklyDecisionReceiptService($this->selector());
        $this->assertFalse($service->record('manual')['persisted']);
        $this->assertFalse($service->record('scheduled', CarbonImmutable::now()->addMinute())['persisted']);
        $this->assertSame($before, $this->bytes());
    }

    public function test_two_processes_on_empty_tables_commit_only_one_batch(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Process concurrency requires pcntl.');
        }
        $this->seedPage(1);
        $dir = sys_get_temp_dir().'/seo-natural-concurrency-'.Str::uuid();
        mkdir($dir);
        $file = $dir.'/database.sqlite';
        DB::connection('seo_intel')->statement("VACUUM INTO '".$file."'");
        DB::disconnect('seo_intel');
        config(['database.connections.seo_intel.database' => $file, 'cache.default' => 'file',
            'cache.stores.file.path' => $dir.'/cache', 'cache.stores.file.lock_path' => $dir.'/locks']);
        DB::purge('seo_intel');
        Cache::purge('file');
        $children = [];
        foreach ([0, 1] as $i) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    $result = $this->record();
                    file_put_contents($dir.'/result-'.$i, $result['idempotent_replay'] ? 'replayed' : 'committed');
                } catch (RuntimeException $e) {
                    file_put_contents($dir.'/result-'.$i, str_contains($e->getMessage(), 'locked') ? 'locked' : 'unexpected:'.$e->getMessage());
                }
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        try {
            $results = [file_get_contents($dir.'/result-0'), file_get_contents($dir.'/result-1')];
            $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'committed')), json_encode($results));
            $this->assertSame(1, $this->table('seo_change_ledgers')->count());
            $this->assertSame(1, $this->table('seo_weekly_decision_capability_receipts')->count());
            $this->assertSame(1, $this->table('seo_current_decision_cards')->count());
        } finally {
            DB::disconnect('seo_intel');
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($dir);
        }
    }

    public function test_order_independent_query_set_hash_and_weighted_metrics(): void
    {
        $this->seedPage(1);
        foreach ($this->table('seo_gsc_daily')->get() as $r) {
            $r = (array) $r;
            unset($r['id']);
            $r['query_hash'] = hash('sha256', 'second');
            $r['impressions'] = 30;
            $r['clicks'] = 1;
            $r['average_position_milli'] = 14000;
            $this->table('seo_gsc_daily')->insert($r);
        }
        $candidates = (new SeoOpportunityQueueReadService)->planningDiscovery()['candidates'];
        $reader = new SeoOpportunityEvidence;
        $a = $reader->evaluate($candidates, CarbonImmutable::now(), fn () => null);
        $b = $reader->evaluate(array_reverse($candidates), CarbonImmutable::now(), fn () => null);
        $this->assertSame(SeoWeeklyDecisionReceiptValidator::hash($a), SeoWeeklyDecisionReceiptValidator::hash($b));
        $this->assertSame(280, $a['metrics']['impressions']);
        $this->assertEquals(13, $a['metrics']['average_position']);
        $this->assertEquals(7 / 280, $a['metrics']['ctr']);
        $this->assertSame(2, $a['query_count']);
    }

    public function test_legacy_receipts_replay_without_resigning_or_generating(): void
    {
        $this->record();
        foreach (['seo_weekly_decision_receipts' => 'seo.weekly_decision_selection_receipt.v2', 'seo_weekly_decision_capability_receipts' => 'seo.weekly_decision_receipt.v3'] as $table => $version) {
            $row = $this->table($table)->first();
            $p = json_decode($row->receipt_json, true);
            $p['schema_version'] = $version;
            unset($p['generation_summary'], $p['planning_records_write_allowed'], $p['business_execution_allowed']);
            $this->table($table)->update(['receipt_json' => json_encode($p, JSON_PRETTY_PRINT), 'receipt_hash' => SeoWeeklyDecisionReceiptValidator::hash($p)]);
        }
        $before = $this->bytes();
        $this->seedPage(1);
        config(['app.git_sha' => str_repeat('c', 40)]);
        $this->assertTrue($this->record()['idempotent_replay']);
        $this->assertSame($before, $this->bytes());
        $this->assertSame(0, $this->table('seo_change_ledgers')->count());
    }

    public function test_later_week_refresh_has_stable_identity_and_references_do_not_follow_pointer(): void
    {
        $this->seedPage(1);
        $first = $this->record();
        $old = (new SeoDecisionCardReadService)->snapshot()['items'][0];
        CarbonImmutable::setTestNow('2026-10-01T13:45:03Z');
        $this->seedData(1, '2026-09-28');
        $next = $this->record();
        $new = (new SeoDecisionCardReadService)->snapshot()['items'][0];
        $this->assertSame(1, $next['generation_summary']['refreshed']);
        $this->assertSame($old['decision_card_id'], $new['decision_card_id']);
        $this->assertNotSame($old['decision_revision_id'], $new['decision_revision_id']);
        $historical = (new SeoWeeklyPlanningReadService)->additions(CarbonImmutable::parse(self::SLOT))['this_week_selected'];
        $this->assertSame($first['decision_revision_ids'][0], $historical['decisions'][0]['decision_revision_id']);
        $this->assertSame('2026-09-21', $historical['decisions'][0]['brief']['evidence']['end_date']);
    }

    private function seedPage(int $id): string
    {
        $url = 'https://fermatmind.com/en/articles/page-'.$id;
        $hash = hash('sha256', $url);
        $trace = ['authority_revision' => hash('sha256', 'authority'.$id), 'canonical_revision' => hash('sha256', $url.'canonical'), 'page_family' => 'articles_topics'];
        $identity = ['canonical_url_hash' => $hash, 'locale' => 'en', 'page_entity_type' => 'article', 'entity_id_or_slug' => 'page-'.$id];
        $this->table('seo_urls')->insert($trace + $identity + ['canonical_url' => $url, 'source_authority' => 'backend_cms', 'indexability_state' => 'indexable', 'is_private_flow' => false, 'metadata_json' => '{"publication_state":"published","claim_safe":true}']);
        $this->table('seo_url_entities')->insert($trace + $identity + ['entity_source' => 'articles', 'authority_status' => 'published_approved', 'binding_status' => 'current', 'current_binding_key' => hash('sha256', 'binding'.$id)]);
        $this->seedData($id, '2026-09-21');

        return $hash;
    }

    private function seedData(int $id, string $end): void
    {
        $start = CarbonImmutable::parse($end)->subDays(6)->toDateString();
        $uid = $this->seedRun($start, $end);
        for ($i = 0; $i < 7; $i++) {
            $this->table('seo_gsc_daily')->insert([
                'report_date' => CarbonImmutable::parse($start)->addDays($i)->toDateString(),
                'canonical_url_hash' => hash('sha256', 'https://fermatmind.com/en/articles/page-'.$id),
                'query_hash' => hash('sha256', 'query-'.$id), 'query_display_masked' => 'q***',
                'locale' => 'en', 'source_engine' => 'google', 'device' => 'DESKTOP', 'country' => 'usa', 'search_type' => 'web',
                'clicks' => 0, 'impressions' => 10, 'ctr_ppm' => 0, 'average_position_milli' => 10000,
                'is_brand_query' => false, 'query_type' => 'non_brand', 'data_state' => 'final',
                'mapping_state' => 'mapped', 'sync_run_uid' => $uid, 'metadata_json' => '{"data_origin":"live_gsc_api"}',
            ]);
        }
    }

    private function seedRun(string $start, string $end, string $property = 'site'): string
    {
        $uid = (string) Str::uuid();
        $p = ['schema_version' => 'seo.gsc_refresh_receipt.v2', 'status' => 'success', 'sync_run_uid' => $uid,
            'pages_fetched' => 7, 'rows_seen' => 7, 'quality_gate' => ['status' => 'pass'], 'property_hash' => hash('sha256', $property),
            'completeness' => ['schema_version' => 'seo.gsc_fetch_completeness.v1', 'pagination_complete' => true, 'truncated' => false,
                'dimensions' => SeoOpportunityEvidence::DIMENSIONS, 'search_types' => ['web'], 'covered_start_date' => $start, 'covered_end_date' => $end]];
        $p['receipt_hash'] = SeoWeeklyDecisionReceiptValidator::hash($p);
        $this->table('seo_gsc_sync_runs')->insert(['sync_run_uid' => $uid, 'window_days' => 7, 'start_date' => $start, 'end_date' => $end, 'search_types_json' => '["web"]', 'status' => 'success', 'pages_fetched' => 7, 'rows_seen' => 7, 'rows_upserted' => 7, 'started_at' => '2026-09-24 01:00:00', 'finished_at' => '2026-09-24 01:01:00', 'receipt_json' => json_encode($p)]);

        return $uid;
    }

    private function replaceRun(string $uid, array $p): void
    {
        unset($p['receipt_hash']);
        $p['receipt_hash'] = SeoWeeklyDecisionReceiptValidator::hash($p);
        $this->table('seo_gsc_sync_runs')->where('sync_run_uid', $uid)->update(['receipt_json' => json_encode($p)]);
    }

    private function evidence(): array
    {
        $c = (new SeoOpportunityQueueReadService)->planningDiscovery()['candidates'];

        return (new SeoOpportunityEvidence)->evaluate($c, CarbonImmutable::now(), fn () => null);
    }

    private function record(): array
    {
        return (new SeoWeeklyDecisionReceiptService($this->selector()))->record('scheduled', CarbonImmutable::now());
    }

    private function selector(): SeoWeeklyDecisionSelector
    {
        return new SeoWeeklyDecisionSelector(new SeoDecisionCardReadService);
    }

    private function table(string $name): \Illuminate\Database\Query\Builder
    {
        return DB::connection('seo_intel')->table($name);
    }

    private function bytes(): string
    {
        $rows = [];
        foreach (['seo_change_ledgers', 'seo_change_ledger_events', 'seo_decision_cards', 'seo_current_decision_cards', 'seo_weekly_decision_receipts', 'seo_weekly_decision_capability_receipts'] as $t) {
            $rows[$t] = $this->table($t)->get()->all();
        }

        return json_encode($rows, JSON_THROW_ON_ERROR);
    }
}
