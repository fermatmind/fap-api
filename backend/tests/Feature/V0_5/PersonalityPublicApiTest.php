<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PersonalityPublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_returns_published_public_only(): void
    {
        $visible = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'title' => 'INTJ - Architect',
            'type_name' => 'Architect',
            'nickname' => 'Systems builder',
            'rarity_text' => 'About 2%',
            'keywords_json' => ['strategy', 'independence'],
            'hero_summary_md' => 'Strategic, independent, and long-range.',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createSeoMeta($visible, [
            'seo_title' => 'INTJ Personality',
            'seo_description' => 'INTJ seo description',
        ]);

        $this->createProfile([
            'type_code' => 'ENTP',
            'slug' => 'entp',
            'title' => 'ENTP draft',
            'status' => 'draft',
            'is_public' => true,
        ]);
        $this->createProfile([
            'type_code' => 'INFJ',
            'slug' => 'infj',
            'title' => 'INFJ private',
            'status' => 'published',
            'is_public' => false,
            'published_at' => now()->subMinute(),
        ]);
        $this->createProfile([
            'type_code' => 'ENFP',
            'slug' => 'enfp',
            'title' => 'ENFP scheduled',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->addHour(),
        ]);

        $response = $this->getJson('/api/v0.5/personality?locale=en');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.slug', 'intj')
            ->assertJsonPath('items.0.seo_meta.seo_title', 'INTJ Personality')
            ->assertJsonPath('items.0.canonical_type_code', 'INTJ')
            ->assertJsonPath('items.0.schema_version', PersonalityProfile::SCHEMA_VERSION_V2)
            ->assertJsonPath('items.0.type_name', 'Architect')
            ->assertJsonPath('items.0.nickname', 'Systems builder')
            ->assertJsonPath('items.0.rarity', 'About 2%')
            ->assertJsonPath('items.0.keywords.0', 'strategy')
            ->assertJsonPath('items.0.hero_summary', 'Strategic, independent, and long-range.');
    }

    public function test_list_respects_locale_and_org_scope(): void
    {
        $this->createProfile([
            'org_id' => 0,
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'INTJ EN',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createProfile([
            'org_id' => 0,
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'zh-CN',
            'title' => 'INTJ ZH',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createProfile([
            'org_id' => 7,
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'INTJ Org 7',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        $en = $this->getJson('/api/v0.5/personality?locale=en');
        $en->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.title', 'INTJ EN');

        $zh = $this->getJson('/api/v0.5/personality?locale=zh-CN');
        $zh->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.title', 'INTJ ZH');

        $org = $this->getJson('/api/v0.5/personality?locale=en&org_id=7');
        $org->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.org_id', 7)
            ->assertJsonPath('items.0.title', 'INTJ Org 7');
    }

    public function test_detail_returns_profile_sections_and_seo_meta(): void
    {
        $profile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'title' => 'INTJ - Architect',
            'type_name' => 'Architect',
            'nickname' => 'Systems builder',
            'rarity_text' => 'About 2%',
            'keywords_json' => ['strategy', 'independence'],
            'hero_summary_md' => 'Strategic, independent, and long-range.',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        PersonalityProfileSection::query()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'overview',
            'title' => 'Overview',
            'render_variant' => 'rich_text',
            'body_md' => 'INTJ body',
            'sort_order' => 10,
            'is_enabled' => true,
        ]);
        PersonalityProfileSection::query()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'strengths',
            'title' => 'Strengths',
            'render_variant' => 'bullets',
            'payload_json' => ['items' => [['title' => 'Strategic thinking']]],
            'sort_order' => 20,
            'is_enabled' => false,
        ]);

        $this->createSeoMeta($profile, [
            'seo_title' => 'INTJ Personality Type',
            'seo_description' => 'Explore INTJ traits.',
            'robots' => 'index,follow',
        ]);
        PersonalityProfileRevision::query()->create([
            'profile_id' => (int) $profile->id,
            'revision_no' => 1,
            'snapshot_json' => ['title' => 'INTJ - Architect'],
            'note' => 'initial',
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/v0.5/personality/intj?locale=en');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('profile.type_code', 'INTJ')
            ->assertJsonPath('profile.slug', 'intj')
            ->assertJsonPath('profile.canonical_type_code', 'INTJ')
            ->assertJsonPath('profile.schema_version', PersonalityProfile::SCHEMA_VERSION_V2)
            ->assertJsonPath('profile.type_name', 'Architect')
            ->assertJsonPath('profile.nickname', 'Systems builder')
            ->assertJsonPath('profile.rarity', 'About 2%')
            ->assertJsonPath('profile.keywords.1', 'independence')
            ->assertJsonPath('profile.hero_summary', 'Strategic, independent, and long-range.')
            ->assertJsonCount(1, 'sections')
            ->assertJsonPath('sections.0.section_key', 'overview')
            ->assertJsonPath('seo_meta.seo_title', 'INTJ Personality Type')
            ->assertJsonPath('mbti_public_projection_v1.display_type', 'INTJ')
            ->assertJsonPath('mbti_public_projection_v1.canonical_type_code', 'INTJ')
            ->assertJsonPath('mbti_public_projection_v1.runtime_type_code', null)
            ->assertJsonPath('mbti_public_projection_v1.summary_card.title', 'INTJ - Architect')
            ->assertJsonPath('mbti_public_projection_v1.sections.0.key', 'overview')
            ->assertJsonMissingPath('revisions');
    }

    public function test_detail_returns_not_found_for_missing_hidden_or_locale_mismatch_profiles(): void
    {
        $draft = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'status' => 'draft',
            'is_public' => true,
        ]);
        $this->createProfile([
            'type_code' => 'ENTJ',
            'slug' => 'entj',
            'status' => 'published',
            'is_public' => false,
            'published_at' => now()->subMinute(),
        ]);
        $this->createProfile([
            'type_code' => 'INFJ',
            'slug' => 'infj',
            'locale' => 'zh-CN',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v0.5/personality/missing?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.5/personality/'.$draft->slug.'?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.5/personality/entj?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.5/personality/infj?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_seo_endpoint_returns_locale_aware_meta_and_jsonld(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $enProfile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'INTJ Personality Type',
            'excerpt' => 'Explore INTJ traits, strengths, and growth.',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createSeoMeta($enProfile, [
            'seo_title' => 'INTJ Personality Type: Traits, Careers, and Growth | FermatMind',
            'seo_description' => 'Explore INTJ traits, strengths, blind spots, work style, relationships, and growth advice.',
            'canonical_url' => 'https://staging.fermatmind.com/en/personality/intj-a',
            'jsonld_overrides_json' => [
                'mainEntityOfPage' => 'https://staging.fermatmind.com/en/personality/intj-a',
            ],
        ]);

        $zhProfile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'zh-CN',
            'title' => 'INTJ 人格类型',
            'excerpt' => '探索 INTJ 的特质、优势与成长方向。',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now()->subMinute(),
        ]);
        $this->createSeoMeta($zhProfile, [
            'seo_title' => 'INTJ 人格类型：特质、职业与成长 | FermatMind',
            'seo_description' => '探索 INTJ 的特质、优势、关系模式与成长建议。',
            'canonical_url' => 'https://staging.fermatmind.com/zh/personality/intj-a',
            'jsonld_overrides_json' => [
                'mainEntityOfPage' => 'https://staging.fermatmind.com/zh/personality/intj-a',
            ],
        ]);

        $enResponse = $this->getJson('/api/v0.5/personality/INTJ/seo?locale=en');
        $enResponse->assertOk()
            ->assertJsonPath('meta.title', 'INTJ Personality Type: Traits, Careers, and Growth | FermatMind')
            ->assertJsonPath('meta.canonical', 'https://staging.fermatmind.com/en/personality/intj')
            ->assertJsonPath('meta.robots', 'index,follow');
        self::assertSame('AboutPage', data_get($enResponse->json(), 'jsonld.@type'));
        self::assertSame(
            'https://staging.fermatmind.com/en/personality/intj',
            data_get($enResponse->json(), 'jsonld.mainEntityOfPage')
        );

        $zhResponse = $this->getJson('/api/v0.5/personality/intj/seo?locale=zh-CN');
        $zhResponse->assertOk()
            ->assertJsonPath('meta.canonical', 'https://staging.fermatmind.com/zh/personality/intj')
            ->assertJsonPath('meta.robots', 'noindex,follow');
        self::assertSame(
            'https://staging.fermatmind.com/zh/personality/intj',
            data_get($zhResponse->json(), 'jsonld.mainEntityOfPage')
        );
    }

    public function test_variant_route_key_remains_invalid_for_public_base_route(): void
    {
        $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);

        $this->getJson('/api/v0.5/personality/intj-a?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createProfile(array $overrides = []): PersonalityProfile
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
            'excerpt' => 'INTJs tend to value competence, systems, and long-range thinking.',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
            'published_at' => null,
            'scheduled_at' => null,
            'schema_version' => 'v1',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSeoMeta(PersonalityProfile $profile, array $overrides = []): PersonalityProfileSeoMeta
    {
        /** @var PersonalityProfileSeoMeta */
        return PersonalityProfileSeoMeta::query()->create(array_merge([
            'profile_id' => (int) $profile->id,
            'seo_title' => null,
            'seo_description' => null,
            'canonical_url' => null,
            'og_title' => null,
            'og_description' => null,
            'og_image_url' => null,
            'twitter_title' => null,
            'twitter_description' => null,
            'twitter_image_url' => null,
            'robots' => null,
            'jsonld_overrides_json' => null,
        ], $overrides));
    }
}
