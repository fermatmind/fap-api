<?php

namespace Tests\Feature\SEO;

use App\Models\PersonalityProfile;
use App\Services\Cms\PersonalityProfileSeoService;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SitemapGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_only_includes_public_global_scales(): void
    {
        config(['services.seo.tests_url_prefix' => 'https://fermatmind.com/tests/']);

        $now = now();
        // Isolate this test from migration-seeded default scales.
        DB::table('scales_registry')->delete();

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
        $prefix = rtrim((string) config('services.seo.tests_url_prefix'), '/').'/';
        $this->assertStringContainsString($prefix.'public-global', $xml);
        $this->assertStringContainsString($prefix.'public-global-alt', $xml);
        $this->assertStringNotContainsString($prefix.'private-global', $xml);
        $this->assertStringNotContainsString($prefix.'private-global-alt', $xml);
        $this->assertStringNotContainsString($prefix.'tenant-public', $xml);
        $this->assertStringNotContainsString($prefix.'tenant-public-alt', $xml);
    }

    public function test_generate_includes_only_indexable_global_personality_urls_with_locale_aware_paths(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $eligibleEn = $this->createPersonalityProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 3, 7, 9, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 7, 10, 0, 0, 'UTC'),
        ]);

        $eligibleZh = $this->createPersonalityProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'zh-CN',
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 3, 7, 11, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 7, 12, 0, 0, 'UTC'),
        ]);

        $this->createPersonalityProfile([
            'type_code' => 'ENTJ',
            'slug' => 'entj',
            'locale' => 'en',
            'status' => 'draft',
            'updated_at' => Carbon::create(2026, 3, 7, 12, 30, 0, 'UTC'),
        ]);
        $this->createPersonalityProfile([
            'type_code' => 'ENTP',
            'slug' => 'entp',
            'locale' => 'en',
            'is_public' => false,
            'updated_at' => Carbon::create(2026, 3, 7, 12, 45, 0, 'UTC'),
        ]);
        $this->createPersonalityProfile([
            'type_code' => 'INFJ',
            'slug' => 'infj',
            'locale' => 'en',
            'is_indexable' => false,
            'updated_at' => Carbon::create(2026, 3, 7, 13, 0, 0, 'UTC'),
        ]);
        $this->createPersonalityProfile([
            'org_id' => 9,
            'type_code' => 'INFP',
            'slug' => 'infp',
            'locale' => 'en',
            'updated_at' => Carbon::create(2026, 3, 7, 13, 15, 0, 'UTC'),
        ]);
        $this->createPersonalityProfile([
            'scale_code' => 'DISC',
            'type_code' => 'DISC-I',
            'slug' => 'disc-i',
            'locale' => 'en',
            'updated_at' => Carbon::create(2026, 3, 7, 13, 30, 0, 'UTC'),
        ]);
        $this->createPersonalityProfile([
            'type_code' => 'ISFJ',
            'slug' => 'isfj',
            'locale' => 'fr',
            'updated_at' => Carbon::create(2026, 3, 7, 13, 45, 0, 'UTC'),
        ]);

        $payload = app(SitemapGenerator::class)->generate();
        $xml = (string) ($payload['xml'] ?? '');
        $this->assertSame(2, DB::table('personality_profiles')
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->where('status', 'published')
            ->where('is_public', 1)
            ->where('is_indexable', 1)
            ->whereIn('locale', PersonalityProfile::SUPPORTED_LOCALES)
            ->where(function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->count());

        $this->assertStringContainsString('https://staging.fermatmind.com/en/personality', $xml);
        $this->assertStringContainsString('https://staging.fermatmind.com/zh/personality', $xml);
        $this->assertStringContainsString('https://staging.fermatmind.com/en/personality/intj', $xml);
        $this->assertStringContainsString('https://staging.fermatmind.com/zh/personality/intj', $xml);

        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/personality/entj', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/personality/entp', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/personality/infj', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/personality/infp', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/personality/disc-i', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/fr/personality/isfj', $xml);

        $seoService = app(PersonalityProfileSeoService::class);

        $this->assertSame(
            data_get($seoService->buildMeta($eligibleEn), 'canonical'),
            data_get($seoService->buildJsonLd($eligibleEn), 'mainEntityOfPage')
        );
        $this->assertSame(
            data_get($seoService->buildMeta($eligibleZh), 'canonical'),
            data_get($seoService->buildJsonLd($eligibleZh), 'mainEntityOfPage')
        );
        $this->assertSame('AboutPage', data_get($seoService->buildJsonLd($eligibleEn), '@type'));
        $this->assertStringContainsString(data_get($seoService->buildMeta($eligibleEn), 'canonical'), $xml);
        $this->assertStringContainsString(data_get($seoService->buildMeta($eligibleZh), 'canonical'), $xml);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPersonalityProfile(array $overrides = []): PersonalityProfile
    {
        /** @var PersonalityProfile */
        return PersonalityProfile::query()->create(array_merge([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'INTJ Personality Type',
            'subtitle' => 'Strategic and future-oriented.',
            'excerpt' => 'Explore INTJ traits, strengths, and growth.',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 3, 7, 8, 0, 0, 'UTC'),
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'created_at' => Carbon::create(2026, 3, 7, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 7, 8, 0, 0, 'UTC'),
        ], $overrides));
    }
}
