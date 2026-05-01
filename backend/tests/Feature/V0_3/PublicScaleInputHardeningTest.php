<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\Content\ContentPackResolver as PathContentPackResolver;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PublicScaleInputHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_array_locale_is_rejected_without_crashing_public_companion_links(): void
    {
        $response = $this->getJson('/api/v0.5/career/first-wave/recommendations/mbti/intj/companion-links?locale[]=zh');

        $response->assertStatus(422);
        $this->assertStringContainsString('locale', json_encode($response->json(), JSON_THROW_ON_ERROR));
    }

    public function test_scale_questions_reject_malformed_locale_and_region_inputs(): void
    {
        $this->seedDefaultScales();

        $this->getJson('/api/v0.3/scales/MBTI/questions?locale[]=zh')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['locale']);

        $this->getJson('/api/v0.3/scales/MBTI/questions?region=../../content')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['region']);
    }

    public function test_non_public_scale_lookup_show_and_questions_are_denied(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->insertPrivateScale('PRIVATE_SCALE', 'private-scale');

        $this->getJson('/api/v0.3/scales/PRIVATE_SCALE')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.3/scales/PRIVATE_SCALE/questions')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.3/scales/lookup?slug=private-scale')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_public_mbti_lookup_cache_uses_bounded_normalized_locale_variants(): void
    {
        $this->seedDefaultScales();
        Config::set('content_packs.mbti_response_cache_store', 'array');
        Cache::flush();
        Cache::store('array')->flush();

        $first = $this->getJson('/api/v0.3/scales/lookup?slug=mbti-test&locale=en_US');
        $first->assertStatus(200);
        $first->assertHeader('X-FAP-Cache', 'miss');
        $first->assertJsonPath('locale', 'en-US');

        $second = $this->getJson('/api/v0.3/scales/lookup?slug=mbti-test&locale=en-US');
        $second->assertStatus(200);
        $second->assertHeader('X-FAP-Cache', 'hit');
        $second->assertJsonPath('locale', 'en-US');

        $longKey = CacheKeys::mbtiLookup(0, str_repeat('mbti-test-', 30), str_repeat('en-US-', 30), true);
        $this->assertLessThanOrEqual(220, strlen($longKey));
    }

    public function test_content_pack_path_segments_reject_traversal(): void
    {
        $resolver = new PathContentPackResolver(base_path('../content_packages'));

        $this->expectException(\RuntimeException::class);

        $resolver->resolve('MBTI', '../CN_MAINLAND', 'zh-CN', 'v0.3');
    }

    public function test_valid_public_questions_flow_still_works(): void
    {
        $this->seedDefaultScales();

        $response = $this->getJson('/api/v0.3/scales/MBTI/questions?locale=zh-CN&region=CN_MAINLAND');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('locale', 'zh-CN');
        $response->assertJsonPath('region', 'CN_MAINLAND');
        $this->assertIsArray($response->json('questions.items'));
    }

    private function seedDefaultScales(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('fap:scales:sync-slugs');
    }

    private function insertPrivateScale(string $code, string $slug): void
    {
        DB::table('scales_registry')->insert([
            'code' => $code,
            'org_id' => 0,
            'primary_slug' => $slug,
            'slugs_json' => json_encode([$slug]),
            'driver_type' => 'mbti',
            'default_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'default_region' => 'CN_MAINLAND',
            'default_locale' => 'zh-CN',
            'default_dir_version' => 'MBTI-CN-v0.3',
            'capabilities_json' => json_encode(['questions' => true]),
            'view_policy_json' => json_encode(['report' => 'private']),
            'commercial_json' => json_encode(['price_tier' => 'PRIVATE']),
            'seo_schema_json' => json_encode(['name' => 'Private scale']),
            'is_public' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('scale_slugs')->insert([
            'org_id' => 0,
            'slug' => $slug,
            'scale_code' => $code,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
