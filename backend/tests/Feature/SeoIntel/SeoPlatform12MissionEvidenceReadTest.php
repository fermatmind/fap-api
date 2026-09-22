<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Evaluation\Platform12DailyGscCoreRuntimeEvaluator;
use App\Services\SeoCouncil\Platform12\Operations\Platform12MissionEvidenceReadService;
use App\Services\SeoCouncil\Platform12\Operations\Platform12SystemHealthReadService;
use App\Services\SeoCouncil\Platform12\Platform12DailyMissionSet;
use App\Services\SeoCouncil\Platform12\Platform12FrozenMission;
use App\Services\SeoCouncil\Platform12\Platform12ReadOnlyRuntimeGate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeoPlatform12MissionEvidenceReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.seo_intel' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'seo_intel.connection' => 'seo_intel', 'seo_council.connection' => 'seo_intel',
            'seo_council.runtime_cache_store' => 'array']);
        DB::purge('seo_intel');
        foreach (['2026_08_29_030000_create_seo_council_runtime_tables.php',
            '2026_09_04_010000_create_seo_council_scheduler_storage.php',
            '2026_09_04_020000_expand_seo_council_scheduler_fencing.php',
            '2026_09_04_030000_expand_seo_council_run_receipts.php'] as $migration) {
            (require database_path('migrations/seo_intel/'.$migration))->up();
        }
        DB::connection('seo_intel')->getSchemaBuilder()->create('seo_gsc_sync_runs', function (Blueprint $table): void {
            $table->id();
            foreach (['sync_run_uid', 'trigger_mode', 'finished_at', 'status', 'receipt_json'] as $field) {
                $table->text($field)->nullable();
            }
        });
    }

    protected function tearDown(): void
    {
        DB::purge('seo_intel');
        parent::tearDown();
    }

    public function test_historical_pt_range_and_runtime_remain_bound_when_latest_gsc_changes(): void
    {
        [$row, $receipt] = $this->fixture('2026-09-21T22:20:00Z', 'scheduled');
        $this->storeGsc('new-run', '2026-09-22T08:00:00Z', '2026-09-19');
        $result = app(Platform12MissionEvidenceReadService::class)->summary($row, $receipt, CarbonImmutable::parse('2026-09-22T09:00:00Z'));
        $this->assertSame('2026-09-18', $result['data_max_date']);
        $this->assertSame(3, $result['lag_days']);
        $this->assertSame('READY', $result['state']);
        $this->assertSame('2026-09-18', $result['gsc_collection']['cutoff_date']);
        $this->assertSame('2026-09-15', $result['gsc_collection']['start_date']);
        $this->assertSame('America/Los_Angeles', $result['gsc_collection']['timezone']);
        $this->assertSame('2026-09-21T21:00:00Z', $result['sources'][0]['observed_at']);
        $this->assertSame('2026-09-21T22:20:00Z', $result['sources'][0]['read_at']);
        $this->assertSame(['core_runtime_state' => 'AVAILABLE', 'public_api_state' => 'AVAILABLE', 'readback_state' => 'AVAILABLE'], $result['runtime']);
        $this->assertSame(str_repeat('a', 40), $result['production_sha']);
        $this->assertSame(str_repeat('a', 40), $result['readback_sha']);
        $this->assertSame($receipt['receipt_hash'], $result['receipt_hash']);
        DB::connection('seo_intel')->table('seo_gsc_sync_runs')->where('sync_run_uid', 'original-run')->delete();
        $missing = app(Platform12MissionEvidenceReadService::class)->summary($row, $receipt, CarbonImmutable::parse('2026-09-22T09:00:00Z'));
        $this->assertNull($missing['gsc_collection']);
        $this->assertSame('2026-09-18', $missing['data_max_date']);
    }

    public function test_same_frozen_source_crosses_utc_day_without_rewriting_natural_result(): void
    {
        [$naturalRow, $natural] = $this->fixture('2026-09-21T22:20:00Z', 'scheduled');
        [$controlledRow, $controlled] = $this->fixture('2026-09-22T00:20:00Z', 'controlled_acceptance');
        $reader = app(Platform12MissionEvidenceReadService::class);
        $now = CarbonImmutable::parse('2026-09-22T09:00:00Z');
        $a = $reader->summary($naturalRow, $natural, $now);
        $b = $reader->summary($controlledRow, $controlled, $now);
        $this->assertSame($a['sources'][0]['hash'], $b['sources'][0]['hash']);
        $this->assertSame('scheduled', $a['origin']);
        $this->assertSame('controlled_acceptance', $b['origin']);
        $this->assertSame(3, $a['lag_days']);
        $this->assertSame(4, $b['lag_days']);
        $this->assertSame('READY', $a['state']);
        $this->assertSame('DATA_FRESHNESS_HOLD', $b['state']);
        foreach (['en', 'zh_CN'] as $locale) {
            app()->setLocale($locale);
            $html = view('filament.ops.components.ops-mission-result-evidence', ['result' => $b, 'label' => 'latest_controlled'])->render();
            $this->assertStringContainsString('2026-09-22 08:20:00 Asia/Shanghai', $html);
            $this->assertStringContainsString('2026-09-18', $html);
            $this->assertStringContainsString('America/Los_Angeles', $html);
            $this->assertStringNotContainsString('seo-council.', $html);
        }
    }

    public function test_forged_frozen_input_and_future_evaluation_fail_closed(): void
    {
        [$row, $receipt] = $this->fixture('2026-09-21T22:20:00Z', 'scheduled');
        $reader = app(Platform12MissionEvidenceReadService::class);
        $this->assertNull($reader->summary($row, $receipt, CarbonImmutable::parse('2026-09-21T22:19:00Z')));
        $envelope = json_decode($row->mission_request_json, true);
        $envelope['evidence']['input']['gsc']['data_max_date'] = '2026-09-20';
        $row->mission_request_json = json_encode($envelope);
        $this->assertNull($reader->summary($row, $receipt, CarbonImmutable::parse('2026-09-22T09:00:00Z')));
    }

    public function test_missing_observation_is_not_replaced_by_read_time(): void
    {
        [$row, $receipt] = $this->fixture('2026-09-21T22:20:00Z', 'scheduled');
        $envelope = json_decode($row->mission_request_json, true);
        $envelope['evidence']['sources'][0]['observed_at'] = null;
        $mission = Platform12FrozenMission::freeze($envelope['slot'], $envelope['evidence'], $envelope['version_vector'], $envelope['catalog_hash']);
        $row->mission_request_json = json_encode($mission->envelope);
        $row->mission_request_hash = $mission->request->requestHash;
        $receipt['request_hash'] = $mission->request->requestHash;
        $receipt['route_plan'][0]['source_refs'] = $envelope['evidence']['sources'];
        $receipt['route_plan'][1]['sources'] = $envelope['evidence']['sources'];
        $receipt['receipt_hash'] = app(SeoRegistryHasher::class)->hashWithout($receipt, 'receipt_hash');
        $result = app(Platform12MissionEvidenceReadService::class)->summary($row, $receipt, CarbonImmutable::parse('2026-09-22T09:00:00Z'));
        $this->assertNull($result['sources'][0]['observed_at']);
        $this->assertSame('2026-09-21T22:20:00Z', $result['sources'][0]['read_at']);
        $this->assertNull($result['gsc_collection']);
    }

    public function test_latest_natural_and_controlled_remain_visible_while_next_delivery_is_pending(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-22T09:00:00Z'));
        foreach ([['2026-09-21T22:20:00Z', 'scheduled'], ['2026-09-22T00:20:00Z', 'controlled_acceptance']] as [$at, $trigger]) {
            [$row, $receipt] = $this->fixture($at, $trigger);
            $this->persist($row, $receipt);
        }
        $db = DB::connection('seo_intel');
        $pending = (array) $db->table('seo_council_schedule_deliveries')->first();
        unset($pending['id']);
        $pending = array_replace($pending, ['delivery_id' => 'next-delivery', 'slot_key' => 'next-slot', 'status' => 'PLANNED',
            'idempotency_key' => 'next-key', 'scheduled_for' => '2026-09-22 22:20:00', 'terminal_receipt_reference' => null, 'terminal_receipt_hash' => null]);
        $db->table('seo_council_schedule_deliveries')->insert($pending);
        $snapshot = app(Platform12SystemHealthReadService::class)->snapshot(['computation_enabled' => false]);
        $item = $snapshot['daily_missions']['items'][0];
        $this->assertSame('PENDING', $item['state']);
        $this->assertSame('READY', $item['latest_natural']['state']);
        $this->assertSame('DATA_FRESHNESS_HOLD', $item['latest_controlled']['state']);
        $this->assertSame(0, $snapshot['daily_missions']['business_hold_count']);
        $this->assertSame(0, collect($snapshot['items'])->firstWhere('component', 'execution_anomalies')['count']);
    }

    private function fixture(string $at, string $trigger): array
    {
        $r = $this->storeGsc('original-run', '2026-09-21T21:00:00Z', '2026-09-18');
        $hasher = app(SeoRegistryHasher::class);
        $projection = ['availability' => 'AVAILABLE', 'scheduled_receipt_status' => 'success', 'observed_at' => '2026-09-21T21:00:00Z',
            'source_hash' => $hasher->hash($r), 'trigger_mode' => 'scheduled', 'mapping_state' => 'READY', 'data_quality_state' => 'READY',
            'window_state' => 'COMPLETE', 'row_count' => 100, 'data_max_date' => '2026-09-18'];
        $evidence = ['input' => ['evaluated_at' => $at, 'gsc' => array_diff_key($projection, ['observed_at' => true, 'source_hash' => true]),
            'runtime' => ['core_runtime_state' => 'AVAILABLE', 'public_api_state' => 'AVAILABLE', 'readback_state' => 'AVAILABLE',
                'production_sha' => str_repeat('a', 40), 'readback_sha' => str_repeat('a', 40)]],
            'sources' => [['id' => 'gsc_scheduled_receipt', 'hash' => $hasher->hash($projection), 'observed_at' => $projection['observed_at'], 'read_at' => $at]],
            'source_gaps' => [], 'captured_at' => $at, 'expires_at' => CarbonImmutable::parse($at)->addHour()->toAtomString()];
        $date = substr($at, 0, 10);
        $slot = ['mission_id' => Platform12DailyMissionSet::IDS[0], 'trigger_mode' => $trigger, 'scheduled_for' => $at,
            'slot_key' => $trigger === 'scheduled' ? Platform12DailyMissionSet::IDS[0].':'.$date : 'a08:acceptance:0:'.$date.':'.str_repeat('a', 12)];
        $mission = Platform12FrozenMission::freeze($slot, $evidence, array_fill_keys(Platform12ReadOnlyRuntimeGate::VERSION_DIMENSIONS, str_repeat('a', 64)), str_repeat('a', 64));
        $output = app(Platform12DailyGscCoreRuntimeEvaluator::class)->evaluate($evidence['input']);
        $receipt = ['receipt_id' => hash('sha256', $at), 'request_hash' => $mission->request->requestHash,
            'status' => $output['state'] === 'READY' ? 'DAILY_MISSION_READY' : 'DAILY_MISSION_HOLD',
            'route_plan' => [array_merge($slot, ['kind' => 'scheduled_delivery', 'source_refs' => $evidence['sources'], 'source_gaps' => []]),
                array_merge($slot, ['kind' => 'daily_evaluation', 'sources' => $evidence['sources'], 'output' => $output])]];
        $receipt['receipt_hash'] = $hasher->hash($receipt);
        $row = (object) ['mission_id' => $slot['mission_id'], 'slot_key' => $slot['slot_key'],
            'mission_request_hash' => $mission->request->requestHash, 'mission_request_json' => json_encode($mission->envelope),
            'scheduled_for' => CarbonImmutable::parse($at)->format('Y-m-d H:i:s'), 'terminal_receipt_reference' => $receipt['receipt_id'],
            'terminal_receipt_hash' => $receipt['receipt_hash']];

        return [$row, $receipt];
    }

    private function storeGsc(string $uid, string $finished, string $max): array
    {
        $r = ['schema_version' => 'seo.gsc_refresh_receipt.v2', 'sync_run_uid' => $uid, 'status' => 'success',
            'trigger_mode' => 'scheduled', 'reporting_timezone' => 'America/Los_Angeles', 'unmapped_rows' => 0,
            'rows_seen' => 100, 'data_max_date' => $max, 'start_date' => '2026-09-15', 'end_date' => $max, 'quality_gate' => ['status' => 'pass']];
        DB::connection('seo_intel')->table('seo_gsc_sync_runs')->updateOrInsert(['sync_run_uid' => $uid],
            ['trigger_mode' => 'scheduled', 'status' => 'success', 'finished_at' => CarbonImmutable::parse($finished)->format('Y-m-d H:i:s'), 'receipt_json' => json_encode($r)]);

        return $r;
    }

    private function persist(object $row, array $receipt): void
    {
        $db = DB::connection('seo_intel');
        $db->table('seo_council_schedule_deliveries')->insert((array) $row + ['delivery_id' => $receipt['receipt_id'],
            'catalog_version' => 'seo.platform12.mission_catalog.v1', 'catalog_hash' => str_repeat('a', 64),
            'idempotency_key' => $receipt['receipt_id'], 'attempt' => 1, 'status' => $receipt['status'] === 'DAILY_MISSION_READY' ? 'CLOSED' : 'HELD',
            'created_at' => $row->scheduled_for, 'updated_at' => $row->scheduled_for]);
        $record = array_fill_keys(['run_id', 'request_hash', 'catalog_hash', 'policy_hash', 'binding_hash', 'evidence_hash', 'capability_hash'], str_repeat('a', 64));
        $db->table('seo_council_run_receipts')->insert(array_merge($record, ['receipt_id' => $receipt['receipt_id'], 'run_id' => $receipt['receipt_id'],
            'request_hash' => $row->mission_request_hash, 'receipt_hash' => $receipt['receipt_hash'], 'receipt_version' => 1, 'receipt_json' => json_encode($receipt)]));
    }
}
