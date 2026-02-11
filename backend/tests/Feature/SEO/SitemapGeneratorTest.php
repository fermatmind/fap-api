<?php

namespace Tests\Feature\SEO;

use App\Services\SEO\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SitemapGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_only_includes_public_global_scales(): void
    {
        config(['app.url' => 'https://fermatmind.com']);

        $now = now();

        DB::table('scales_registry')->insert([
            [
                'code' => 'P0_PUBLIC_GLOBAL',
                'org_id' => 0,
                'primary_slug' => 'public-global',
                'slugs_json' => json_encode(['public-global-alt']),
                'driver_type' => 'MBTI',
                'default_pack_id' => null,
                'default_region' => null,
                'default_locale' => null,
                'default_dir_version' => null,
                'capabilities_json' => null,
                'view_policy_json' => null,
                'commercial_json' => null,
                'seo_schema_json' => null,
                'is_public' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'P0_PRIVATE_GLOBAL',
                'org_id' => 0,
                'primary_slug' => 'private-global',
                'slugs_json' => json_encode(['private-global-alt']),
                'driver_type' => 'MBTI',
                'default_pack_id' => null,
                'default_region' => null,
                'default_locale' => null,
                'default_dir_version' => null,
                'capabilities_json' => null,
                'view_policy_json' => null,
                'commercial_json' => null,
                'seo_schema_json' => null,
                'is_public' => 0,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'P0_PUBLIC_TENANT',
                'org_id' => 9,
                'primary_slug' => 'tenant-public',
                'slugs_json' => json_encode(['tenant-public-alt']),
                'driver_type' => 'MBTI',
                'default_pack_id' => null,
                'default_region' => null,
                'default_locale' => null,
                'default_dir_version' => null,
                'capabilities_json' => null,
                'view_policy_json' => null,
                'commercial_json' => null,
                'seo_schema_json' => null,
                'is_public' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $payload = app(SitemapGenerator::class)->generate();

        $slugList = (array) ($payload['slug_list'] ?? []);
        sort($slugList, SORT_STRING);

        $this->assertSame(['public-global', 'public-global-alt'], $slugList);

        $xml = (string) ($payload['xml'] ?? '');
        $baseUrl = rtrim((string) config('app.url'), '/');
        $this->assertStringContainsString($baseUrl . '/tests/public-global', $xml);
        $this->assertStringContainsString($baseUrl . '/tests/public-global-alt', $xml);
        $this->assertStringNotContainsString($baseUrl . '/tests/private-global', $xml);
        $this->assertStringNotContainsString($baseUrl . '/tests/private-global-alt', $xml);
        $this->assertStringNotContainsString($baseUrl . '/tests/tenant-public', $xml);
        $this->assertStringNotContainsString($baseUrl . '/tests/tenant-public-alt', $xml);
    }
}
