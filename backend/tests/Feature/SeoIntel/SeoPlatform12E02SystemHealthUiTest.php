<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Filament\Ops\Pages\SeoOperationsPage;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Operations\Platform12SystemHealthReadService;
use App\Services\SeoCouncil\Platform12\Platform12DailyMissionSet;
use App\Support\Rbac\PermissionNames;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SeoPlatform12E02SystemHealthUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.seo_intel', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('seo_council.scheduler_enabled', false);
        config()->set('seo_council.mission_execution_enabled', false);
        config()->set('seo_council.model_runtime_enabled', false);
        config()->set('seo_council.tool_broker_enabled', false);
        config()->set('seo_council.notification_dispatch_enabled', false);
        DB::purge('seo_intel');
        DB::connection('seo_intel')->getPdo();

        $storage = require database_path('migrations/seo_intel/2026_09_04_010000_create_seo_council_scheduler_storage.php');
        $storage->up();
        $fencing = require database_path('migrations/seo_intel/2026_09_04_020000_expand_seo_council_scheduler_fencing.php');
        $fencing->up();
        (require database_path('migrations/seo_intel/2026_09_04_030000_expand_seo_council_run_receipts.php'))->up();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::purge('seo_intel');

        parent::tearDown();
    }

    public function test_disabled_empty_runtime_is_distinct_from_missing_evidence(): void
    {
        $snapshot = app(Platform12SystemHealthReadService::class)->snapshot();
        $items = collect($snapshot['items'])->keyBy('component');

        $this->assertSame('HOLD', $snapshot['status']);
        $this->assertSame('DISABLED', $items['scheduler']['state']);
        $this->assertSame('VALID_ZERO', $items['lease_backlog']['state']);
        $this->assertSame('UNAVAILABLE', $items['data_freshness']['state']);
        $this->assertSame('UNAVAILABLE', $items['policy_drift']['state']);
        $this->assertSame('VALID_ZERO', $items['cost']['state']);
        $this->assertSame('DISABLED', $items['notification_transport']['state']);
        $this->assertSame('READY', $items['write_guards']['state']);
        $this->assertTrue($snapshot['read_only']);
        $this->assertFalse($snapshot['execution_allowed']);
        $this->assertFalse($snapshot['write_allowed']);
    }

    public function test_health_reuses_the_provided_page_runtime_snapshot(): void
    {
        config()->set('seo_council.scheduler_enabled', true);
        // The actual runtime is still disabled; a supplied UI snapshot must not be reread.
        config()->set('seo_council.daily_read_only_enabled', false);
        $snapshot = app(Platform12SystemHealthReadService::class)->snapshot([
            'state' => 'ACTIVE_READ_ONLY', 'computation_enabled' => true,
            'audit_enabled' => true, 'business_write_enabled' => false,
        ]);
        $this->assertSame('READY', collect($snapshot['items'])->keyBy('component')['scheduler']['state']);
        $this->assertFalse($snapshot['write_allowed']);
        $this->assertFalse((bool) config('seo_council.daily_read_only_enabled'));
    }

    public function test_hold_and_stale_backlog_states_are_rendered_without_actions(): void
    {
        $this->insertDelivery('HELD', now()->utc());
        $held = app(Platform12SystemHealthReadService::class)->snapshot();
        $this->assertSame('HOLD', $held['status']);

        DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->delete();
        $this->insertDelivery('PLANNED', now()->utc()->subDays(2));
        $stale = app(Platform12SystemHealthReadService::class)->snapshot();
        $staleItems = collect($stale['items'])->keyBy('component');
        $this->assertSame('STALE', $staleItems['lease_backlog']['state']);
        $this->assertSame('UNAVAILABLE', $staleItems['data_freshness']['state']);

        $html = view('filament.ops.components.ops-system-health-workspace', ['snapshot' => $stale])->render();
        $this->assertStringContainsString('data-scheduler-activation="disabled"', $html);
        $this->assertStringContainsString('data-component-state="STALE"', $html);
        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('wire:click', $html);
    }

    public function test_query_failure_fails_to_unavailable_without_mutating_runtime_flags(): void
    {
        DB::purge('seo_intel');
        config()->set('database.connections.seo_intel.driver', 'unavailable');

        $snapshot = app(Platform12SystemHealthReadService::class)->snapshot();

        $this->assertSame('UNAVAILABLE', $snapshot['status']);
        $this->assertNull($snapshot['pagination']['total']);
        $this->assertSame([], $snapshot['items']);
        $this->assertFalse((bool) config('seo_council.scheduler_enabled'));

        config()->set('database.connections.seo_intel.driver', 'sqlite');
        DB::purge('seo_intel');
    }

    public function test_page_access_covers_owner_ops_read_and_unauthorized_users(): void
    {
        $owner = $this->adminWithPermission(PermissionNames::ADMIN_OWNER);
        $opsReader = $this->adminWithPermission(PermissionNames::ADMIN_OPS_READ);
        $unauthorized = $this->adminWithPermission(null);

        $this->actingAs($owner, (string) config('admin.guard', 'admin'));
        $this->assertTrue(SeoOperationsPage::canAccess());
        $this->actingAs($opsReader, (string) config('admin.guard', 'admin'));
        $this->assertTrue(SeoOperationsPage::canAccess());
        $this->actingAs($unauthorized, (string) config('admin.guard', 'admin'));
        $this->assertFalse(SeoOperationsPage::canAccess());

        $workspace = (string) file_get_contents(resource_path('views/filament/ops/components/ops-agent-council-workspace.blade.php'));
        $this->assertStringContainsString('ops-system-health-workspace', $workspace);
        $this->assertSame(['overview', 'performance', 'technical', 'url-truth', 'content', 'automation'], SeoOperationsPage::workspaceKeys());
    }

    public function test_receipt_time_authorization_and_business_hold_are_independent(): void
    {
        CarbonImmutable::setTestNow('2026-09-21T23:00:00Z');
        $this->dailyReceipt('HELD', 'RECONCILIATION_INCOMPLETE_HOLD', '2026-09-22T06:25:00+08:00');
        $snapshot = app(Platform12SystemHealthReadService::class)->snapshot($this->runtime());
        $mission = $snapshot['daily_missions']['items'][1];
        $this->assertSame('2026-09-21T22:25:00Z', $mission['observed_at']);
        $this->assertSame('natural_run_authorized', $mission['gate_next_step']);
        $this->assertSame('HOLD', $mission['state']);
        $items = collect($snapshot['items'])->keyBy('component');
        $this->assertSame('VALID_ZERO', $items['lease_backlog']['state']);
        $this->assertSame(0, $items['lease_backlog']['count']);
        $this->assertSame(1, $items['business_checks']['count']);
        foreach (['en', 'zh_CN'] as $locale) {
            app()->setLocale($locale);
            $html = view('filament.ops.components.ops-system-health-workspace', compact('snapshot'))->render();
            $this->assertStringContainsString('2026-09-22 06:25:00', $html);
            $this->assertStringContainsString('datetime="2026-09-21T22:25:00Z"', $html);
            $this->assertStringContainsString('Asia/Shanghai', $html);
            $this->assertStringNotContainsString(__('seo-council.explicit_selection_required'), $html);
            $this->assertStringNotContainsString('<button', $html);
            $this->assertStringNotContainsString('<form', $html);
        }
    }

    public function test_gate_combinations_do_not_reassign_business_results(): void
    {
        $cases = [
            [['selected' => false, 'run_allowed' => false], 'RUNNING', 'explicit_selection_required'],
            [['run_allowed' => false], 'PAUSED', 'mission_paused'],
            [['run_allowed' => false, 'acceptance_ready' => false], 'RUNNING', 'public_checks_required'],
            [['run_allowed' => false, 'source_accepted' => false], 'RUNNING', 'source_acceptance_required'],
            [['run_allowed' => false, 'end_to_end_accepted' => false], 'RUNNING', 'end_to_end_required'],
            [['run_allowed' => false], 'RUNNING', 'runtime_blocked'],
            [['selected' => false, 'run_allowed' => true], 'RUNNING', 'authorization_unknown'],
            [[], 'PAUSED', 'authorization_unknown'],
        ];
        foreach ($cases as [$gate, $pause, $expected]) {
            $runtime = $this->runtime($gate);
            $runtime['pause_intent'] = $pause;
            $item = app(Platform12SystemHealthReadService::class)->snapshot($runtime)['daily_missions']['items'][1];
            $this->assertSame($expected, $item['gate_next_step']);
            $this->assertNull($item['next_run']);
        }
        $runtime = $this->runtime();
        $runtime['missions'] = [];
        $this->assertSame('authorization_unknown', app(Platform12SystemHealthReadService::class)->snapshot($runtime)['daily_missions']['items'][1]['gate_next_step']);
    }

    public function test_pending_delivery_and_lease_are_not_counted_twice_and_future_is_not_backlog(): void
    {
        $this->insertDelivery('CLAIMED', now()->utc());
        $db = DB::connection('seo_intel');
        $db->table('seo_council_schedule_deliveries')->update(['lease_key' => 'fixture', 'fencing_token' => 1, 'mission_id' => Platform12DailyMissionSet::IDS[1]]);
        $db->table('seo_council_scheduler_leases')->insert(['lease_key' => 'fixture', 'owner_token_hash' => str_repeat('a', 64),
            'fencing_token' => 1, 'lease_expires_at' => now()->utc()->addMinute(), 'created_at' => now(), 'updated_at' => now()]);
        $snapshot = app(Platform12SystemHealthReadService::class)->snapshot($this->runtime());
        $items = collect($snapshot['items'])->keyBy('component');
        $this->assertSame(1, $items['lease_backlog']['count']);
        $this->assertSame('RUNNING', $items['lease_backlog']['state']);
        $this->assertSame(1, $items['active_leases']['count']);
        $this->assertSame('RUNNING', $snapshot['daily_missions']['items'][1]['state']);
        $db->table('seo_council_scheduler_leases')->update(['lease_expires_at' => now()->utc()]);
        $items = collect(app(Platform12SystemHealthReadService::class)->snapshot($this->runtime())['items'])->keyBy('component');
        $this->assertSame('HOLD', $items['execution_anomalies']['state']);
        $this->assertSame('EXECUTION_HOLD', app(Platform12SystemHealthReadService::class)->snapshot($this->runtime())['daily_missions']['items'][1]['state']);
        $db->table('seo_council_schedule_deliveries')->delete();
        $this->insertDelivery('PLANNED', now()->utc()->addDay());
        $items = collect(app(Platform12SystemHealthReadService::class)->snapshot($this->runtime())['items'])->keyBy('component');
        $this->assertSame('VALID_ZERO', $items['lease_backlog']['state']);
        $this->assertSame(1, $items['scheduled_deliveries']['count']);
    }

    public function test_invalid_receipt_and_old_evidence_never_report_ready(): void
    {
        CarbonImmutable::setTestNow('2026-09-22T01:00:00Z');
        $this->dailyReceipt('CLOSED', 'READY', '2026-09-20T22:25:00Z');
        $item = app(Platform12SystemHealthReadService::class)->snapshot($this->runtime())['daily_missions']['items'][1];
        $this->assertSame('STALE', $item['state']);
        DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->update(['terminal_receipt_hash' => str_repeat('0', 64)]);
        $item = app(Platform12SystemHealthReadService::class)->snapshot($this->runtime())['daily_missions']['items'][1];
        $this->assertSame('UNAVAILABLE', $item['state']);
        $this->assertNull($item['observed_at']);
        $this->assertNull($item['receipt_hash']);
    }

    public function test_time_inputs_require_proven_semantics_and_preserve_instants(): void
    {
        $time = \App\Services\SeoCouncil\Platform12\Operations\Platform12OperationsTime::class;
        foreach (['2026-09-21T22:25:00Z', '2026-09-22T06:25:00+08:00', '2026-09-21T18:25:00-04:00'] as $value) {
            $this->assertSame('2026-09-21T22:25:00Z', $time::iso($time::instant($value)));
            $this->assertSame('2026-09-22 06:25:00 Asia/Shanghai (UTC+08:00)', $time::display($value));
        }
        foreach ([null, '', 'tomorrow', '2026-09-22 06:25:00', '2026-02-30T00:00:00Z', '2026-09-22T99:00:00Z'] as $value) {
            $this->assertNull($time::instant($value));
            $this->assertNull($time::display($value));
        }
        $this->assertSame('2026-09-22T06:25:00Z', $time::iso($time::utcDatetime('2026-09-22 06:25:00')));
    }

    public function test_unrelated_new_work_does_not_hide_old_pending_delivery_and_recovered_failure_is_not_current(): void
    {
        $this->insertDelivery('PLANNED', now()->utc()->subDays(2));
        $this->insertDelivery('CLOSED', now()->utc());
        $db = DB::connection('seo_intel');
        $db->table('seo_council_schedule_deliveries')->where('status', 'CLOSED')->update(['mission_id' => 'unrelated']);
        $items = collect(app(Platform12SystemHealthReadService::class)->snapshot($this->runtime())['items'])->keyBy('component');
        $this->assertSame('STALE', $items['lease_backlog']['state']);
        $db->table('seo_council_schedule_deliveries')->delete();
        $this->insertDelivery('FAILED', now()->utc()->subDays(2));
        $items = collect(app(Platform12SystemHealthReadService::class)->snapshot($this->runtime())['items'])->keyBy('component');
        $this->assertSame(1, $items['execution_anomalies']['count']);
        $this->insertDelivery('CLOSED', now()->utc());
        $items = collect(app(Platform12SystemHealthReadService::class)->snapshot($this->runtime())['items'])->keyBy('component');
        $this->assertSame('VALID_ZERO', $items['execution_anomalies']['state']);
        $this->assertSame(0, $items['lease_backlog']['count']);
    }

    public function test_receipt_stale_boundary_and_invalid_times_are_not_guessed(): void
    {
        CarbonImmutable::setTestNow('2026-09-23T00:25:00Z');
        $this->dailyReceipt('CLOSED', 'READY', '2026-09-21T22:25:00Z');
        $this->assertSame('READY', app(Platform12SystemHealthReadService::class)->snapshot($this->runtime())['daily_missions']['items'][1]['state']);
        CarbonImmutable::setTestNow('2026-09-23T00:25:01Z');
        $this->assertSame('STALE', app(Platform12SystemHealthReadService::class)->snapshot($this->runtime())['daily_missions']['items'][1]['state']);
        $db = DB::connection('seo_intel');
        foreach (['2026-09-22 06:25:00', 'not-a-time', null] as $time) {
            $db->table('seo_council_run_receipts')->delete();
            $db->table('seo_council_schedule_deliveries')->delete();
            $this->dailyReceipt('CLOSED', 'READY', $time ?? '');
            $item = app(Platform12SystemHealthReadService::class)->snapshot($this->runtime())['daily_missions']['items'][1];
            $this->assertSame('UNAVAILABLE', $item['state']);
            $this->assertNull($item['observed_at']);
        }
    }

    public function test_reads_and_bilingual_rendering_issue_no_writes_and_no_missing_translation_keys(): void
    {
        $this->dailyReceipt('HELD', 'DATA_FRESHNESS_HOLD', now()->utc()->format('Y-m-d\TH:i:s\Z'));
        $writes = [];
        DB::connection('seo_intel')->listen(function ($event) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        foreach (['zh_CN', 'en'] as $locale) {
            app()->setLocale($locale);
            $snapshot = app(Platform12SystemHealthReadService::class)->snapshot($this->runtime());
            $html = view('filament.ops.components.ops-system-health-workspace', compact('snapshot'))->render();
            $this->assertStringNotContainsString('seo-council.', $html);
            $this->assertStringNotContainsString('ops.custom_pages.', $html);
            $this->assertFalse($snapshot['execution_allowed']);
            $this->assertFalse($snapshot['write_allowed']);
        }
        $this->assertSame([], $writes);
        $this->assertFalse((bool) config('seo_council.model_runtime_enabled'));
        $this->assertFalse((bool) config('seo_council.tool_broker_enabled'));
    }

    public function test_execution_hold_is_not_a_business_hold_and_receipt_binding_is_required(): void
    {
        $this->dailyReceipt('HELD', 'RECONCILIATION_INCOMPLETE_HOLD', now()->utc()->format('Y-m-d\TH:i:s\Z'));
        $db = DB::connection('seo_intel');
        $receipt = json_decode($db->table('seo_council_run_receipts')->value('receipt_json'), true);
        $receipt['status'] = 'DAILY_STOPPED_HOLD';
        $receipt['route_plan'] = [$receipt['route_plan'][0]];
        $receipt['receipt_hash'] = app(SeoRegistryHasher::class)->hashWithout($receipt, 'receipt_hash');
        $db->table('seo_council_run_receipts')->update(['receipt_hash' => $receipt['receipt_hash'], 'receipt_json' => json_encode($receipt)]);
        $db->table('seo_council_schedule_deliveries')->update(['terminal_receipt_hash' => $receipt['receipt_hash']]);
        $snapshot = app(Platform12SystemHealthReadService::class)->snapshot($this->runtime());
        $items = collect($snapshot['items'])->keyBy('component');
        $this->assertSame(1, $items['execution_anomalies']['count']);
        $this->assertSame(0, $snapshot['daily_missions']['business_hold_count']);
        $this->assertSame('EXECUTION_HOLD', $snapshot['daily_missions']['items'][1]['state']);
        foreach (['UNRECOGNIZED_RECEIPT' => 'UNAVAILABLE', 'POLICY_HOLD' => 'EXECUTION_HOLD'] as $status => $expected) {
            $receipt['status'] = $status;
            $receipt['receipt_hash'] = app(SeoRegistryHasher::class)->hashWithout($receipt, 'receipt_hash');
            $db->table('seo_council_run_receipts')->update(['receipt_hash' => $receipt['receipt_hash'], 'receipt_json' => json_encode($receipt)]);
            $db->table('seo_council_schedule_deliveries')->update(['terminal_receipt_hash' => $receipt['receipt_hash']]);
            $snapshot = app(Platform12SystemHealthReadService::class)->snapshot($this->runtime());
            $this->assertSame($expected, $snapshot['daily_missions']['items'][1]['state']);
            $this->assertSame($expected === 'UNAVAILABLE' ? 'UNAVAILABLE' : 'HOLD', collect($snapshot['items'])->keyBy('component')['execution_anomalies']['state']);
        }
        $receipt['status'] = 'DAILY_MISSION_HOLD';
        $receipt['stop_reason'] = 'daily_stopped_before_commit';
        $receipt['route_plan'][] = ['kind' => 'daily_evaluation', 'output' => ['state' => 'DATA_FRESHNESS_HOLD', 'evaluated_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')]];
        $receipt['receipt_hash'] = app(SeoRegistryHasher::class)->hashWithout($receipt, 'receipt_hash');
        $db->table('seo_council_run_receipts')->update(['receipt_hash' => $receipt['receipt_hash'], 'receipt_json' => json_encode($receipt)]);
        $db->table('seo_council_schedule_deliveries')->update(['terminal_receipt_hash' => $receipt['receipt_hash']]);
        $snapshot = app(Platform12SystemHealthReadService::class)->snapshot($this->runtime());
        $this->assertSame('EXECUTION_HOLD', $snapshot['daily_missions']['items'][1]['state']);
        $this->assertSame(0, $snapshot['daily_missions']['business_hold_count']);
        unset($receipt['stop_reason']);
        $receipt['route_plan'][0]['source_gaps'] = ['url_truth_reconciliation'];
        $receipt['receipt_hash'] = app(SeoRegistryHasher::class)->hashWithout($receipt, 'receipt_hash');
        $db->table('seo_council_run_receipts')->update(['receipt_hash' => $receipt['receipt_hash'], 'receipt_json' => json_encode($receipt)]);
        $db->table('seo_council_schedule_deliveries')->update(['terminal_receipt_hash' => $receipt['receipt_hash']]);
        $snapshot = app(Platform12SystemHealthReadService::class)->snapshot($this->runtime());
        $this->assertSame('UNAVAILABLE', $snapshot['daily_missions']['items'][1]['state']);
        $this->assertSame(0, $snapshot['daily_missions']['business_hold_count']);
        $db->table('seo_council_schedule_deliveries')->update(['mission_request_hash' => str_repeat('f', 64)]);
        $snapshot = app(Platform12SystemHealthReadService::class)->snapshot($this->runtime());
        $this->assertSame('UNAVAILABLE', $snapshot['daily_missions']['items'][1]['state']);
        $this->assertNull($snapshot['daily_missions']['items'][1]['receipt_hash']);
    }

    private function runtime(array $overrides = []): array
    {
        return ['state' => 'ACTIVE_READ_ONLY', 'computation_enabled' => true, 'audit_enabled' => true,
            'public_gate' => 'READY', 'pause_intent' => 'RUNNING', 'missions' => array_fill_keys(Platform12DailyMissionSet::IDS,
                array_replace(['selected' => true, 'run_allowed' => true, 'acceptance_ready' => true,
                    'source_accepted' => true, 'end_to_end_accepted' => true, 'reason' => 'READY'], $overrides))];
    }

    private function dailyReceipt(string $status, string $result, string $evaluated): void
    {
        $this->insertDelivery($status, now()->utc());
        $db = DB::connection('seo_intel');
        $id = Platform12DailyMissionSet::IDS[1];
        $receipt = ['request_hash' => str_repeat('b', 64), 'receipt_id' => str_repeat('c', 64), 'status' => $status === 'CLOSED' ? 'DAILY_MISSION_READY' : 'DAILY_MISSION_HOLD',
            'route_plan' => [['kind' => 'scheduled_delivery', 'mission_id' => $id, 'scheduled_for' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'trigger_mode' => 'controlled_acceptance', 'source_refs' => [], 'source_gaps' => []],
                ['kind' => 'daily_evaluation', 'output' => ['state' => $result, 'evaluated_at' => $evaluated]]]];
        $receipt['receipt_hash'] = app(SeoRegistryHasher::class)->hashWithout($receipt, 'receipt_hash');
        $db->table('seo_council_schedule_deliveries')->update(['mission_id' => $id, 'terminal_receipt_reference' => $receipt['receipt_id'], 'terminal_receipt_hash' => $receipt['receipt_hash']]);
        $row = array_fill_keys(['run_id', 'request_hash', 'catalog_hash', 'policy_hash', 'binding_hash', 'evidence_hash', 'capability_hash'], str_repeat('a', 64));
        $db->table('seo_council_run_receipts')->insert([...$row, 'receipt_id' => $receipt['receipt_id'], 'receipt_hash' => $receipt['receipt_hash'], 'receipt_version' => 1, 'receipt_json' => json_encode($receipt)]);
    }

    private function insertDelivery(string $status, \DateTimeInterface $updatedAt): void
    {
        DB::connection('seo_intel')->table('seo_council_schedule_deliveries')->insert([
            'delivery_id' => hash('sha256', $status.$updatedAt->format(DATE_ATOM)),
            'slot_key' => 'daily:system-health:'.$status,
            'scheduled_for' => $updatedAt->format('Y-m-d H:i:s'),
            'catalog_version' => 'seo.platform12.mission_catalog.v1',
            'catalog_hash' => str_repeat('a', 64),
            'mission_id' => 'system-health-fixture',
            'mission_request_hash' => str_repeat('b', 64),
            'mission_request_json' => '{}',
            'idempotency_key' => 'system-health:'.strtolower($status),
            'attempt' => 1,
            'status' => $status,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    private function adminWithPermission(?string $permissionName): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'ops_'.Str::lower(Str::random(6)),
            'email' => 'ops_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);
        $role = Role::query()->create([
            'name' => 'role_'.Str::lower(Str::random(8)),
            'description' => null,
        ]);
        if ($permissionName !== null) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => null],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }
}
