<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
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
            'hero_image_url' => 'https://assets.fermatmind.com/static/personality/type-icons/intj.png',
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
            ->assertJsonPath('items.0.hero_image_url', 'https://assets.fermatmind.com/static/personality/type-icons/intj.png')
            ->assertJsonPath('landing_surface_v1.landing_contract_version', 'landing.surface.v1')
            ->assertJsonPath('landing_surface_v1.entry_surface', 'personality_index')
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

    public function test_list_defaults_to_base_profiles_even_when_variants_exist(): void
    {
        $profile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'title' => 'INTJ - Architect',
            'hero_image_url' => 'https://assets.fermatmind.com/static/personality/type-icons/intj.png',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $this->createVariant($profile, [
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariant($profile, [
            'variant_code' => 'T',
            'runtime_type_code' => 'INTJ-T',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v0.5/personality?locale=en')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.type_code', 'INTJ')
            ->assertJsonPath('items.0.slug', 'intj')
            ->assertJsonPath('items.0.hero_image_url', 'https://assets.fermatmind.com/static/personality/type-icons/intj.png')
            ->assertJsonMissingPath('items.0.runtime_type_code')
            ->assertJsonMissingPath('items.0.variant_code');
    }

    public function test_list_can_return_backend_authoritative_variant_directory(): void
    {
        $profile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'title' => 'INTJ - Architect',
            'type_name' => 'Architect',
            'nickname' => 'Systems builder',
            'rarity_text' => 'About 2%',
            'keywords_json' => ['strategy', 'independence'],
            'hero_summary_md' => 'Base hero summary',
            'hero_image_url' => 'https://assets.fermatmind.com/static/personality/type-icons/intj.png',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $this->createSeoMeta($profile, [
            'seo_title' => 'Base INTJ title',
            'seo_description' => 'Base INTJ description',
        ]);
        $assertive = $this->createVariant($profile, [
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
            'type_name' => 'Architect Assertive',
            'nickname' => 'Assertive strategist',
            'rarity_text' => 'About 3%',
            'keywords_json' => ['assertive', 'strategy'],
            'hero_summary_md' => 'Assertive hero summary',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariantSeoMeta($assertive, [
            'seo_title' => 'INTJ-A title',
            'seo_description' => 'INTJ-A description',
        ]);
        $this->createVariant($profile, [
            'variant_code' => 'T',
            'runtime_type_code' => 'INTJ-T',
            'type_name' => 'Architect Turbulent',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        $draftProfile = $this->createProfile([
            'type_code' => 'ENTP',
            'slug' => 'entp',
            'title' => 'ENTP draft',
            'status' => 'draft',
            'is_public' => true,
        ]);
        $this->createVariant($draftProfile, [
            'canonical_type_code' => 'ENTP',
            'variant_code' => 'A',
            'runtime_type_code' => 'ENTP-A',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        $response = $this->getJson('/api/v0.5/personality?locale=en&include_variants=1');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.type_code', 'INTJ-A')
            ->assertJsonPath('items.0.runtime_type_code', 'INTJ-A')
            ->assertJsonPath('items.0.base_type_code', 'INTJ')
            ->assertJsonPath('items.0.canonical_type_code', 'INTJ')
            ->assertJsonPath('items.0.variant_code', 'A')
            ->assertJsonPath('items.0.slug', 'intj-a')
            ->assertJsonPath('items.0.base_slug', 'intj')
            ->assertJsonPath('items.0.display_type', 'INTJ-A')
            ->assertJsonPath('items.0.public_route_slug', 'intj-a')
            ->assertJsonPath('items.0.public_route_type', '32-type')
            ->assertJsonPath('items.0.type_name', 'Architect Assertive')
            ->assertJsonPath('items.0.nickname', 'Assertive strategist')
            ->assertJsonPath('items.0.rarity', 'About 3%')
            ->assertJsonPath('items.0.keywords.0', 'assertive')
            ->assertJsonPath('items.0.hero_summary', 'Assertive hero summary')
            ->assertJsonPath('items.0.hero_image_url', 'https://assets.fermatmind.com/static/personality/type-icons/intj.png')
            ->assertJsonPath('items.0.seo_meta.seo_title', 'INTJ-A title')
            ->assertJsonPath('items.1.type_code', 'INTJ-T')
            ->assertJsonPath('items.1.slug', 'intj-t');

        self::assertNotContains('ENTP-A', collect($response->json('items'))->pluck('runtime_type_code')->all());
    }

    public function test_variant_directory_respects_locale_org_and_publication_time(): void
    {
        $enProfile = $this->createProfile([
            'org_id' => 0,
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $zhProfile = $this->createProfile([
            'org_id' => 0,
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'zh-CN',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $orgProfile = $this->createProfile([
            'org_id' => 7,
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->createVariant($enProfile, [
            'runtime_type_code' => 'INTJ-A',
            'variant_code' => 'A',
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariant($enProfile, [
            'runtime_type_code' => 'INTJ-T',
            'variant_code' => 'T',
            'published_at' => now()->addHour(),
        ]);
        $this->createVariant($zhProfile, [
            'runtime_type_code' => 'INTJ-T',
            'variant_code' => 'T',
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariant($orgProfile, [
            'runtime_type_code' => 'INTJ-T',
            'variant_code' => 'T',
            'published_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v0.5/personality?locale=en&include_variants=1')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.runtime_type_code', 'INTJ-A');

        $this->getJson('/api/v0.5/personality?locale=zh-CN&include_variants=1')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.runtime_type_code', 'INTJ-T')
            ->assertJsonPath('items.0.locale', 'zh-CN');

        $this->getJson('/api/v0.5/personality?locale=en&org_id=7&include_variants=1')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.org_id', 7)
            ->assertJsonPath('items.0.runtime_type_code', 'INTJ-T');
    }

    public function test_variant_directory_requires_explicit_zero_or_one_flag(): void
    {
        $this->getJson('/api/v0.5/personality?locale=en&include_variants=true')
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'INVALID_ARGUMENT');
    }

    public function test_detail_returns_profile_sections_and_seo_meta(): void
    {
        config(['app.frontend_url' => 'https://www.fermatmind.com']);

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
            'section_key' => 'growth.strengths',
            'title' => 'Growth strengths',
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
            ->assertJsonPath('seo_surface_v1.metadata_contract_version', 'seo.surface.v1')
            ->assertJsonPath('seo_surface_v1.surface_type', 'mbti_personality_public_detail')
            ->assertJsonPath('seo_surface_v1.canonical_url', 'https://fermatmind.com/en/personality/intj')
            ->assertJsonPath('seo_surface_v1.alternates.en', 'https://fermatmind.com/en/personality/intj')
            ->assertJsonPath('seo_surface_v1.alternates.zh-CN', 'https://fermatmind.com/zh/personality/intj')
            ->assertJsonPath('seo_surface_v1.og_payload.url', 'https://fermatmind.com/en/personality/intj')
            ->assertJsonPath('landing_surface_v1.landing_contract_version', 'landing.surface.v1')
            ->assertJsonPath('landing_surface_v1.entry_surface', 'personality_detail')
            ->assertJsonPath('landing_surface_v1.entry_type', 'personality_profile')
            ->assertJsonPath('answer_surface_v1.answer_contract_version', 'answer.surface.v1')
            ->assertJsonPath('answer_surface_v1.answer_scope', 'public_indexable_detail')
            ->assertJsonPath('answer_surface_v1.surface_type', 'personality_public_detail')
            ->assertJsonPath('answer_surface_v1.summary_blocks.0.key', 'type_summary')
            ->assertJsonPath('answer_surface_v1.scene_summary_blocks.0.key', 'career_direction')
            ->assertJsonPath('answer_surface_v1.scene_summary_blocks.0.href', '/en/career/recommendations')
            ->assertJsonPath('answer_surface_v1.next_step_blocks.0.key', 'start_test')
            ->assertJsonPath('personality_public_projection_v1.display_type', 'INTJ')
            ->assertJsonPath('answer_surface_v1.evidence_refs.2', 'personality_public_projection_v1')
            ->assertJsonPath('answer_surface_v1.evidence_refs.3', 'mbti_public_projection_v1')
            ->assertJsonPath('mbti_public_projection_v1.display_type', 'INTJ')
            ->assertJsonPath('mbti_public_projection_v1.canonical_type_code', 'INTJ')
            ->assertJsonPath('mbti_public_projection_v1.runtime_type_code', null)
            ->assertJsonPath('mbti_public_projection_v1.summary_card.title', 'INTJ - Architect')
            ->assertJsonPath('mbti_public_projection_v1.sections.0.key', 'overview')
            ->assertJsonMissingPath('revisions');

        $this->assertStringNotContainsString('www.fermatmind.com', (string) $response->getContent());
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

    public function test_detail_and_seo_null_blocked_media_urls(): void
    {
        $profile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'title' => 'INTJ - Architect',
            'hero_image_url' => 'https://fermatmind-1316873116.cos.ap-shanghai.myqcloud.com/profile.png',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createSeoMeta($profile, [
            'og_image_url' => 'https://fermatmind-1316873116.cos.ap-shanghai.myqcloud.com/og.png',
            'twitter_image_url' => 'https://ci.example.test/image.png?ci-process=cover',
        ]);

        $this->getJson('/api/v0.5/personality/intj?locale=en')
            ->assertOk()
            ->assertJsonPath('profile.hero_image_url', null)
            ->assertJsonPath('seo_meta.og_image_url', null)
            ->assertJsonPath('seo_meta.twitter_image_url', null);

        $this->getJson('/api/v0.5/personality?locale=en')
            ->assertOk()
            ->assertJsonPath('items.0.hero_image_url', null);

        $this->getJson('/api/v0.5/personality/intj/seo?locale=en')
            ->assertOk()
            ->assertJsonPath('meta.og.image', null)
            ->assertJsonPath('meta.twitter.image', null);
    }

    public function test_personality_seo_title_metadata_returns_search_intent_meta_and_jsonld(): void
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
        $enVariant = $this->createVariant($enProfile, [
            'canonical_type_code' => 'INTJ',
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariantSeoMeta($enVariant, [
            'seo_title' => 'INTJ-A Architect Personality: Traits, Careers, Love & Rarity',
            'seo_description' => 'Explore INTJ-A Architect traits, A/T differences, strengths, blind spots, relationships, career fit, rarity, and how to confirm your type with an MBTI test.',
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
        $zhVariant = $this->createVariant($zhProfile, [
            'canonical_type_code' => 'INTJ',
            'variant_code' => 'T',
            'runtime_type_code' => 'INTJ-T',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariantSeoMeta($zhVariant, [
            'seo_title' => 'INTJ-T 建筑师人格：特点、适合职业、爱情与稀有度',
            'seo_description' => '了解 INTJ-T 建筑师人格的 A/T 区别、核心特点、爱情关系、适合职业、优势盲点、稀有度，并通过 MBTI 测试确认自己的类型。',
            'canonical_url' => 'https://staging.fermatmind.com/zh/personality/intj-t',
            'jsonld_overrides_json' => [
                'mainEntityOfPage' => 'https://staging.fermatmind.com/zh/personality/intj-t',
            ],
        ]);

        $enResponse = $this->getJson('/api/v0.5/personality/intj-a/seo?locale=en');
        $enResponse->assertOk()
            ->assertJsonPath('meta.title', 'INTJ-A Architect Personality: Traits, Careers, Love & Rarity')
            ->assertJsonPath('meta.description', 'Explore INTJ-A Architect traits, A/T differences, strengths, blind spots, relationships, career fit, rarity, and how to confirm your type with an MBTI test.')
            ->assertJsonPath('meta.canonical', 'https://staging.fermatmind.com/en/personality/intj-a')
            ->assertJsonPath('seo_surface_v1.metadata_contract_version', 'seo.surface.v1')
            ->assertJsonPath('seo_surface_v1.surface_type', 'mbti_personality_public_detail')
            ->assertJsonPath('meta.alternates.en', 'https://staging.fermatmind.com/en/personality/intj-a')
            ->assertJsonPath('meta.alternates.zh-CN', 'https://staging.fermatmind.com/zh/personality/intj-a')
            ->assertJsonPath('meta.robots', 'index,follow');
        self::assertSame('AboutPage', data_get($enResponse->json(), 'jsonld.@type'));
        self::assertSame(
            'https://staging.fermatmind.com/en/personality/intj-a',
            data_get($enResponse->json(), 'jsonld.mainEntityOfPage')
        );

        $zhResponse = $this->getJson('/api/v0.5/personality/intj-t/seo?locale=zh-CN');
        $zhResponse->assertOk()
            ->assertJsonPath('meta.title', 'INTJ-T 建筑师人格：特点、适合职业、爱情与稀有度')
            ->assertJsonPath('meta.description', '了解 INTJ-T 建筑师人格的 A/T 区别、核心特点、爱情关系、适合职业、优势盲点、稀有度，并通过 MBTI 测试确认自己的类型。')
            ->assertJsonPath('meta.canonical', 'https://staging.fermatmind.com/zh/personality/intj-t')
            ->assertJsonPath('seo_surface_v1.metadata_contract_version', 'seo.surface.v1')
            ->assertJsonPath('meta.alternates.en', 'https://staging.fermatmind.com/en/personality/intj-t')
            ->assertJsonPath('meta.alternates.zh-CN', 'https://staging.fermatmind.com/zh/personality/intj-t')
            ->assertJsonPath('meta.robots', 'noindex,follow');
        self::assertSame(
            'https://staging.fermatmind.com/zh/personality/intj-t',
            data_get($zhResponse->json(), 'jsonld.mainEntityOfPage')
        );
    }

    public function test_personality_comparison_endpoint_returns_backend_authoritative_at_pair(): void
    {
        config(['app.frontend_url' => 'https://www.fermatmind.com']);

        $profile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'INTJ Personality Type',
            'type_name' => 'Architect',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $this->createSeoMeta($profile, [
            'seo_title' => 'INTJ Personality Type',
            'seo_description' => 'Explore INTJ traits.',
            'robots' => 'index,follow',
        ]);

        $assertive = $this->createVariant($profile, [
            'canonical_type_code' => 'INTJ',
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
            'type_name' => 'Architect Assertive',
            'nickname' => 'Assertive strategist',
            'rarity_text' => 'About 2%',
            'hero_summary_md' => 'INTJ-A keeps a calmer long-range plan under pressure.',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariantSeoMeta($assertive, [
            'seo_title' => 'INTJ-A Architect Personality: Traits, Careers, Love & Rarity',
            'seo_description' => 'Explore INTJ-A Architect traits, A/T differences, strengths, blind spots, relationships, career fit, rarity, and how to confirm your type with an MBTI test.',
        ]);
        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $assertive->id,
            'section_key' => 'traits.at_difference',
            'render_variant' => 'rich_text',
            'body_md' => 'INTJ-A usually trusts the plan sooner and spends less energy second-guessing the decision.',
            'payload_json' => ['runtime_type_code' => 'INTJ-A', 'sibling_runtime_type_code' => 'INTJ-T'],
            'sort_order' => 31,
            'is_enabled' => true,
        ]);
        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $assertive->id,
            'section_key' => 'career.summary',
            'render_variant' => 'rich_text',
            'body_md' => 'INTJ-A often fits roles that reward independent strategy and calm ownership.',
            'sort_order' => 50,
            'is_enabled' => true,
        ]);

        $turbulent = $this->createVariant($profile, [
            'canonical_type_code' => 'INTJ',
            'variant_code' => 'T',
            'runtime_type_code' => 'INTJ-T',
            'type_name' => 'Architect Turbulent',
            'nickname' => 'Self-auditing strategist',
            'rarity_text' => 'About 1%',
            'hero_summary_md' => 'INTJ-T keeps checking weak points before committing.',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariantSeoMeta($turbulent, [
            'seo_title' => 'INTJ-T Architect Personality: Traits, Careers, Love & Rarity',
            'seo_description' => 'Explore INTJ-T Architect traits, A/T differences, strengths, blind spots, relationships, career fit, rarity, and how to confirm your type with an MBTI test.',
        ]);
        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $turbulent->id,
            'section_key' => 'traits.at_difference',
            'render_variant' => 'rich_text',
            'body_md' => 'INTJ-T usually stress-tests the plan longer and notices risks earlier.',
            'payload_json' => ['runtime_type_code' => 'INTJ-T', 'sibling_runtime_type_code' => 'INTJ-A'],
            'sort_order' => 31,
            'is_enabled' => true,
        ]);
        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $turbulent->id,
            'section_key' => 'relationships.summary',
            'render_variant' => 'rich_text',
            'body_md' => 'INTJ-T often needs explicit trust signals before relaxing in close relationships.',
            'sort_order' => 60,
            'is_enabled' => true,
        ]);

        $response = $this->getJson('/api/v0.5/personality/comparisons/intj-a-vs-intj-t?locale=en');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('comparison_public_projection_v1.comparison_contract_version', 'mbti.at_comparison.v1')
            ->assertJsonPath('comparison_public_projection_v1.comparison_slug', 'intj-a-vs-intj-t')
            ->assertJsonPath('comparison_public_projection_v1.base_type_code', 'INTJ')
            ->assertJsonPath('comparison_public_projection_v1.public_route_type', 'at-comparison')
            ->assertJsonPath('comparison_public_projection_v1.variants.a.runtime_type_code', 'INTJ-A')
            ->assertJsonPath('comparison_public_projection_v1.variants.a.public_route_slug', 'intj-a')
            ->assertJsonPath('comparison_public_projection_v1.variants.t.runtime_type_code', 'INTJ-T')
            ->assertJsonPath('comparison_public_projection_v1.variants.t.public_route_slug', 'intj-t')
            ->assertJsonPath('comparison_public_projection_v1.comparison_blocks.0.key', 'at_difference')
            ->assertJsonPath('comparison_public_projection_v1.comparison_blocks.0.variants.a', 'INTJ-A usually trusts the plan sooner and spends less energy second-guessing the decision.')
            ->assertJsonPath('comparison_public_projection_v1.comparison_blocks.0.variants.t', 'INTJ-T usually stress-tests the plan longer and notices risks earlier.')
            ->assertJsonPath('seo_meta.seo_title', 'INTJ-A vs INTJ-T: Traits, Careers, Love & Rarity')
            ->assertJsonPath('seo_meta.canonical_url', 'https://fermatmind.com/en/personality/intj-a-vs-intj-t')
            ->assertJsonPath('seo_surface_v1.surface_type', 'mbti_personality_at_comparison')
            ->assertJsonPath('seo_surface_v1.canonical_url', 'https://fermatmind.com/en/personality/intj-a-vs-intj-t')
            ->assertJsonPath('seo_surface_v1.alternates.zh-CN', 'https://fermatmind.com/zh/personality/intj-a-vs-intj-t')
            ->assertJsonPath('landing_surface_v1.entry_surface', 'personality_comparison')
            ->assertJsonPath('landing_surface_v1.entry_type', 'mbti_at_pair')
            ->assertJsonPath('landing_surface_v1.cta_bundle.0.href', '/en/personality/intj-a')
            ->assertJsonPath('answer_surface_v1.surface_type', 'personality_comparison_public_detail')
            ->assertJsonPath('answer_surface_v1.compare_blocks.0.key', 'at_difference')
            ->assertJsonPath('jsonld.@type', 'CollectionPage')
            ->assertJsonPath('jsonld.mainEntity.@type', 'ItemList');

        self::assertContains('BreadcrumbList', (array) $response->json('seo_surface_v1.structured_data_keys'));
        self::assertStringNotContainsString('www.fermatmind.com', (string) $response->getContent());

        $this->getJson('/api/v0.5/personality/comparisons/intj?locale=en')
            ->assertOk()
            ->assertJsonPath('comparison_public_projection_v1.comparison_slug', 'intj-a-vs-intj-t');
    }

    public function test_personality_comparison_endpoint_requires_complete_published_pair(): void
    {
        $profile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $this->createVariant($profile, [
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariant($profile, [
            'variant_code' => 'T',
            'runtime_type_code' => 'INTJ-T',
            'is_published' => false,
            'published_at' => null,
        ]);

        $this->getJson('/api/v0.5/personality/comparisons/intj-a-vs-intj-t?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.5/personality/comparisons/invalid-a-vs-invalid-t?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_detail_accepts_published_public_variants_after_canonical_cutover(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $profile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'type_name' => 'Architect',
            'nickname' => 'Systems builder',
            'rarity_text' => 'About 2%',
            'keywords_json' => ['strategy', 'independence'],
            'hero_summary_md' => 'Base hero summary',
            'status' => 'published',
            'is_public' => true,
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
        $this->createSeoMeta($profile, [
            'seo_title' => 'Base INTJ title',
            'canonical_url' => 'https://staging.fermatmind.com/en/personality/intj-a',
        ]);

        $publishedVariant = $this->createVariant($profile, [
            'canonical_type_code' => 'INTJ',
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
            'type_name' => 'Architect Assertive',
            'nickname' => 'Assertive strategist',
            'rarity_text' => 'About 3%',
            'keywords_json' => ['assertive', 'strategy'],
            'hero_summary_md' => 'Variant hero summary',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariantSeoMeta($publishedVariant, [
            'seo_title' => 'Variant INTJ-A title',
            'canonical_url' => 'https://staging.fermatmind.com/en/personality/intj-a',
        ]);
        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $publishedVariant->id,
            'section_key' => 'overview',
            'render_variant' => 'rich_text',
            'body_md' => 'Variant overview',
            'sort_order' => 10,
            'is_enabled' => true,
        ]);
        $this->createVariant($profile, [
            'canonical_type_code' => 'INTJ',
            'variant_code' => 'T',
            'runtime_type_code' => 'INTJ-T',
            'type_name' => 'Architect Turbulent',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => false,
            'published_at' => null,
        ]);

        $this->getJson('/api/v0.5/personality/intj?locale=en')
            ->assertOk()
            ->assertJsonPath('profile.canonical_type_code', 'INTJ')
            ->assertJsonPath('mbti_public_projection_v1.runtime_type_code', null)
            ->assertJsonPath('mbti_public_projection_v1.display_type', 'INTJ');

        $this->getJson('/api/v0.5/personality/intj-a?locale=en')
            ->assertOk()
            ->assertJsonPath('profile.type_code', 'INTJ')
            ->assertJsonPath('profile.slug', 'intj')
            ->assertJsonPath('profile.canonical_type_code', 'INTJ')
            ->assertJsonPath('profile.type_name', 'Architect Assertive')
            ->assertJsonPath('profile.nickname', 'Assertive strategist')
            ->assertJsonPath('profile.rarity', 'About 3%')
            ->assertJsonPath('profile.hero_summary', 'Variant hero summary')
            ->assertJsonPath('sections.0.section_key', 'overview')
            ->assertJsonPath('sections.0.body_md', 'Variant overview')
            ->assertJsonPath('seo_meta.seo_title', 'Variant INTJ-A title')
            ->assertJsonPath('seo_meta.canonical_url', 'https://staging.fermatmind.com/en/personality/intj-a')
            ->assertJsonPath('mbti_public_projection_v1.runtime_type_code', 'INTJ-A')
            ->assertJsonPath('mbti_public_projection_v1.display_type', 'INTJ-A')
            ->assertJsonPath('mbti_public_projection_v1.variant_code', 'A')
            ->assertJsonPath('mbti_public_projection_v1._meta.route_mode', 'public_variant')
            ->assertJsonPath('mbti_public_projection_v1._meta.public_route_type', '32-type')
            ->assertJsonPath('mbti_public_projection_v1.seo.canonical_url', 'https://staging.fermatmind.com/en/personality/intj-a');

        $this->getJson('/api/v0.5/personality/intj-t?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_committed_baseline_covers_all_public_variant_detail_pages(): void
    {
        $this->artisan('personality:import-local-baseline', [
            '--status' => 'published',
            '--upsert' => true,
            '--source-dir' => base_path('../content_baselines/personality'),
        ])
            ->expectsOutputToContain('profiles_found=32')
            ->expectsOutputToContain('variants_found=64')
            ->expectsOutputToContain('variant_will_create=64')
            ->assertExitCode(0);

        $checkedRoutes = [];
        $seoTitlesByLocale = [];

        foreach (['en', 'zh-CN'] as $locale) {
            $directory = $this->getJson(sprintf(
                '/api/v0.5/personality?locale=%s&include_variants=1&per_page=100',
                rawurlencode($locale),
            ));

            $directory->assertOk()
                ->assertJsonPath('pagination.total', 32)
                ->assertJsonCount(32, 'items');

            foreach ($directory->json('items') as $item) {
                $routeSlug = (string) ($item['public_route_slug'] ?? $item['slug'] ?? '');
                $runtimeTypeCode = (string) ($item['runtime_type_code'] ?? '');

                $detail = $this->getJson(sprintf(
                    '/api/v0.5/personality/%s?locale=%s',
                    rawurlencode($routeSlug),
                    rawurlencode($locale),
                ));

                $detail->assertOk()
                    ->assertJsonPath('profile.type_code', (string) ($item['base_type_code'] ?? ''))
                    ->assertJsonPath('mbti_public_projection_v1.runtime_type_code', $runtimeTypeCode);

                $profileTypeName = (string) $detail->json('profile.type_name');
                $expectedSeoTitle = $this->expectedSearchIntentSeoTitle($locale, $runtimeTypeCode, $profileTypeName);
                $expectedSeoDescription = $this->expectedSearchIntentSeoDescription($locale, $runtimeTypeCode, $profileTypeName);

                $detail->assertJsonPath('seo_meta.seo_title', $expectedSeoTitle)
                    ->assertJsonPath('seo_meta.seo_description', $expectedSeoDescription)
                    ->assertJsonPath('mbti_public_projection_v1.seo.title', $expectedSeoTitle)
                    ->assertJsonPath('mbti_public_projection_v1.seo.description', $expectedSeoDescription);
                self::assertStringContainsString($runtimeTypeCode, $expectedSeoTitle);
                self::assertStringContainsString($runtimeTypeCode, $expectedSeoDescription);
                self::assertStringContainsString($profileTypeName, $expectedSeoTitle);
                self::assertStringContainsString($profileTypeName, $expectedSeoDescription);
                self::assertStringContainsString('A/T', $expectedSeoDescription);
                $localeSeoTokens = $locale === 'zh-CN'
                    ? ['特点', '适合职业', '爱情', '稀有度', 'MBTI 测试']
                    : ['Traits', 'Careers', 'Love', 'Rarity', 'MBTI test'];
                foreach ($localeSeoTokens as $seoToken) {
                    self::assertStringContainsString($seoToken, $expectedSeoTitle.' '.$expectedSeoDescription);
                }
                self::assertStringNotContainsString('Personality Type: Traits, Careers, and Growth', $expectedSeoTitle);
                self::assertStringNotContainsString('人格类型：特质、职业与成长', $expectedSeoTitle);
                $seoTitlesByLocale[$locale][] = $expectedSeoTitle;

                $sections = $detail->json('sections');
                self::assertIsArray($sections, $runtimeTypeCode.' sections should be an array.');
                self::assertNotEmpty($sections, $runtimeTypeCode.' should not render the frontend empty-content fallback.');

                $bodyText = collect($sections)
                    ->map(static fn (array $section): string => trim(implode(' ', array_filter([
                        (string) ($section['title'] ?? ''),
                        (string) ($section['body_md'] ?? ''),
                    ]))))
                    ->filter(static fn (string $value): bool => $value !== '')
                    ->implode("\n");

                self::assertGreaterThan(
                    200,
                    mb_strlen($bodyText),
                    $runtimeTypeCode.' should expose substantive public detail body text.',
                );
                self::assertStringNotContainsString('内容暂未同步', $bodyText);
                self::assertStringNotContainsString('content is not yet synchronized', strtolower($bodyText));

                /** @var array<string, mixed>|null $differenceSection */
                $differenceSection = collect($sections)
                    ->firstWhere('section_key', 'traits.at_difference');
                self::assertIsArray($differenceSection, $runtimeTypeCode.' should expose a backend-authored A/T difference section.');
                self::assertSame(
                    $this->expectedAtDifferenceTitle($locale, $runtimeTypeCode),
                    $differenceSection['title'] ?? null,
                    $runtimeTypeCode.' should expose a backend-authored public section title.',
                );
                self::assertSame(31, (int) ($differenceSection['sort_order'] ?? 0));
                self::assertSame($runtimeTypeCode, data_get($differenceSection, 'payload_json.runtime_type_code'));
                self::assertSame($this->siblingRuntimeTypeCode($runtimeTypeCode), data_get($differenceSection, 'payload_json.sibling_runtime_type_code'));

                $projectionDifferenceSection = collect((array) $detail->json('mbti_public_projection_v1.sections'))
                    ->firstWhere('key', 'traits.at_difference');
                self::assertIsArray(
                    $projectionDifferenceSection,
                    $runtimeTypeCode.' should include the A/T difference section in mbti_public_projection_v1.',
                );
                self::assertSame(
                    $this->expectedAtDifferenceTitle($locale, $runtimeTypeCode),
                    $projectionDifferenceSection['title'] ?? null,
                    $runtimeTypeCode.' projection title should come from backend variant payload.',
                );
                self::assertStringContainsString(
                    $this->siblingRuntimeTypeCode($runtimeTypeCode),
                    (string) ($projectionDifferenceSection['body_md'] ?? ''),
                );

                /** @var array<string, mixed>|null $faqSection */
                $faqSection = collect($sections)
                    ->firstWhere('section_key', 'faq');
                self::assertIsArray($faqSection, $runtimeTypeCode.' should expose a backend-authored visible FAQ section.');
                self::assertSame('faq', $faqSection['render_variant'] ?? null);
                self::assertSame(90, (int) ($faqSection['sort_order'] ?? 0));
                self::assertSame($runtimeTypeCode, data_get($faqSection, 'payload_json.runtime_type_code'));
                self::assertSame($this->siblingRuntimeTypeCode($runtimeTypeCode), data_get($faqSection, 'payload_json.sibling_runtime_type_code'));
                self::assertCount(4, (array) data_get($faqSection, 'payload_json.items'));
                self::assertSame(
                    $this->expectedFaqMeaningQuestion($locale, $runtimeTypeCode),
                    data_get($faqSection, 'payload_json.items.0.question'),
                    $runtimeTypeCode.' FAQ question should come from backend baseline content.',
                );

                $projectionFaqSection = collect((array) $detail->json('mbti_public_projection_v1.sections'))
                    ->firstWhere('key', 'faq');
                self::assertIsArray(
                    $projectionFaqSection,
                    $runtimeTypeCode.' should include the FAQ section in mbti_public_projection_v1.',
                );
                self::assertSame('faq', $projectionFaqSection['render'] ?? null);
                self::assertSame(
                    $this->expectedFaqMeaningQuestion($locale, $runtimeTypeCode),
                    data_get($projectionFaqSection, 'payload.items.0.question'),
                );

                $answerFaqBlocks = (array) $detail->json('answer_surface_v1.faq_blocks');
                self::assertCount(4, $answerFaqBlocks, $runtimeTypeCode.' should expose four FAQ blocks for frontend FAQ rendering.');
                self::assertSame(
                    $this->expectedFaqMeaningQuestion($locale, $runtimeTypeCode),
                    data_get($answerFaqBlocks, '0.question'),
                );
                self::assertStringContainsString(
                    $runtimeTypeCode,
                    (string) data_get($answerFaqBlocks, '0.answer'),
                );

                $checkedRoutes[] = $locale.':'.$routeSlug;

                if ($locale === 'zh-CN' && $runtimeTypeCode === 'ENTJ-T') {
                    self::assertStringContainsString('带着自检系统的战略指挥官', $bodyText);
                    self::assertStringContainsString('职业的天花板往往不在硬实力', $bodyText);
                }
            }
        }

        self::assertCount(64, $checkedRoutes);
        self::assertContains('zh-CN:entj-t', $checkedRoutes);
        foreach ($seoTitlesByLocale as $locale => $titles) {
            self::assertCount(32, $titles, $locale.' should expose 32 variant SEO titles.');
            self::assertSame(
                $titles,
                array_values(array_unique($titles)),
                $locale.' variant SEO titles should be unique and not template-collapsed.',
            );
        }
    }

    private function expectedSearchIntentSeoTitle(string $locale, string $runtimeTypeCode, string $typeName): string
    {
        $typeLabel = trim($runtimeTypeCode.' '.$typeName);

        if ($locale === 'zh-CN') {
            return $typeLabel.'人格：特点、适合职业、爱情与稀有度';
        }

        return $typeLabel.' Personality: Traits, Careers, Love & Rarity';
    }

    private function expectedSearchIntentSeoDescription(string $locale, string $runtimeTypeCode, string $typeName): string
    {
        $typeLabel = trim($runtimeTypeCode.' '.$typeName);

        if ($locale === 'zh-CN') {
            return '了解 '.$typeLabel.'人格的 A/T 区别、核心特点、爱情关系、适合职业、优势盲点、稀有度，并通过 MBTI 测试确认自己的类型。';
        }

        return 'Explore '.$typeLabel.' traits, A/T differences, strengths, blind spots, relationships, career fit, rarity, and how to confirm your type with an MBTI test.';
    }

    private function expectedAtDifferenceTitle(string $locale, string $runtimeTypeCode): string
    {
        $baseTypeCode = strtoupper(strtok($runtimeTypeCode, '-') ?: $runtimeTypeCode);

        if ($locale === 'zh-CN') {
            return $baseTypeCode.'-A 和 '.$baseTypeCode.'-T 有什么区别？';
        }

        return $baseTypeCode.'-A vs '.$baseTypeCode.'-T: what is the difference?';
    }

    private function expectedFaqMeaningQuestion(string $locale, string $runtimeTypeCode): string
    {
        if ($locale === 'zh-CN') {
            return $runtimeTypeCode.' 是什么意思？';
        }

        return 'What does '.$runtimeTypeCode.' mean?';
    }

    private function siblingRuntimeTypeCode(string $runtimeTypeCode): string
    {
        $baseTypeCode = strtoupper(strtok($runtimeTypeCode, '-') ?: $runtimeTypeCode);
        $variantCode = strtoupper(substr($runtimeTypeCode, -1));

        return $baseTypeCode.'-'.($variantCode === 'A' ? 'T' : 'A');
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createVariant(PersonalityProfile $profile, array $overrides = []): PersonalityProfileVariant
    {
        /** @var PersonalityProfileVariant */
        return PersonalityProfileVariant::query()->create(array_merge([
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => (string) $profile->type_code,
            'variant_code' => 'A',
            'runtime_type_code' => ((string) $profile->type_code).'-A',
            'type_name' => 'Variant type',
            'nickname' => 'Variant nickname',
            'rarity_text' => 'About 3%',
            'keywords_json' => ['variant'],
            'hero_summary_md' => 'Variant summary',
            'hero_summary_html' => null,
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createVariantSeoMeta(PersonalityProfileVariant $variant, array $overrides = []): PersonalityProfileVariantSeoMeta
    {
        /** @var PersonalityProfileVariantSeoMeta */
        return PersonalityProfileVariantSeoMeta::query()->create(array_merge([
            'personality_profile_variant_id' => (int) $variant->id,
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
