<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\ContentWorkspacePage;
use App\Filament\Ops\Resources\ScaleRegistryResource;
use App\Filament\Ops\Resources\ScaleSlugResource;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Scale\PublicScaleCatalogCache;
use App\Services\Scale\ScaleRegistry;
use App\Services\Scale\ScaleRegistryWriter;
use App\Support\CacheKeys;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class ScaleRegistryV2ManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        Config::set('fap.scales_registry.use_v2', true);
        Config::set('content_packs.public_scale_cache_store', 'array');
        Cache::flush();
        Cache::store('array')->flush();
    }

    public function test_ops_and_public_runtime_read_v2_before_legacy(): void
    {
        $writer = app(ScaleRegistryWriter::class);
        $writer->upsertScale($this->scalePayload('V2_SOURCE', 0, 'v2-source', ['v2-source', 'v2-history']));

        DB::table('scales_registry')
            ->where('org_id', 0)
            ->where('code', 'V2_SOURCE')
            ->update([
                'primary_slug' => 'legacy-drift',
                'slugs_json' => json_encode(['legacy-drift']),
            ]);

        $this->setOpsContext(0);
        $opsRow = ScaleRegistryResource::getEloquentQuery()
            ->where('code', 'V2_SOURCE')
            ->firstOrFail();
        $this->assertSame('scales_registry_v2', $opsRow->getTable());
        $this->assertSame('v2-source', $opsRow->primary_slug);

        $runtime = app(ScaleRegistry::class);
        $this->assertSame('v2-source', $runtime->getByCode('V2_SOURCE', 0)['primary_slug'] ?? null);
        $this->assertSame('V2_SOURCE', $runtime->lookupBySlug('v2-source', 0, false)['code'] ?? null);
        $this->assertSame('V2_SOURCE', $runtime->lookupBySlug('v2-history', 0, true)['code'] ?? null);
        $this->assertSame(
            'v2-source',
            collect($runtime->listActivePublicForCatalog())->firstWhere('code', 'V2_SOURCE')['primary_slug'] ?? null,
        );
    }

    public function test_writer_atomically_updates_authority_projection_and_caches(): void
    {
        $cache = app(PublicScaleCatalogCache::class);
        $generationBefore = $cache->generation(0);
        Cache::put(CacheKeys::scaleRegistryByCode(0, 'WRITER_SAMPLE'), ['stale' => true]);
        Cache::put(CacheKeys::scaleRegistryBySlug(0, 'compat:writer-history'), ['stale' => true]);

        app(ScaleRegistryWriter::class)->upsertScale(
            $this->scalePayload('WRITER_SAMPLE', 0, 'writer-primary', ['writer-primary', 'writer-history'])
        );

        $this->assertDatabaseHas('scales_registry_v2', [
            'org_id' => 0,
            'code' => 'WRITER_SAMPLE',
            'primary_slug' => 'writer-primary',
        ]);
        $this->assertDatabaseHas('scales_registry', [
            'org_id' => 0,
            'code' => 'WRITER_SAMPLE',
            'primary_slug' => 'writer-primary',
        ]);
        $this->assertDatabaseHas('scale_slugs', [
            'org_id' => 0,
            'scale_code' => 'WRITER_SAMPLE',
            'slug' => 'writer-primary',
            'is_primary' => 1,
        ]);
        $this->assertDatabaseHas('scale_slugs', [
            'org_id' => 0,
            'scale_code' => 'WRITER_SAMPLE',
            'slug' => 'writer-history',
            'is_primary' => 0,
        ]);
        $this->assertNull(Cache::get(CacheKeys::scaleRegistryByCode(0, 'WRITER_SAMPLE')));
        $this->assertNull(Cache::get(CacheKeys::scaleRegistryBySlug(0, 'compat:writer-history')));
        $this->assertSame($generationBefore + 1, $cache->generation(0));

        app(ScaleRegistryWriter::class)->upsertScale(
            $this->scalePayload('WRITER_SAMPLE', 0, 'writer-next', ['writer-next', 'writer-primary'])
        );

        $this->assertDatabaseMissing('scale_slugs', [
            'org_id' => 0,
            'scale_code' => 'WRITER_SAMPLE',
            'slug' => 'writer-history',
        ]);
        $this->assertSame('writer-next', app(ScaleRegistry::class)->getByCode('WRITER_SAMPLE', 0)['primary_slug'] ?? null);
        $this->assertSame('WRITER_SAMPLE', app(ScaleRegistry::class)->lookupBySlug('writer-primary', 0, true)['code'] ?? null);
    }

    public function test_conflict_and_projection_failure_leave_no_half_written_state(): void
    {
        $writer = app(ScaleRegistryWriter::class);
        $writer->upsertScale($this->scalePayload('OWNER_A', 0, 'owner-a', ['owner-a', 'owned-alias']));
        $generationBefore = app(PublicScaleCatalogCache::class)->generation(0);

        try {
            $writer->upsertScale($this->scalePayload('OWNER_B', 0, 'owner-b', ['owner-b', 'owned-alias']));
            $this->fail('Expected the shared slug conflict to fail closed.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('already owned by OWNER_A', $exception->getMessage());
        }

        $this->assertDatabaseMissing('scales_registry_v2', ['org_id' => 0, 'code' => 'OWNER_B']);
        $this->assertDatabaseMissing('scales_registry', ['org_id' => 0, 'code' => 'OWNER_B']);
        $this->assertSame($generationBefore, app(PublicScaleCatalogCache::class)->generation(0));

        DB::statement(<<<'SQL'
CREATE TRIGGER reject_projection_insert
BEFORE INSERT ON scale_slugs
WHEN NEW.scale_code = 'ROLLBACK_SAMPLE'
BEGIN
    SELECT RAISE(ABORT, 'projection rejected');
END
SQL);

        try {
            $writer->upsertScale($this->scalePayload('ROLLBACK_SAMPLE', 0, 'rollback-sample', ['rollback-sample']));
            $this->fail('Expected projection failure to roll back the registry transaction.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('projection rejected', $exception->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS reject_projection_insert');
        }

        $this->assertDatabaseMissing('scales_registry_v2', ['org_id' => 0, 'code' => 'ROLLBACK_SAMPLE']);
        $this->assertDatabaseMissing('scales_registry', ['org_id' => 0, 'code' => 'ROLLBACK_SAMPLE']);
        $this->assertDatabaseMissing('scale_slugs', ['org_id' => 0, 'scale_code' => 'ROLLBACK_SAMPLE']);
        $this->assertSame($generationBefore, app(PublicScaleCatalogCache::class)->generation(0));
    }

    public function test_scale_management_is_contextual_and_slug_urls_are_read_only_compatibility_routes(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        $this->setOpsContext(0, $admin);

        $cards = Livewire::test(ContentWorkspacePage::class)->get('advancedContentCards');
        $this->assertSame('Scale Registry V2', $cards[0]['title'] ?? null);
        $this->assertStringContainsString('/ops/scale-registries', $cards[0]['index_url'] ?? '');
        $this->assertTrue((bool) ($cards[0]['can_create'] ?? false));

        $this->assertFalse(ScaleRegistryResource::shouldRegisterNavigation());
        $this->assertFalse(ScaleSlugResource::shouldRegisterNavigation());
        $this->assertFalse(ScaleSlugResource::canCreate());
        $this->assertFalse(ScaleSlugResource::canEdit(null));
        $this->assertFalse(ScaleSlugResource::canDelete(null));
        $this->assertTrue(app('router')->has('filament.ops.resources.scale-slugs.create'));
        $this->assertTrue(app('router')->has('filament.ops.resources.scale-slugs.edit'));
        $this->assertSame('Scale Registry V2', __('ops.custom_pages.content_workspace.cards.scale_registry', [], 'en'));
        $this->assertSame('量表注册表 V2', __('ops.custom_pages.content_workspace.cards.scale_registry', [], 'zh_CN'));
    }

    /** @return array<string, mixed> */
    private function scalePayload(string $code, int $orgId, string $primarySlug, array $slugs): array
    {
        return [
            'code' => $code,
            'org_id' => $orgId,
            'primary_slug' => $primarySlug,
            'slugs_json' => $slugs,
            'driver_type' => 'mbti',
            'default_locale' => 'en',
            'is_public' => $orgId === 0,
            'is_active' => true,
            'is_indexable' => true,
        ];
    }

    /** @param list<string> $permissions */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'scale_admin_'.Str::lower(Str::random(6)),
            'email' => 'scale_admin_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);
        $role = Role::query()->create([
            'name' => 'scale_management_'.Str::lower(Str::random(6)),
            'guard_name' => (string) config('admin.guard', 'admin'),
        ]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['guard_name' => (string) config('admin.guard', 'admin')],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    private function setOpsContext(int $orgId, ?AdminUser $admin = null): void
    {
        app()->instance('request', Request::create('/ops/scale-registries', 'GET'));
        $context = app(OrgContext::class);
        $context->set($orgId, (int) ($admin?->id ?? 9001), 'admin');
        app()->instance(OrgContext::class, $context);
    }
}
