<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
