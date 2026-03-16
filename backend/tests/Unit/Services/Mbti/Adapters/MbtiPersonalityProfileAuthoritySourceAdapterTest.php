<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mbti\Adapters;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use App\Services\Mbti\Adapters\MbtiPersonalityProfileAuthoritySourceAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MbtiPersonalityProfileAuthoritySourceAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_maps_base_and_variant_authority_without_guessing_runtime_identity(): void
    {
        $profile = PersonalityProfile::query()->create([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => 'ENFJ',
            'slug' => 'enfj',
            'locale' => 'en',
            'title' => 'ENFJ Personality',
            'subtitle' => 'Warm and future-aware',
            'excerpt' => 'Base excerpt',
            'type_name' => 'Protagonist',
            'nickname' => 'Warm guide',
            'rarity_text' => 'About 2%',
            'keywords_json' => ['empathy', 'vision'],
            'hero_summary_md' => 'Base hero summary',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
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
            'canonical_type_code' => 'ENFJ',
            'variant_code' => 'T',
            'runtime_type_code' => 'ENFJ-T',
            'type_name' => 'Protagonist T',
            'nickname' => 'Sensitive guide',
            'rarity_text' => 'About 5%',
            'keywords_json' => ['sensitivity', 'vision'],
            'hero_summary_md' => 'Variant hero summary',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'section_key' => 'overview',
            'render_variant' => 'rich_text',
            'is_enabled' => false,
        ]);
        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'section_key' => 'growth.summary',
            'title' => 'Growth summary',
            'render_variant' => 'rich_text',
            'body_md' => 'Variant growth summary',
            'sort_order' => 20,
            'is_enabled' => true,
        ]);

        PersonalityProfileVariantSeoMeta::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'seo_title' => 'Variant SEO title',
        ]);

        $adapter = new MbtiPersonalityProfileAuthoritySourceAdapter;
        $baseAuthority = $adapter->fromBaseProfile($profile);
        $variantAuthority = $adapter->overlayVariant($baseAuthority, $variant);

        $this->assertSame('ENFJ', $baseAuthority['canonical_type_code']);
        $this->assertSame('ENFJ Personality', data_get($baseAuthority, 'summary_card.title'));
        $this->assertSame('Base excerpt', data_get($baseAuthority, 'summary_card.summary'));
        $this->assertSame('Warm guide', data_get($baseAuthority, 'profile.nickname'));
        $this->assertSame('base', data_get($baseAuthority, 'sections.overview.source'));
        $this->assertSame('Base SEO title', data_get($baseAuthority, 'seo.title'));

        $this->assertSame('ENFJ-T', $variantAuthority['runtime_type_code']);
        $this->assertSame('T', $variantAuthority['variant_code']);
        $this->assertSame('Protagonist T', data_get($variantAuthority, 'profile.type_name'));
        $this->assertSame('Sensitive guide', data_get($variantAuthority, 'profile.nickname'));
        $this->assertSame(['sensitivity', 'vision'], data_get($variantAuthority, 'profile.keywords'));
        $this->assertArrayNotHasKey('overview', $variantAuthority['sections']);
        $this->assertSame('variant', $variantAuthority['sections']['growth.summary']['source'] ?? null);
        $this->assertSame('Variant growth summary', $variantAuthority['sections']['growth.summary']['body_md'] ?? null);
        $this->assertSame('Variant SEO title', data_get($variantAuthority, 'seo.title'));
    }
}
