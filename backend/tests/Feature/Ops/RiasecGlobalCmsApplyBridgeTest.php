<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\RiasecGlobalCmsApplyPage;
use App\Models\AdminUser;
use App\Models\LandingSurface;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Ops\RiasecGlobalCmsApplyBridge;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

final class RiasecGlobalCmsApplyBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('admin.totp.enabled', false);
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_owner_can_open_bridge_without_an_organization_and_non_owner_cannot(): void
    {
        $owner = $this->adminWithPermissions([PermissionNames::ADMIN_OWNER]);

        $this->actingAs($owner, (string) config('admin.guard', 'admin'))
            ->get(route('filament.ops.pages.riasec-global-cms-apply'))
            ->assertOk()
            ->assertSee('RIASEC Global CMS Apply Bridge')
            ->assertSee(RiasecGlobalCmsApplyBridge::TARGET_PACKAGE_SHA256);

        $writer = $this->adminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);

        $this->actingAs($writer, (string) config('admin.guard', 'admin'))
            ->get(route('filament.ops.pages.riasec-global-cms-apply'))
            ->assertRedirect('/ops/login');
    }

    public function test_exact_package_preflight_apply_idempotency_and_rollback_are_audited(): void
    {
        $owner = $this->adminWithPermissions([PermissionNames::ADMIN_OWNER]);
        $this->seedBeforeSurface();
        $this->actingAs($owner, (string) config('admin.guard', 'admin'));

        $component = Livewire::test(RiasecGlobalCmsApplyPage::class)
            ->set('beforeSnapshotJson', $this->fixture('current_public_readback.json'))
            ->set('targetPackageJson', $this->fixture('target_internal_update.json'))
            ->call('preflightExactPackage')
            ->assertSet('receipt.status', 'ready_to_apply')
            ->call('applyExactPackage')
            ->assertSet('receipt.status', 'applied');

        $surface = $this->surface();
        $this->assertSame('Free Holland Career Interest Test (RIASEC) | FermatMind', $surface->title);
        $this->assertSame('Free Holland Career Interest Test (RIASEC)', data_get($surface->payload_json, 'h1_or_hero_title'));
        $this->assertTrue((bool) $surface->is_indexable);

        $this->assertDatabaseHas('audit_logs', [
            'org_id' => 0,
            'actor_admin_id' => (int) $owner->id,
            'action' => 'riasec_global_cms_apply',
            'target_type' => 'landing_surface',
            'target_id' => RiasecGlobalCmsApplyBridge::SURFACE_KEY,
            'result' => 'applied',
        ]);

        $component
            ->call('applyExactPackage')
            ->assertSet('receipt.status', 'already_applied')
            ->call('rollbackExactPackage')
            ->assertSet('receipt.status', 'rolled_back');

        $this->assertSame(
            'Free Holland Career Interest Test | RIASEC Full Report',
            $this->surface()->title,
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'riasec_global_cms_rollback',
            'target_id' => RiasecGlobalCmsApplyBridge::SURFACE_KEY,
            'result' => 'rolled_back',
        ]);
    }

    public function test_hash_mismatch_and_surface_drift_fail_closed_without_an_audit_write(): void
    {
        $owner = $this->adminWithPermissions([PermissionNames::ADMIN_OWNER]);
        $this->seedBeforeSurface();
        $this->setPublicOrgContext($owner);
        $bridge = app(RiasecGlobalCmsApplyBridge::class);

        try {
            $bridge->apply(
                $this->fixture('current_public_readback.json'),
                $this->fixture('target_internal_update.json')."\n",
                (int) $owner->id,
            );
            $this->fail('Expected the target package hash mismatch to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Target package SHA-256 mismatch.', $exception->getMessage());
        }

        $surface = $this->surface();
        $surface->title = 'Unexpected external edit';
        $surface->save();

        try {
            $bridge->apply(
                $this->fixture('current_public_readback.json'),
                $this->fixture('target_internal_update.json'),
                (int) $owner->id,
            );
            $this->fail('Expected current surface drift to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Pre-apply surface drift detected. No write was performed.', $exception->getMessage());
        }

        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame('Unexpected external edit', $this->surface()->title);
    }

    public function test_positive_tenant_context_cannot_use_the_org_zero_bridge(): void
    {
        $owner = $this->adminWithPermissions([PermissionNames::ADMIN_OWNER]);
        $this->seedBeforeSurface();

        $context = app(OrgContext::class);
        $context->set(77, (int) $owner->id, 'admin', null, OrgContext::KIND_TENANT);
        app()->instance(OrgContext::class, $context);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The bridge requires the unselected org-0 Ops authority context.');

        app(RiasecGlobalCmsApplyBridge::class)->preflight(
            $this->fixture('current_public_readback.json'),
            $this->fixture('target_internal_update.json'),
        );
    }

    private function seedBeforeSurface(): LandingSurface
    {
        $before = json_decode($this->fixture('current_public_readback.json'), true, 512, JSON_THROW_ON_ERROR);
        $surface = $before['surface'];

        return LandingSurface::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'surface_key' => $surface['surface_key'],
            'locale' => $surface['locale'],
            'title' => $surface['title'],
            'description' => $surface['description'],
            'schema_version' => $surface['schema_version'],
            'payload_json' => $surface['payload_json'],
            'status' => $surface['status'],
            'is_public' => $surface['is_public'],
            'is_indexable' => $surface['is_indexable'],
            'published_at' => $surface['published_at'],
            'scheduled_at' => $surface['scheduled_at'],
        ]);
    }

    private function surface(): LandingSurface
    {
        return LandingSurface::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('surface_key', RiasecGlobalCmsApplyBridge::SURFACE_KEY)
            ->where('locale', RiasecGlobalCmsApplyBridge::LOCALE)
            ->firstOrFail();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/Seo/RiasecLandingCmsExperiment01/'.$name));
    }

    private function setPublicOrgContext(AdminUser $admin): void
    {
        $context = app(OrgContext::class);
        $context->set(0, (int) $admin->id, 'admin', null, OrgContext::KIND_PUBLIC);
        app()->instance(OrgContext::class, $context);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function adminWithPermissions(array $permissionNames): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'riasec_bridge_'.Str::lower(Str::random(6)),
            'email' => 'riasec_bridge_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'riasec_bridge_'.Str::lower(Str::random(8)),
            'description' => null,
        ]);

        foreach ($permissionNames as $permissionName) {
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
