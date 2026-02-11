<?php

namespace Tests\Feature\V0_3;

use App\Services\Scale\ScaleRegistryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScalesLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_by_slug_hits_mbti(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('fap:scales:sync-slugs');

        $response = $this->getJson('/api/v0.3/scales/lookup?slug=mbti-test');
        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'scale_code' => 'MBTI',
            'primary_slug' => 'mbti-test',
        ]);
    }

    public function test_lookup_unknown_slug_returns_not_found(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $response = $this->getJson('/api/v0.3/scales/lookup?slug=unknown-slug');
        $response->assertStatus(404);
        $response->assertJson([
            'ok' => false,
            'error_code' => 'NOT_FOUND',
        ]);
    }

    public function test_lookup_after_inserting_scale(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $writer = app(ScaleRegistryWriter::class);

        $scale = $writer->upsertScale([
            'code' => 'IQ_RAVEN',
            'org_id' => 0,
            'primary_slug' => 'raven-iq-test',
            'slugs_json' => [
                'raven-iq-test',
                'raven-matrices',
            ],
            'driver_type' => 'iq',
            'default_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.2.1-TEST',
            'default_region' => 'CN_MAINLAND',
            'default_locale' => 'zh-CN',
            'default_dir_version' => 'IQ-RAVEN-v0.1.0',
            'capabilities_json' => [
                'content_graph' => true,
            ],
            'view_policy_json' => [
                'report' => 'public',
            ],
            'commercial_json' => [
                'price_tier' => 'FREE',
            ],
            'seo_schema_json' => [
                '@context' => 'https://schema.org',
                '@type' => 'Quiz',
                'name' => 'Raven IQ Test',
            ],
            'is_public' => true,
            'is_active' => true,
        ]);
        $writer->syncSlugsForScale($scale);

        $response = $this->getJson('/api/v0.3/scales/lookup?slug=raven-iq-test');
        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'scale_code' => 'IQ_RAVEN',
            'primary_slug' => 'raven-iq-test',
        ]);
    }

    public function test_slug_unique_constraint_enforced(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        if (!Schema::hasTable('scale_slugs')) {
            $this->markTestSkipped('scale_slugs missing');
        }

        DB::table('scale_slugs')->insert([
            'org_id' => 0,
            'slug' => 'unique-slug',
            'scale_code' => 'MBTI',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('scale_slugs')->insert([
            'org_id' => 0,
            'slug' => 'unique-slug',
            'scale_code' => 'IQ_RAVEN',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
