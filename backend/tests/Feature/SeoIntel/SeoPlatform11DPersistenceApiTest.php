<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Http\Middleware\EnsureAdminTotpVerified;
use App\Http\Middleware\EnsureSeoCouncilMissionAuthorized;
use App\Http\Middleware\EnsureSeoIntelReadAuthorized;
use App\Http\Middleware\OpsAccessControl;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Services\SeoCouncil\Entrypoints\ApiMissionAdapter;
use App\Services\SeoCouncil\Memory\DecisionHistoryProjectionService;
use App\Services\SeoCouncil\Memory\OperatorTimeService;
use App\Support\Rbac\PermissionNames;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class SeoPlatform11DPersistenceApiTest extends TestCase
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
        config()->set('seo_council.connection', 'seo_intel');
        config()->set('seo_council.mission_persistence_enabled', true);
        DB::purge('seo_intel');
        DB::connection('seo_intel')->getPdo();
        $migration = require database_path('migrations/seo_intel/2026_08_29_030000_create_seo_council_runtime_tables.php');
        $migration->up();
    }

    public function test_expand_only_runtime_migration_has_all_tables_and_unique_constraints(): void
    {
        foreach (['seo_council_runs', 'seo_council_run_steps', 'seo_council_conflicts', 'seo_operator_time_entries'] as $table) {
            $this->assertTrue(DB::connection('seo_intel')->getSchemaBuilder()->hasTable($table));
        }

        $input = $this->request();
        app(ApiMissionAdapter::class)->submit($input);
        $duplicate = (array) DB::connection('seo_intel')->table('seo_council_runs')->first();
        unset($duplicate['id']);
        $duplicate['run_id'] = str_repeat('c', 64);
        $duplicate['receipt_hash'] = str_repeat('d', 64);

        $this->expectException(QueryException::class);
        DB::connection('seo_intel')->table('seo_council_runs')->insert($duplicate);
    }

    public function test_idempotency_returns_the_same_immutable_receipt_and_conflicting_payload_holds(): void
    {
        $input = $this->request();
        $first = app(ApiMissionAdapter::class)->submit($input);
        $same = app(ApiMissionAdapter::class)->submit($input);
        $different = $input;
        $different['locale'] = 'en';
        $conflict = app(ApiMissionAdapter::class)->submit($different);

        $this->assertSame($first, $same);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $conflict['status']);
        $this->assertFalse($conflict['execution_allowed']);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_council_runs')->count());
        $this->assertSame(14, DB::connection('seo_intel')->table('seo_council_run_steps')->count());
    }

    public function test_terminal_hold_receipt_cannot_be_resumed_and_requires_a_new_mission_request(): void
    {
        $first = app(ApiMissionAdapter::class)->submit($this->request());
        $resume = $this->request();
        $resume['mission_id'] = 'mission:persistence:resume';
        $resume['idempotency_key'] = 'idempotency:persistence:resume';
        $resume['resume_from'] = [
            'receipt_hash' => $first['receipt_hash'],
            'step_hash' => $first['steps'][0]['step_hash'],
        ];

        $receipt = app(ApiMissionAdapter::class)->submit($resume);

        $this->assertSame('STALE_RESUME_HOLD', $receipt['status']);
        $this->assertSame('resume_hash_verification_failed', $receipt['stop_reason']);
        $this->assertFalse($receipt['execution_allowed']);
    }

    public function test_operator_time_baseline_does_not_invent_zero_and_counts_only_routine_maintenance(): void
    {
        $service = app(OperatorTimeService::class);
        $this->assertSame(['state' => 'NO_OBSERVATIONS', 'total_minutes' => null, 'observation_count' => 0], $service->routineMaintenanceBaseline());

        $service->record('2026-08-29', 'seo_growth_project', 45, str_repeat('a', 64), str_repeat('b', 64), 'GROWTH_PROJECT');
        $this->assertSame(['state' => 'NO_OBSERVATIONS', 'total_minutes' => null, 'observation_count' => 0], $service->routineMaintenanceBaseline());
        $service->record('2026-08-29', 'routine_seo_maintenance', 30, str_repeat('a', 64), str_repeat('b', 64), 'ROUTINE_MAINTENANCE');
        $this->assertSame(['state' => 'OBSERVED', 'total_minutes' => 30, 'observation_count' => 1], $service->routineMaintenanceBaseline());

        try {
            $service->record('2026-08-29', 'routine_seo_maintenance', 30, str_repeat('a', 64), str_repeat('b', 64), 'owner@example.test');
            $this->fail('Private operator note must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('OPERATOR_TIME_ENTRY_INVALID', $exception->getMessage());
        }
    }

    public function test_decision_history_never_infers_missing_sources_from_chat_or_logs(): void
    {
        $projection = app(DecisionHistoryProjectionService::class)->project();

        $this->assertSame('SOURCE_CAPABILITY_UNAVAILABLE', $projection['status']);
        $this->assertSame([], $projection['records']);
        $this->assertFalse($projection['execution_allowed']);
        $encoded = strtolower(json_encode($projection, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('chat', $encoded);
        $this->assertStringNotContainsString('prompt', $encoded);
    }

    public function test_council_api_requires_admin_totp_ops_and_exact_solo_owner_boundary(): void
    {
        $route = Route::getRoutes()->getByName('api.v0_5.ops.seo_intel.council.missions.store');
        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        foreach ([EnsureAdminTotpVerified::class, OpsAccessControl::class, EnsureSeoIntelReadAuthorized::class, EnsureSeoCouncilMissionAuthorized::class] as $middleware) {
            $this->assertContains($middleware, $route->gatherMiddleware());
        }

        $this->postJson('/api/v0.5/ops/seo-intel/council/missions', $this->request())
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHORIZED');

        $nonOwner = $this->createAdminWithPermissions([PermissionNames::ADMIN_SEO_INTEL_READ]);
        $this->withSession(['ops_org_id' => 0, 'ops_admin_totp_verified_user_id' => (int) $nonOwner->id])
            ->actingAs($nonOwner, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/ops/seo-intel/council/missions', $this->request())
            ->assertForbidden();

        $owner = $this->createAdminWithPermissions([PermissionNames::ADMIN_OWNER]);
        config()->set('review_governance.solo_owner_admin_user_id', (int) $owner->id);
        config()->set('admin.totp.enabled', true);
        $owner->forceFill(['totp_enabled_at' => now()])->save();
        $payload = ['caller_type' => 'local_skill', ...$this->request()];
        $this->withSession(['ops_org_id' => 0, 'ops_admin_totp_verified_user_id' => (int) $owner->id])
            ->actingAs($owner, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/ops/seo-intel/council/missions', $payload)
            ->assertAccepted()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.caller_provenance.caller_type', 'api')
            ->assertJsonPath('data.execution_allowed', false)
            ->assertJsonPath('meta.execution_allowed', false);
    }

    /** @param list<string> $permissions */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'admin_'.Str::lower(Str::random(6)),
            'email' => 'admin_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);
        $role = Role::query()->create(['name' => 'role_'.Str::lower(Str::random(8)), 'description' => null]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName], ['description' => null]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return [
            'mission_id' => 'mission:persistence:test',
            'idempotency_key' => 'idempotency:persistence:test',
            'mission_type' => 'weekly_opportunity',
            'family' => 'tests',
            'locale' => 'zh-CN',
            'review_domain' => null,
            'requested_role' => null,
            'evidence_bundle_refs' => [[
                'bundle_id' => 'bundle:persistence:test',
                'bundle_version' => 1,
                'bundle_hash' => hash('sha256', 'persistence'),
                'evidence_type' => 'search_measurement',
                'status' => 'READY',
                'authority_revision' => str_repeat('a', 64),
            ]],
            'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [],
            'egress_scope' => [],
            'resume_from' => null,
        ];
    }
}
