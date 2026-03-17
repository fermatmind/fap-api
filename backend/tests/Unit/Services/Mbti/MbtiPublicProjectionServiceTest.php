<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mbti;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use App\Services\Mbti\MbtiPublicProjectionService;
use App\Services\Mbti\MbtiPublicSummaryV1Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MbtiPublicProjectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_base_route_projection_from_personality_cms_only(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $profile = $this->createBaseProfile([
            'title' => 'INTJ - Architect',
        ]);
        PersonalityProfileSection::query()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'overview',
            'title' => 'Overview',
            'render_variant' => 'rich_text',
            'body_md' => 'Base overview',
            'sort_order' => 10,
            'is_enabled' => true,
        ]);
        PersonalityProfileSeoMeta::query()->create([
            'profile_id' => (int) $profile->id,
            'seo_title' => 'Base SEO title',
        ]);

        $projection = app(MbtiPublicProjectionService::class)->buildForPersonalityProfile($profile);

        $this->assertSame(null, $projection['runtime_type_code']);
        $this->assertSame('INTJ', $projection['canonical_type_code']);
        $this->assertSame('INTJ', $projection['display_type']);
        $this->assertSame('Architect', data_get($projection, 'profile.type_name'));
        $this->assertSame('INTJ - Architect', data_get($projection, 'summary_card.title'));
        $this->assertSame('Base overview', data_get($projection, 'sections.0.body_md'));
        $this->assertSame('Base SEO title', data_get($projection, 'seo.title'));
        $this->assertSame('https://staging.fermatmind.com/en/personality/intj', data_get($projection, 'seo.canonical_url'));
        $this->assertSame('base', data_get($projection, '_meta.route_mode'));
        $this->assertSame([], $projection['offer_set']);
    }

    public function test_it_builds_public_alias_projection_from_published_variant_authority(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $profile = $this->createBaseProfile([
            'title' => 'INTJ - Architect',
            'subtitle' => 'Base subtitle',
            'excerpt' => 'Base excerpt',
        ]);
        PersonalityProfileSection::query()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'overview',
            'title' => 'Overview',
            'render_variant' => 'rich_text',
            'body_md' => 'Base overview',
            'sort_order' => 10,
            'is_enabled' => true,
        ]);
        PersonalityProfileSeoMeta::query()->create([
            'profile_id' => (int) $profile->id,
            'seo_title' => 'Base SEO title',
        ]);

        $variant = PersonalityProfileVariant::query()->create([
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => 'INTJ',
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
            'type_name' => 'Architect A',
            'nickname' => 'Assertive strategist',
            'rarity_text' => 'About 3%',
            'keywords_json' => ['assertive', 'strategy'],
            'hero_summary_md' => 'Variant hero summary',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'section_key' => 'overview',
            'render_variant' => 'rich_text',
            'body_md' => 'Variant overview',
            'sort_order' => 10,
            'is_enabled' => true,
        ]);
        PersonalityProfileVariantSeoMeta::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'seo_title' => 'Variant SEO title',
        ]);

        $projection = app(MbtiPublicProjectionService::class)->buildForPublicPersonalityRoute($profile, $variant);

        $this->assertSame('INTJ-A', $projection['runtime_type_code']);
        $this->assertSame('INTJ', $projection['canonical_type_code']);
        $this->assertSame('INTJ-A', $projection['display_type']);
        $this->assertSame('A', $projection['variant_code']);
        $this->assertSame('Architect A', data_get($projection, 'profile.type_name'));
        $this->assertSame('Assertive strategist', data_get($projection, 'profile.nickname'));
        $this->assertSame('Variant overview', data_get($projection, 'sections.0.body_md'));
        $this->assertSame('Variant SEO title', data_get($projection, 'seo.title'));
        $this->assertSame('https://staging.fermatmind.com/en/personality/intj-a', data_get($projection, 'seo.canonical_url'));
        $this->assertSame('public_variant', data_get($projection, '_meta.route_mode'));
        $this->assertSame('32-type', data_get($projection, '_meta.public_route_type'));
    }

    public function test_it_merges_runtime_identity_report_fallback_and_cms_variant_authority_for_share_projection(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $profile = $this->createBaseProfile([
            'title' => 'INTJ - Architect',
            'subtitle' => 'Base subtitle',
            'excerpt' => 'Base excerpt',
            'hero_summary_md' => 'Base hero summary',
        ]);
        PersonalityProfileSection::query()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'overview',
            'title' => 'Overview',
            'render_variant' => 'rich_text',
            'body_md' => 'Base overview',
            'sort_order' => 10,
            'is_enabled' => true,
        ]);
        PersonalityProfileSeoMeta::query()->create([
            'profile_id' => (int) $profile->id,
            'seo_title' => 'Base SEO title',
        ]);

        $variant = PersonalityProfileVariant::query()->create([
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => 'INTJ',
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
            'type_name' => 'Architect A',
            'nickname' => 'Assertive strategist',
            'rarity_text' => 'About 3%',
            'keywords_json' => ['assertive', 'strategy'],
            'hero_summary_md' => 'Variant hero summary',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'section_key' => 'overview',
            'title' => 'Overview',
            'render_variant' => 'rich_text',
            'body_md' => 'Variant overview',
            'sort_order' => 10,
            'is_enabled' => true,
        ]);
        PersonalityProfileVariantSeoMeta::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'seo_title' => 'Variant SEO title',
        ]);

        $summaryBuilder = app(MbtiPublicSummaryV1Builder::class);
        $summary = $summaryBuilder->scaffold('INTJ-A');
        $summary['profile']['type_name'] = 'Runtime Architect';
        $summary['profile']['nickname'] = 'Runtime strategist';
        $summary['profile']['rarity'] = 'About 2%';
        $summary['profile']['keywords'] = ['runtime', 'strategy'];
        $summary['profile']['summary'] = 'Runtime hero summary';
        $summary['summary_card']['title'] = 'Runtime title';
        $summary['summary_card']['subtitle'] = 'Runtime subtitle';
        $summary['summary_card']['share_text'] = 'Runtime share summary';
        $summary['summary_card']['tags'] = ['runtime'];
        $summary['dimensions'] = [
            ['id' => 'EI', 'label' => 'Energy', 'axis_left' => 'Extraversion', 'axis_right' => 'Introversion', 'value_pct' => 35],
            ['id' => 'SN', 'label' => 'Information', 'axis_left' => 'Sensing', 'axis_right' => 'Intuition', 'value_pct' => 72],
            ['id' => 'TF', 'label' => 'Decision', 'axis_left' => 'Thinking', 'axis_right' => 'Feeling', 'value_pct' => 68],
            ['id' => 'JP', 'label' => 'Lifestyle', 'axis_left' => 'Judging', 'axis_right' => 'Perceiving', 'value_pct' => 63],
            ['id' => 'AT', 'label' => 'Identity', 'axis_left' => 'Assertive', 'axis_right' => 'Turbulent', 'value_pct' => 58],
        ];
        $summary['offer_set'] = [
            'cta' => ['target_sku' => 'MBTI_REPORT_FULL'],
        ];

        $sharePayload = [
            'type_code' => 'INTJ-A',
            'type_name' => 'Runtime Architect',
            'title' => 'Runtime title',
            'subtitle' => 'Runtime subtitle',
            'summary' => 'Runtime share summary',
            'tagline' => 'Runtime strategist',
            'rarity' => 'About 2%',
            'tags' => ['runtime'],
            'dimensions' => [
                ['code' => 'EI', 'label' => 'Energy', 'side' => 'I', 'side_label' => 'Introversion', 'pct' => 65, 'state' => 'clear'],
                ['code' => 'SN', 'label' => 'Information', 'side' => 'N', 'side_label' => 'Intuition', 'pct' => 72, 'state' => 'clear'],
                ['code' => 'TF', 'label' => 'Decision', 'side' => 'T', 'side_label' => 'Thinking', 'pct' => 68, 'state' => 'clear'],
                ['code' => 'JP', 'label' => 'Lifestyle', 'side' => 'J', 'side_label' => 'Judging', 'pct' => 63, 'state' => 'moderate'],
                ['code' => 'AT', 'label' => 'Identity', 'side' => 'A', 'side_label' => 'Assertive', 'pct' => 58, 'state' => 'moderate'],
            ],
            'mbti_public_summary_v1' => $summary,
        ];
        $reportPayload = [
            'profile' => [
                'type_code' => 'INTJ-A',
                'short_summary' => 'Fallback hero summary',
            ],
            'layers' => [
                'identity' => [
                    'title' => 'Fallback title',
                    'subtitle' => 'Fallback subtitle',
                    'one_liner' => 'Fallback overview body',
                ],
            ],
            'scores_pct' => [
                'EI' => 35,
                'NS' => 72,
                'FT' => 68,
                'JP' => 63,
                'AT' => 58,
            ],
            'sections' => [
                'career' => [
                    'cards' => [
                        ['body' => 'Career fallback body'],
                    ],
                ],
            ],
        ];

        $projection = app(MbtiPublicProjectionService::class)->buildForSharePayload(
            $sharePayload,
            'en',
            0,
            $reportPayload,
            ['summary' => 'Runtime result summary']
        );

        $this->assertSame('INTJ-A', $projection['runtime_type_code']);
        $this->assertSame('INTJ', $projection['canonical_type_code']);
        $this->assertSame('INTJ-A', $projection['display_type']);
        $this->assertSame('A', $projection['variant_code']);
        $this->assertSame('Architect A', data_get($projection, 'profile.type_name'));
        $this->assertSame('Assertive strategist', data_get($projection, 'profile.nickname'));
        $this->assertSame('INTJ - Architect', data_get($projection, 'summary_card.title'));
        $this->assertSame('Base excerpt', data_get($projection, 'summary_card.summary'));
        $this->assertSame('Variant overview', $this->findSection($projection['sections'], 'overview')['body_md'] ?? null);
        $this->assertSame('Career fallback body', $this->findSection($projection['sections'], 'career.summary')['body_md'] ?? null);
        $this->assertSame('Variant SEO title', data_get($projection, 'seo.title'));
        $this->assertSame('https://staging.fermatmind.com/en/personality/intj', data_get($projection, 'seo.canonical_url'));
        $this->assertSame('runtime+report_fallback+personality_cms_v2', data_get($projection, '_meta.authority_source'));
        $this->assertSame(
            ['EI', 'SN', 'TF', 'JP', 'AT'],
            array_map(static fn (array $item): string => (string) ($item['id'] ?? ''), $projection['dimensions'])
        );
        $this->assertSame('I', data_get($projection, 'dimensions.0.side'));
        $this->assertSame('clear', data_get($projection, 'dimensions.0.state'));
        $this->assertSame('MBTI_REPORT_FULL', data_get($projection, 'offer_set.cta.target_sku'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createBaseProfile(array $overrides = []): PersonalityProfile
    {
        /** @var PersonalityProfile */
        return PersonalityProfile::query()->create(array_merge([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'INTJ',
            'subtitle' => 'Strategic and future-oriented',
            'excerpt' => 'Base excerpt',
            'type_name' => 'Architect',
            'nickname' => 'Systems builder',
            'rarity_text' => 'About 2%',
            'keywords_json' => ['strategy', 'independence'],
            'hero_summary_md' => 'Base hero summary',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ], $overrides));
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    private function findSection(array $sections, string $sectionKey): array
    {
        foreach ($sections as $section) {
            if (($section['key'] ?? null) === $sectionKey) {
                return $section;
            }
        }

        return [];
    }
}
