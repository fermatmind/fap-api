<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ScaleRegistry as ScaleRegistryModel;
use App\Services\Scale\ScaleRegistry as ScaleRegistryService;
use App\Services\Scale\ScaleRegistryWriter;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ScaleRegistryTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_scale_registry_model_no_longer_bypasses_tenant_scope(): void
    {
        $this->seedScale('TENANT_ALPHA_PRIVATE', 101, 'tenant-alpha-private', false);
        $this->seedScale('TENANT_BRAVO_PRIVATE', 202, 'tenant-bravo-private', false);

        $this->setHttpContext(101, '/api/_scale-registry-scope');

        $codes = ScaleRegistryModel::query()->pluck('code')->all();

        $this->assertContains('TENANT_ALPHA_PRIVATE', $codes);
        $this->assertNotContains('TENANT_BRAVO_PRIVATE', $codes);
    }

    public function test_scale_registry_service_blocks_cross_tenant_and_global_slug_resolution_for_tenant_reads(): void
    {
        $this->seedScale('GLOBAL_PUBLIC_SCALE', 0, 'global-public-scale', true);
        $this->seedScale('TENANT_ALPHA_PRIVATE', 101, 'tenant-alpha-private', false);
        $this->seedScale('TENANT_BRAVO_PRIVATE', 202, 'tenant-bravo-private', false);

        /** @var ScaleRegistryService $registry */
        $registry = app(ScaleRegistryService::class);

        $own = $registry->lookupBySlug('tenant-alpha-private', 101, true);
        $this->assertNotNull($own);
        $this->assertSame(101, (int) ($own['org_id'] ?? -1));

        $crossTenant = $registry->lookupBySlug('tenant-bravo-private', 101, true);
        $this->assertNull($crossTenant);

        $global = $registry->lookupBySlug('global-public-scale', 101, true);
        $this->assertNull($global);
    }

    public function test_scale_registry_service_list_visible_contains_only_tenant_rows_for_tenant_reads(): void
    {
        $this->seedScale('GLOBAL_PUBLIC_SCALE', 0, 'global-public-scale', true);
        $this->seedScale('GLOBAL_PRIVATE_SCALE', 0, 'global-private-scale', false);
        $this->seedScale('TENANT_ALPHA_PRIVATE', 101, 'tenant-alpha-private', false);
        $this->seedScale('TENANT_BRAVO_PRIVATE', 202, 'tenant-bravo-private', false);

        /** @var ScaleRegistryService $registry */
        $registry = app(ScaleRegistryService::class);
        $rows = $registry->listVisible(101);
        $codes = array_values(array_map(static fn (array $row): string => (string) ($row['code'] ?? ''), $rows));

        $this->assertContains('TENANT_ALPHA_PRIVATE', $codes);
        $this->assertNotContains('GLOBAL_PUBLIC_SCALE', $codes);
        $this->assertNotContains('GLOBAL_PRIVATE_SCALE', $codes);
        $this->assertNotContains('TENANT_BRAVO_PRIVATE', $codes);
    }

    public function test_scales_registry_v2_supports_same_code_across_tenants(): void
    {
        if (!Schema::hasTable('scales_registry_v2')) {
            $this->markTestSkipped('scales_registry_v2 missing');
        }

        /** @var ScaleRegistryWriter $writer */
        $writer = app(ScaleRegistryWriter::class);

        $tenantAlpha = $writer->upsertScale([
            'code' => 'SHARED_CODE',
            'org_id' => 101,
            'primary_slug' => 'shared-alpha',
            'slugs_json' => ['shared-alpha'],
            'driver_type' => 'mbti',
            'default_locale' => 'zh-CN',
            'is_public' => false,
            'is_active' => true,
        ]);
        $writer->syncSlugsForScale($tenantAlpha);

        $tenantBravo = $writer->upsertScale([
            'code' => 'SHARED_CODE',
            'org_id' => 202,
            'primary_slug' => 'shared-bravo',
            'slugs_json' => ['shared-bravo'],
            'driver_type' => 'mbti',
            'default_locale' => 'zh-CN',
            'is_public' => false,
            'is_active' => true,
        ]);
        $writer->syncSlugsForScale($tenantBravo);

        $this->assertSame(2, DB::table('scales_registry_v2')->where('code', 'SHARED_CODE')->count());

        /** @var ScaleRegistryService $registry */
        $registry = app(ScaleRegistryService::class);

        $alphaByCode = $registry->getByCode('SHARED_CODE', 101);
        $this->assertNotNull($alphaByCode);
        $this->assertSame(101, (int) ($alphaByCode['org_id'] ?? -1));

        $bravoByCode = $registry->getByCode('SHARED_CODE', 202);
        $this->assertNotNull($bravoByCode);
        $this->assertSame(202, (int) ($bravoByCode['org_id'] ?? -1));

        $alphaBySlug = $registry->lookupBySlug('shared-alpha', 101, true);
        $this->assertNotNull($alphaBySlug);
        $this->assertSame(101, (int) ($alphaBySlug['org_id'] ?? -1));

        $crossTenant = $registry->lookupBySlug('shared-bravo', 101, true);
        $this->assertNull($crossTenant);
    }

    private function seedScale(string $code, int $orgId, string $primarySlug, bool $isPublic): void
    {
        DB::table('scales_registry')->insert([
            'code' => $code,
            'org_id' => $orgId,
            'primary_slug' => $primarySlug,
            'slugs_json' => json_encode([$primarySlug], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'driver_type' => 'mbti',
            'default_pack_id' => null,
            'default_region' => 'CN_MAINLAND',
            'default_locale' => 'zh-CN',
            'default_dir_version' => null,
            'assessment_driver' => null,
            'capabilities_json' => null,
            'view_policy_json' => null,
            'commercial_json' => null,
            'seo_schema_json' => null,
            'seo_i18n_json' => null,
            'content_i18n_json' => null,
            'report_summary_i18n_json' => null,
            'is_public' => $isPublic ? 1 : 0,
            'is_active' => 1,
            'is_indexable' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('scale_slugs')->insert([
            'org_id' => $orgId,
            'slug' => $primarySlug,
            'scale_code' => $code,
            'is_primary' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function setHttpContext(int $orgId, string $path): void
    {
        $request = Request::create($path, 'GET');
        app()->instance('request', $request);

        $context = app(OrgContext::class);
        $context->set($orgId, 9001, 'admin');
        app()->instance(OrgContext::class, $context);
    }
}
