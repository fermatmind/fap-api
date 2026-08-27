<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Http\Middleware\EnsureSeoIntelReadAuthorized;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Services\SeoIntel\Ledger\SeoLedgerSnapshotReadService;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SeoPlatform08ReadOnlyLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'admin.totp.enabled' => false,
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('seo_intel');

        $migration = require database_path('migrations/seo_intel/2026_08_27_010000_create_seo_change_ledger_tables.php');
        $migration->up();
    }

    public function test_protected_get_route_requires_the_existing_seo_read_permission(): void
    {
        $route = Route::getRoutes()->getByName('api.v0_5.ops.seo_intel.experiment_ledger');

        $this->assertNotNull($route);
        $this->assertContains('GET', $route->methods());
        $this->assertContains(EnsureSeoIntelReadAuthorized::class, $route->gatherMiddleware());

        $this->getJson('/api/v0.5/ops/seo-intel/experiment-ledger')->assertUnauthorized();

        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/ops/seo-intel/experiment-ledger')
            ->assertForbidden()
            ->assertJsonPath('message', 'admin_seo_intel_read_required');
    }

    public function test_empty_ledger_is_a_true_empty_snapshot(): void
    {
        $snapshot = (new SeoLedgerSnapshotReadService)->snapshot();

        $this->assertSame('production_proven', $snapshot['state']);
        $this->assertSame('verified_zero', $snapshot['data_state']);
        $this->assertTrue($snapshot['empty']);
        $this->assertSame([], $snapshot['items']);
        $this->assertSame(0, $snapshot['pagination']['total']);
        $this->assertTrue($snapshot['read_only']);

        $workspace = (string) file_get_contents(resource_path('views/filament/ops/components/ops-experiment-ledger-workspace.blade.php'));
        $this->assertStringContainsString('evidence_readback.public_runtime.status', $workspace);
        $this->assertStringContainsString('evidence_readback.measurement.quality_state', $workspace);
        $this->assertStringNotContainsString('wire:click', $workspace);
    }

    public function test_authorized_api_is_paginated_and_excludes_private_raw_and_topology_fields(): void
    {
        $this->seedLedger('2026-08-27 01:00:00', 'First bounded hypothesis');
        $this->seedLedger('2026-08-27 02:00:00', 'Second bounded hypothesis');
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_SEO_INTEL_READ]);

        $response = $this->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/ops/seo-intel/experiment-ledger?page=1&limit=1')
            ->assertOk()
            ->assertJsonPath('meta.contract_version', SeoLedgerSnapshotReadService::CONTRACT_VERSION)
            ->assertJsonPath('meta.read_only', true)
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonPath('data.items.0.hypothesis', 'Second bounded hypothesis')
            ->assertJsonPath('data.items.0.scope.public_url_count', 2)
            ->assertJsonPath('data.items.0.evidence_readback.public_runtime.status', 'healthy')
            ->assertJsonPath('data.items.0.status', 'observing');

        $encoded = $response->getContent();
        foreach ([
            'https://private.example.test/member/result',
            'https://fermatmind.com/en/tests/example',
            'raw-response-body',
            '10.0.0.12',
            'owner_actor',
            'actor_json',
            'source_json',
            'evidence_hash',
            'public_url_cohort_json',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    private function seedLedger(string $updatedAt, string $hypothesis): void
    {
        DB::connection('seo_intel')->table('seo_change_ledgers')->insert([
            'ledger_id' => (string) Str::uuid(),
            'schema_version' => 'seo.change_ledger.v1',
            'idempotency_key' => (string) Str::uuid(),
            'change_type' => 'metadata',
            'hypothesis' => $hypothesis,
            'rationale' => 'Bounded public cohort evaluation.',
            'source_json' => json_encode(['private_topology' => '10.0.0.12'], JSON_THROW_ON_ERROR),
            'public_url_cohort_json' => json_encode([
                'https://fermatmind.com/en/tests/example',
                'https://private.example.test/member/result',
            ], JSON_THROW_ON_ERROR),
            'page_family' => 'test_detail',
            'locale' => 'en',
            'baseline_window_json' => json_encode(['value' => 0, 'unit' => 'clicks'], JSON_THROW_ON_ERROR),
            'primary_metric_json' => json_encode(['name' => 'organic_clicks', 'value' => 0], JSON_THROW_ON_ERROR),
            'guardrail_metrics_json' => json_encode(['status' => 'within_bounds'], JSON_THROW_ON_ERROR),
            'observation_window_json' => json_encode(['window_days' => 28], JSON_THROW_ON_ERROR),
            'public_runtime_readback_json' => json_encode([
                'status' => 'healthy',
                'raw_body' => 'raw-response-body',
            ], JSON_THROW_ON_ERROR),
            'gsc_funnel_evidence_state_json' => json_encode(['quality_state' => 'final'], JSON_THROW_ON_ERROR),
            'owner_actor_json' => json_encode(['email' => 'private@example.test'], JSON_THROW_ON_ERROR),
            'current_state' => 'observing',
            'transition_sequence' => 1,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    /** @param list<string> $permissions */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'seo_'.Str::lower(Str::random(6)),
            'email' => 'seo_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);
        $role = Role::query()->create([
            'name' => 'role_'.Str::lower(Str::random(8)),
            'description' => null,
        ]);

        foreach ($permissions as $permissionName) {
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
