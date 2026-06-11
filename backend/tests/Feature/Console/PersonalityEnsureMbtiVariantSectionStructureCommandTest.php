<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PersonalityEnsureMbtiVariantSectionStructureCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_complete_64_variant_section_scope_without_writes(): void
    {
        $this->createMbtiVariantMatrix();

        $this->artisan('personality:ensure-mbti-variant-section-structure', [
            '--dry-run' => true,
            '--assert-complete' => true,
        ])
            ->expectsOutputToContain('expected_variants=64')
            ->expectsOutputToContain('variants_scanned=64')
            ->expectsOutputToContain('expected_required_sections=512')
            ->expectsOutputToContain('section_changes=512')
            ->expectsOutputToContain('writes_committed=0')
            ->expectsOutputToContain('missing_variants=0')
            ->assertExitCode(0);

        $this->assertSame(0, PersonalityProfileVariantSection::query()->withoutGlobalScopes()->count());
    }

    public function test_command_creates_required_structure_sections_without_overwriting_authored_content(): void
    {
        $variants = $this->createMbtiVariantMatrix();
        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $variants['zh-CN|INFP-T']->id,
            'section_key' => 'overview',
            'render_variant' => 'rich_text',
            'body_md' => '人工写好的 INFP-T 总览。',
            'body_html' => null,
            'payload_json' => null,
            'sort_order' => 20,
            'is_enabled' => true,
        ]);

        $this->artisan('personality:ensure-mbti-variant-section-structure', [
            '--assert-complete' => true,
        ])
            ->expectsOutputToContain('expected_variants=64')
            ->expectsOutputToContain('variants_scanned=64')
            ->expectsOutputToContain('expected_required_sections=512')
            ->expectsOutputToContain('writes_committed=511')
            ->expectsOutputToContain('preserved_existing_sections=1')
            ->expectsOutputToContain('missing_variants=0')
            ->assertExitCode(0);

        $this->assertSame(512, PersonalityProfileVariantSection::query()->withoutGlobalScopes()->count());

        $infpTSections = PersonalityProfileVariantSection::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_variant_id', (int) $variants['zh-CN|INFP-T']->id)
            ->orderBy('sort_order')
            ->pluck('section_key')
            ->all();

        $this->assertSame([
            'letters_intro',
            'overview',
            'trait_overview',
            'career.summary',
            'career.preferred_roles',
            'growth.strengths',
            'growth.weaknesses',
            'relationships.summary',
        ], $infpTSections);

        $preserved = PersonalityProfileVariantSection::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_variant_id', (int) $variants['zh-CN|INFP-T']->id)
            ->where('section_key', 'overview')
            ->firstOrFail();

        $this->assertSame('人工写好的 INFP-T 总览。', $preserved->body_md);

        $traitOverview = PersonalityProfileVariantSection::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_variant_id', (int) $variants['zh-CN|INFP-T']->id)
            ->where('section_key', 'trait_overview')
            ->firstOrFail();

        $this->assertSame('trait_dimension_grid', $traitOverview->render_variant);
        $this->assertSame('mbti_personality_variant_page_structure.v1', data_get($traitOverview->payload_json, 'structure_contract'));
        $this->assertSame('TF', data_get($traitOverview->payload_json, 'dimensions.2.id'));
        $this->assertSame('F', data_get($traitOverview->payload_json, 'dimensions.2.side'));
        $this->assertSame('AT', data_get($traitOverview->payload_json, 'dimensions.4.id'));
        $this->assertSame('T', data_get($traitOverview->payload_json, 'dimensions.4.side'));
        $this->assertStringContainsString('A/T', (string) data_get($traitOverview->payload_json, 'dimensions.4.description'));

        $lettersIntro = PersonalityProfileVariantSection::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_variant_id', (int) $variants['zh-CN|INFP-T']->id)
            ->where('section_key', 'letters_intro')
            ->firstOrFail();

        $this->assertSame('T 型状态', data_get($lettersIntro->payload_json, 'letters.4.title'));

        $preferredRoles = PersonalityProfileVariantSection::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_variant_id', (int) $variants['en|ENFP-A']->id)
            ->where('section_key', 'career.preferred_roles')
            ->firstOrFail();

        $this->assertSame('preferred_role_list', $preferredRoles->render_variant);
        $this->assertNotEmpty(data_get($preferredRoles->payload_json, 'groups.0.examples'));
    }

    public function test_public_api_exposes_the_structured_sections_for_variant_routes(): void
    {
        $variants = $this->createMbtiVariantMatrix();

        $this->artisan('personality:ensure-mbti-variant-section-structure', [
            '--locale' => ['en'],
            '--type' => ['ENFP'],
            '--assert-complete' => true,
        ])->assertExitCode(0);

        $this->getJson('/api/v0.5/personality/enfp-a?locale=en')
            ->assertOk()
            ->assertJsonPath('profile.type_code', 'ENFP')
            ->assertJsonPath('mbti_public_projection_v1.runtime_type_code', 'ENFP-A')
            ->assertJsonPath('mbti_public_projection_v1.sections.0.key', 'letters_intro')
            ->assertJsonPath('mbti_public_projection_v1.sections.1.key', 'overview')
            ->assertJsonPath('mbti_public_projection_v1.sections.2.key', 'trait_overview')
            ->assertJsonPath('mbti_public_projection_v1.sections.2.payload.dimensions.4.id', 'AT')
            ->assertJsonPath('mbti_public_projection_v1.sections.4.key', 'career.preferred_roles')
            ->assertJsonPath('landing_surface_v1.cta_bundle.0.key', 'start_test')
            ->assertJsonPath('answer_surface_v1.next_step_blocks.0.key', 'start_test');

        $this->assertSame(8, PersonalityProfileVariantSection::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_variant_id', (int) $variants['en|ENFP-A']->id)
            ->count());
    }

    public function test_assert_complete_fails_when_selected_variant_scope_is_missing(): void
    {
        $profile = $this->createProfile('en', 'INFP', 'Mediator');
        $this->createVariant($profile, 'INFP-A');

        $this->artisan('personality:ensure-mbti-variant-section-structure', [
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
            'excerpt' => $locale === 'zh-CN' ? $typeName.' 类型摘要' : $typeName.' type summary',
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
