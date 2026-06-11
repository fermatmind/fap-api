<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PersonalityEnrichMbtiEnglishVariantSectionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_complete_english_32_variant_scope_without_writes(): void
    {
        $this->createEnglishMbtiVariantMatrix();

        $this->artisan('personality:enrich-mbti-english-variant-sections', [
            '--dry-run' => true,
            '--assert-complete' => true,
        ])
            ->expectsOutputToContain('task_id=PERSONALITY-EN-CONTENT-00')
            ->expectsOutputToContain('dry_run=true')
            ->expectsOutputToContain('locale=en')
            ->expectsOutputToContain('expected_variants=32')
            ->expectsOutputToContain('variants_scanned=32')
            ->expectsOutputToContain('expected_sections=576')
            ->expectsOutputToContain('section_changes=576')
            ->expectsOutputToContain('writes_committed=0')
            ->expectsOutputToContain('missing_variants=0')
            ->assertExitCode(0);

        $this->assertSame(0, PersonalityProfileVariantSection::query()->withoutGlobalScopes()->count());
    }

    public function test_write_enriches_english_variant_sections_without_touching_publication_or_chinese_rows(): void
    {
        $variants = $this->createEnglishMbtiVariantMatrix();
        $zhProfile = $this->createProfile('zh-CN', 'INFP', '调停者');
        $zhVariant = $this->createVariant($zhProfile, 'INFP-T');
        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $zhVariant->id,
            'section_key' => 'overview',
            'render_variant' => 'rich_text',
            'body_md' => '中文内容不应被英文增强命令改写。',
            'body_html' => null,
            'payload_json' => null,
            'sort_order' => 20,
            'is_enabled' => true,
        ]);

        $this->artisan('personality:enrich-mbti-english-variant-sections', [
            '--write' => true,
            '--assert-complete' => true,
        ])
            ->expectsOutputToContain('dry_run=false')
            ->expectsOutputToContain('expected_variants=32')
            ->expectsOutputToContain('variants_scanned=32')
            ->expectsOutputToContain('expected_sections=576')
            ->expectsOutputToContain('writes_committed=576')
            ->expectsOutputToContain('missing_variants=0')
            ->assertExitCode(0);

        $this->assertSame(577, PersonalityProfileVariantSection::query()->withoutGlobalScopes()->count());

        $infpT = PersonalityProfileVariantSection::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_variant_id', (int) $variants['INFP-T']->id)
            ->where('section_key', 'relationships.summary')
            ->firstOrFail();

        $this->assertStringContainsString('INFP-T Mediator', (string) $infpT->body_md);
        $this->assertStringContainsString('Turbulent', (string) $infpT->body_md);
        $this->assertStringContainsString('communication, repair, boundaries', (string) $infpT->body_md);

        $infpA = PersonalityProfileVariantSection::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_variant_id', (int) $variants['INFP-A']->id)
            ->where('section_key', 'relationships.summary')
            ->firstOrFail();

        $this->assertNotSame($infpA->body_md, $infpT->body_md);

        $preferredRoles = PersonalityProfileVariantSection::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_variant_id', (int) $variants['INTJ-A']->id)
            ->where('section_key', 'career.preferred_roles')
            ->firstOrFail();

        $this->assertSame('preferred_role_list', $preferredRoles->render_variant);
        $this->assertSame('mbti_personality_variant_english_content.v1', data_get($preferredRoles->payload_json, 'structure_contract'));
        $this->assertNotEmpty(data_get($preferredRoles->payload_json, 'groups.2.examples'));

        $traitOverview = PersonalityProfileVariantSection::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_variant_id', (int) $variants['INTJ-A']->id)
            ->where('section_key', 'trait_overview')
            ->firstOrFail();

        $this->assertSame('authored_enrichment', data_get($traitOverview->payload_json, 'dimensions.4.state'));
        $this->assertSame('A', data_get($traitOverview->payload_json, 'dimensions.4.side'));

        $zhOverview = PersonalityProfileVariantSection::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_variant_id', (int) $zhVariant->id)
            ->where('section_key', 'overview')
            ->firstOrFail();

        $this->assertSame('中文内容不应被英文增强命令改写。', $zhOverview->body_md);
        $this->assertTrue((bool) $variants['INFP-T']->fresh()?->is_published);
    }

    public function test_generated_content_differentiates_all_sections_for_selected_at_pair(): void
    {
        $variants = $this->createEnglishMbtiVariantMatrix();

        $this->artisan('personality:enrich-mbti-english-variant-sections', [
            '--type' => ['INFP'],
            '--write' => true,
            '--assert-complete' => true,
        ])
            ->assertExitCode(0);

        $assertive = PersonalityProfileVariantSection::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_variant_id', (int) $variants['INFP-A']->id)
            ->get()
            ->keyBy('section_key');
        $turbulent = PersonalityProfileVariantSection::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_variant_id', (int) $variants['INFP-T']->id)
            ->get()
            ->keyBy('section_key');

        $this->assertSame(18, $assertive->count());
        $this->assertSame(18, $turbulent->count());

        foreach ($assertive as $sectionKey => $assertiveSection) {
            $this->assertTrue($turbulent->has($sectionKey));
            $this->assertNotSame(
                json_encode([$assertiveSection->body_md, $assertiveSection->payload_json]),
                json_encode([$turbulent->get($sectionKey)?->body_md, $turbulent->get($sectionKey)?->payload_json]),
                'Expected A/T differentiation for '.$sectionKey,
            );
        }
    }

    public function test_dry_run_ignores_json_object_key_order_after_write(): void
    {
        $variants = $this->createEnglishMbtiVariantMatrix();

        $this->artisan('personality:enrich-mbti-english-variant-sections', [
            '--type' => ['ENFJ'],
            '--write' => true,
            '--assert-complete' => true,
        ])
            ->expectsOutputToContain('section_changes=36')
            ->expectsOutputToContain('writes_committed=36')
            ->assertExitCode(0);

        $section = PersonalityProfileVariantSection::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_variant_id', (int) $variants['ENFJ-A']->id)
            ->where('section_key', 'career.advantages')
            ->firstOrFail();

        $section->payload_json = [
            'items' => collect($section->payload_json['items'] ?? [])
                ->map(static fn (array $item): array => [
                    'body' => (string) $item['body'],
                    'title' => (string) $item['title'],
                ])
                ->all(),
        ];
        $section->save();

        $this->artisan('personality:enrich-mbti-english-variant-sections', [
            '--type' => ['ENFJ'],
            '--dry-run' => true,
            '--assert-complete' => true,
        ])
            ->expectsOutputToContain('section_changes=0')
            ->expectsOutputToContain('writes_committed=0')
            ->assertExitCode(0);
    }

    public function test_assert_complete_fails_when_selected_english_variant_scope_is_missing(): void
    {
        $profile = $this->createProfile('en', 'INFP', 'Mediator');
        $this->createVariant($profile, 'INFP-A');

        $this->artisan('personality:enrich-mbti-english-variant-sections', [
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
    private function createEnglishMbtiVariantMatrix(): array
    {
        $typeNames = [
            'ENFJ' => 'Protagonist',
            'ENFP' => 'Campaigner',
            'ENTJ' => 'Commander',
            'ENTP' => 'Debater',
            'ESFJ' => 'Consul',
            'ESFP' => 'Entertainer',
            'ESTJ' => 'Executive',
            'ESTP' => 'Entrepreneur',
            'INFJ' => 'Advocate',
            'INFP' => 'Mediator',
            'INTJ' => 'Architect',
            'INTP' => 'Logician',
            'ISFJ' => 'Defender',
            'ISFP' => 'Adventurer',
            'ISTJ' => 'Logistician',
            'ISTP' => 'Virtuoso',
        ];
        $variants = [];

        foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
            $profile = $this->createProfile('en', $typeCode, $typeNames[$typeCode]);
            foreach (['A', 'T'] as $variantCode) {
                $runtimeTypeCode = $typeCode.'-'.$variantCode;
                $variants[$runtimeTypeCode] = $this->createVariant($profile, $runtimeTypeCode);
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
