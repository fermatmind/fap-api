<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Scale\ScaleRegistry as ScaleRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TenantStrictNoGlobalFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_tenant_strict_flag_defaults_to_enabled(): void
    {
        $this->assertTrue((bool) config('fap.features.tenant_strict_v2'));
    }

    public function test_tenant_reads_do_not_fallback_to_global_public_scale(): void
    {
        $this->seedScale('GLOBAL_PUBLIC_SCALE', 0, 'global-public-scale', true);

        /** @var ScaleRegistryService $registry */
        $registry = app(ScaleRegistryService::class);

        $byCode = $registry->getByCode('GLOBAL_PUBLIC_SCALE', 101);
        $this->assertNull($byCode);

        $bySlug = $registry->lookupBySlug('global-public-scale', 101, true);
        $this->assertNull($bySlug);
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
}
