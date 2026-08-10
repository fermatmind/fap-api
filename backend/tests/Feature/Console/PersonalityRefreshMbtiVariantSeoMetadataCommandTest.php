<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSeoMeta;
use App\Services\Cms\MbtiSeoFieldOverrideRevisionService;
use App\Services\Cms\PersonalityPublicReadModelCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

final class PersonalityRefreshMbtiVariantSeoMetadataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_complete_64_variant_scope_without_writes(): void
    {
        $this->createMbtiVariantMatrix();

        $this->artisan('personality:refresh-mbti-variant-seo-metadata', [
            '--dry-run' => true,
            '--assert-complete' => true,
        ])
            ->expectsOutputToContain('expected_variants=64')
            ->expectsOutputToContain('variants_scanned=64')
            ->expectsOutputToContain('metadata_changes=64')
            ->expectsOutputToContain('writes_committed=0')
            ->assertExitCode(0);

        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->withoutGlobalScopes()->count());
    }

    public function test_command_refreshes_all_variant_seo_metadata_without_touching_canonical_or_robots(): void
    {
        $variants = $this->createMbtiVariantMatrix();
        PersonalityProfileVariantSeoMeta::query()->create([
            'personality_profile_variant_id' => (int) $variants['zh-CN|INFP-T']->id,
            'seo_title' => 'Old INFP-T title',
            'seo_description' => 'Old INFP-T description',
            'canonical_url' => 'https://fermatmind.com/zh/personality/infp-t',
            'og_title' => 'Old OG title',
            'og_description' => 'Old OG description',
            'og_image_url' => null,
            'twitter_title' => 'Old Twitter title',
            'twitter_description' => 'Old Twitter description',
            'twitter_image_url' => null,
            'robots' => 'index,follow',
            'jsonld_overrides_json' => null,
        ]);

        $this->artisan('personality:refresh-mbti-variant-seo-metadata', [
            '--assert-complete' => true,
        ])
            ->expectsOutputToContain('expected_variants=64')
            ->expectsOutputToContain('variants_scanned=64')
            ->expectsOutputToContain('writes_committed=64')
            ->expectsOutputToContain('missing_variants=0')
            ->assertExitCode(0);

        $this->assertSame(64, PersonalityProfileVariantSeoMeta::query()->withoutGlobalScopes()->count());

        $infpT = PersonalityProfileVariantSeoMeta::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_variant_id', (int) $variants['zh-CN|INFP-T']->id)
            ->firstOrFail();

        $this->assertSame('INFP-T 调停者人格：特点、适合职业、爱情与稀有度', $infpT->seo_title);
        $this->assertStringContainsString('A/T 区别', (string) $infpT->seo_description);
        $this->assertStringContainsString('核心特点', (string) $infpT->seo_description);
        $this->assertStringContainsString('爱情关系', (string) $infpT->seo_description);
        $this->assertStringContainsString('适合职业', (string) $infpT->seo_description);
        $this->assertStringContainsString('稀有度', (string) $infpT->seo_description);
        $this->assertSame($infpT->seo_title, $infpT->og_title);
        $this->assertSame($infpT->seo_description, $infpT->og_description);
        $this->assertSame($infpT->seo_title, $infpT->twitter_title);
        $this->assertSame($infpT->seo_description, $infpT->twitter_description);
        $this->assertSame('https://fermatmind.com/zh/personality/infp-t', $infpT->canonical_url);
        $this->assertSame('index,follow', $infpT->robots);

        $enfpA = PersonalityProfileVariantSeoMeta::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_variant_id', (int) $variants['en|ENFP-A']->id)
            ->firstOrFail();

        $this->assertSame('ENFP-A Campaigner Personality: Traits, Careers, Love & Rarity', $enfpA->seo_title);
        $this->assertStringContainsString('traits', (string) $enfpA->seo_description);
        $this->assertStringContainsString('A/T differences', (string) $enfpA->seo_description);
        $this->assertStringContainsString('relationships', (string) $enfpA->seo_description);
        $this->assertStringContainsString('career fit', (string) $enfpA->seo_description);
        $this->assertStringContainsString('rarity', (string) $enfpA->seo_description);
        $this->assertSame($enfpA->seo_title, $enfpA->og_title);
        $this->assertSame($enfpA->seo_description, $enfpA->twitter_description);
    }

    public function test_assert_complete_fails_when_selected_variant_scope_is_missing(): void
    {
        $profile = $this->createProfile('en', 'INFP', 'Mediator');
        $this->createVariant($profile, 'INFP-A');

        $this->artisan('personality:refresh-mbti-variant-seo-metadata', [
            '--locale' => ['en'],
            '--type' => ['INFP'],
            '--dry-run' => true,
            '--assert-complete' => true,
        ])
            ->expectsOutputToContain('expected_variants=2')
            ->expectsOutputToContain('variants_scanned=1')
            ->expectsOutputToContain('missing_variants=1')
            ->assertExitCode(1);
    }

    public function test_promoted_live_marker_protects_only_seo_title_and_invalidates_cache_for_other_refreshes(): void
    {
        $profile = $this->createProfile('zh-CN', 'INTP', '逻辑学家');
        $variant = $this->createVariant($profile, 'INTP-A');
        $seoMeta = $this->createSeoMeta($variant, 'Promoted query-owner title');
        $this->createOverrideMarker(
            $profile,
            $variant,
            $seoMeta,
            MbtiSeoFieldOverrideRevisionService::STATUS_PROMOTED_LIVE,
            'Original title',
            'Promoted query-owner title',
        );
        $cache = app(PersonalityPublicReadModelCache::class);
        $versionBefore = $cache->versionToken('INTP-A', 'zh-CN', 0, PersonalityProfile::SCALE_CODE_MBTI);

        $this->artisan('personality:refresh-mbti-variant-seo-metadata', [
            '--locale' => ['zh-CN'],
            '--type' => ['INTP'],
        ])
            ->expectsOutputToContain('metadata_changes=1')
            ->expectsOutputToContain('writes_committed=1')
            ->expectsOutputToContain('protected_override_count=1')
            ->expectsOutputToContain('protected_fields=["seo_title"]')
            ->expectsOutputToContain('cache_invalidations=1')
            ->assertExitCode(0);

        $seoMeta->refresh();
        $this->assertSame('Promoted query-owner title', $seoMeta->seo_title);
        $this->assertSame('INTP-A 逻辑学家人格：特点、适合职业、爱情与稀有度', $seoMeta->og_title);
        $this->assertNotSame(
            $versionBefore,
            $cache->versionToken('INTP-A', 'zh-CN', 0, PersonalityProfile::SCALE_CODE_MBTI),
        );
    }

    public function test_latest_rolled_back_marker_releases_seo_title_protection(): void
    {
        $profile = $this->createProfile('zh-CN', 'INTP', '逻辑学家');
        $variant = $this->createVariant($profile, 'INTP-A');
        $seoMeta = $this->createSeoMeta($variant, 'Original title');
        $this->createOverrideMarker(
            $profile,
            $variant,
            $seoMeta,
            MbtiSeoFieldOverrideRevisionService::STATUS_ROLLED_BACK,
            'Original title',
            'Promoted query-owner title',
        );

        $this->artisan('personality:refresh-mbti-variant-seo-metadata', [
            '--locale' => ['zh-CN'],
            '--type' => ['INTP'],
        ])
            ->expectsOutputToContain('protected_override_count=0')
            ->expectsOutputToContain('protected_fields=[]')
            ->expectsOutputToContain('writes_committed=1')
            ->assertExitCode(0);

        $this->assertSame(
            'INTP-A 逻辑学家人格：特点、适合职业、爱情与稀有度',
            (string) $seoMeta->fresh()?->seo_title,
        );
    }

    public function test_invalid_marker_checksum_fails_before_any_batch_write(): void
    {
        $firstProfile = $this->createProfile('zh-CN', 'ENFP', '竞选者');
        $firstVariant = $this->createVariant($firstProfile, 'ENFP-A');
        $firstSeo = $this->createSeoMeta($firstVariant, 'First untouched title');

        $profile = $this->createProfile('zh-CN', 'INTP', '逻辑学家');
        $variant = $this->createVariant($profile, 'INTP-A');
        $seoMeta = $this->createSeoMeta($variant, 'Promoted query-owner title');
        $revision = $this->createOverrideMarker(
            $profile,
            $variant,
            $seoMeta,
            MbtiSeoFieldOverrideRevisionService::STATUS_PROMOTED_LIVE,
            'Original title',
            'Promoted query-owner title',
        );
        $snapshot = $revision->snapshot_json;
        $snapshot['snapshot_sha256'] = str_repeat('0', 64);
        $revision->forceFill(['snapshot_json' => $snapshot])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('checksum mismatch');
        try {
            $this->artisan('personality:refresh-mbti-variant-seo-metadata', [
                '--locale' => ['zh-CN'],
                '--type' => ['ENFP', 'INTP'],
            ])->run();
        } finally {
            $this->assertSame('First untouched title', (string) $firstSeo->fresh()?->seo_title);
        }
    }

    public function test_marker_live_value_drift_fails_closed(): void
    {
        $profile = $this->createProfile('zh-CN', 'INTP', '逻辑学家');
        $variant = $this->createVariant($profile, 'INTP-A');
        $seoMeta = $this->createSeoMeta($variant, 'Unowned pre-change title');
        $this->createOverrideMarker(
            $profile,
            $variant,
            $seoMeta,
            MbtiSeoFieldOverrideRevisionService::STATUS_PROMOTED_LIVE,
            'Original title',
            'Promoted query-owner title',
            liveValue: 'Promoted query-owner title',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match the live SEO title');
        $this->artisan('personality:refresh-mbti-variant-seo-metadata', [
            '--locale' => ['zh-CN'],
            '--type' => ['INTP'],
            '--dry-run' => true,
        ])->run();
    }

    public function test_marker_target_identity_drift_fails_closed(): void
    {
        $profile = $this->createProfile('zh-CN', 'INTP', '逻辑学家');
        $variant = $this->createVariant($profile, 'INTP-A');
        $seoMeta = $this->createSeoMeta($variant, 'Promoted query-owner title');
        $revision = $this->createOverrideMarker(
            $profile,
            $variant,
            $seoMeta,
            MbtiSeoFieldOverrideRevisionService::STATUS_PROMOTED_LIVE,
            'Original title',
            'Promoted query-owner title',
        );
        $snapshot = $revision->snapshot_json;
        $snapshot['target']['runtime_type_code'] = 'INTJ-A';
        $snapshot['snapshot_sha256'] = app(MbtiSeoFieldOverrideRevisionService::class)->snapshotSha256($snapshot);
        $revision->forceFill(['snapshot_json' => $snapshot])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('target identity mismatch');
        $this->artisan('personality:refresh-mbti-variant-seo-metadata', [
            '--locale' => ['zh-CN'],
            '--type' => ['INTP'],
            '--dry-run' => true,
        ])->run();
    }

    public function test_cache_invalidation_failure_rolls_back_metadata_write(): void
    {
        $profile = $this->createProfile('zh-CN', 'INTP', '逻辑学家');
        $variant = $this->createVariant($profile, 'INTP-A');
        $seoMeta = $this->createSeoMeta($variant, 'Original title');
        app('cache.store');
        Cache::shouldReceive('forever')->once()->andThrow(new RuntimeException('cache unavailable'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database writes were rolled back');
        try {
            $this->artisan('personality:refresh-mbti-variant-seo-metadata', [
                '--locale' => ['zh-CN'],
                '--type' => ['INTP'],
            ])->run();
        } finally {
            $seoMeta->refresh();
            $this->assertSame('Original title', $seoMeta->seo_title);
            $this->assertSame('Old description', $seoMeta->seo_description);
        }
    }

    /**
     * @return array<string, PersonalityProfileVariant>
     */
    private function createMbtiVariantMatrix(): array
    {
        $typeNames = [
            'ENFJ' => ['en' => 'Protagonist', 'zh-CN' => '主人公'],
            'ENFP' => ['en' => 'Campaigner', 'zh-CN' => '竞选者'],
            'ENTJ' => ['en' => 'Commander', 'zh-CN' => '指挥官'],
            'ENTP' => ['en' => 'Debater', 'zh-CN' => '辩论家'],
            'ESFJ' => ['en' => 'Consul', 'zh-CN' => '执政官'],
            'ESFP' => ['en' => 'Entertainer', 'zh-CN' => '表演者'],
            'ESTJ' => ['en' => 'Executive', 'zh-CN' => '总经理'],
            'ESTP' => ['en' => 'Entrepreneur', 'zh-CN' => '企业家'],
            'INFJ' => ['en' => 'Advocate', 'zh-CN' => '提倡者'],
            'INFP' => ['en' => 'Mediator', 'zh-CN' => '调停者'],
            'INTJ' => ['en' => 'Architect', 'zh-CN' => '建筑师'],
            'INTP' => ['en' => 'Logician', 'zh-CN' => '逻辑学家'],
            'ISFJ' => ['en' => 'Defender', 'zh-CN' => '守卫者'],
            'ISFP' => ['en' => 'Adventurer', 'zh-CN' => '探险家'],
            'ISTJ' => ['en' => 'Logistician', 'zh-CN' => '物流师'],
            'ISTP' => ['en' => 'Virtuoso', 'zh-CN' => '鉴赏家'],
        ];
        $variants = [];

        foreach (PersonalityProfile::SUPPORTED_LOCALES as $locale) {
            foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
                $profile = $this->createProfile($locale, $typeCode, $typeNames[$typeCode][$locale]);
                foreach (['A', 'T'] as $variantCode) {
                    $runtimeTypeCode = $typeCode.'-'.$variantCode;
                    $variants[$locale.'|'.$runtimeTypeCode] = $this->createVariant($profile, $runtimeTypeCode);
                }
            }
        }

        return $variants;
    }

    private function createProfile(string $locale, string $typeCode, string $typeName): PersonalityProfile
    {
        return PersonalityProfile::query()->create([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => $typeCode,
            'canonical_type_code' => $typeCode,
            'slug' => strtolower($typeCode),
            'locale' => $locale,
            'title' => $typeCode.' - '.$typeName,
            'type_name' => $typeName,
            'nickname' => $typeName,
            'rarity_text' => null,
            'keywords_json' => [],
            'subtitle' => null,
            'excerpt' => null,
            'hero_kicker' => $typeName,
            'hero_quote' => null,
            'hero_summary_md' => null,
            'hero_summary_html' => null,
            'hero_image_url' => null,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
            'scheduled_at' => null,
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
    }

    private function createVariant(PersonalityProfile $profile, string $runtimeTypeCode): PersonalityProfileVariant
    {
        [$typeCode, $variantCode] = explode('-', $runtimeTypeCode);

        return PersonalityProfileVariant::query()->create([
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => $typeCode,
            'variant_code' => $variantCode,
            'runtime_type_code' => $runtimeTypeCode,
            'type_name' => null,
            'nickname' => null,
            'rarity_text' => null,
            'keywords_json' => [],
            'hero_summary_md' => null,
            'hero_summary_html' => null,
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    private function createSeoMeta(
        PersonalityProfileVariant $variant,
        string $seoTitle,
    ): PersonalityProfileVariantSeoMeta {
        return PersonalityProfileVariantSeoMeta::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'seo_title' => $seoTitle,
            'seo_description' => 'Old description',
            'canonical_url' => '/unchanged',
            'og_title' => 'Old OG title',
            'og_description' => 'Old OG description',
            'og_image_url' => null,
            'twitter_title' => 'Old Twitter title',
            'twitter_description' => 'Old Twitter description',
            'twitter_image_url' => null,
            'robots' => 'index,follow',
            'jsonld_overrides_json' => null,
        ]);
    }

    private function createOverrideMarker(
        PersonalityProfile $profile,
        PersonalityProfileVariant $variant,
        PersonalityProfileVariantSeoMeta $seoMeta,
        string $status,
        string $previous,
        string $promoted,
        ?string $liveValue = null,
    ): PersonalityProfileVariantRevision {
        $snapshot = [
            'schema_version' => MbtiSeoFieldOverrideRevisionService::SCHEMA_VERSION,
            'status' => $status,
            'promotion_id' => 'test-promotion',
            'package_sha256' => str_repeat('a', 64),
            'target' => [
                'org_id' => 0,
                'framework' => PersonalityProfile::SCALE_CODE_MBTI,
                'locale' => (string) $profile->locale,
                'runtime_type_code' => (string) $variant->runtime_type_code,
                'route' => '/'.($profile->locale === 'zh-CN' ? 'zh' : 'en').'/personality/'.strtolower((string) $variant->runtime_type_code),
            ],
            'change' => [
                'field' => MbtiSeoFieldOverrideRevisionService::FIELD_SEO_TITLE,
                'previous' => $previous,
                'promoted' => $promoted,
                'live_value' => $liveValue ?? ($status === MbtiSeoFieldOverrideRevisionService::STATUS_PROMOTED_LIVE ? $promoted : $previous),
            ],
        ];
        $snapshot['snapshot_sha256'] = app(MbtiSeoFieldOverrideRevisionService::class)->snapshotSha256($snapshot);

        return PersonalityProfileVariantRevision::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'revision_no' => 1,
            'snapshot_json' => $snapshot,
            'note' => 'test override marker',
            'created_by_admin_user_id' => null,
            'created_at' => now(),
        ]);
    }
}
