<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Http\Controllers\API\V0_5\Cms\PersonalityPublicContentAssetController;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use App\Models\PersonalityPublicContentAsset;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Cms\PersonalityPublicAssetReadModelCache;
use App\Services\Cms\PersonalityPublicReadModelCache;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PersonalityPublicApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fap.personality_current_authority_enabled' => false]);
    }

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

    public function test_variant_robots_and_profile_flag_share_one_effective_indexability_state(): void
    {
        $profile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
        ]);
        $assertive = $this->createVariant($profile, [
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
            'published_at' => now()->subMinute(),
        ]);
        $turbulent = $this->createVariant($profile, [
            'variant_code' => 'T',
            'runtime_type_code' => 'INTJ-T',
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariantSeoMeta($assertive, ['robots' => 'noindex,follow']);
        $this->createVariantSeoMeta($turbulent, ['robots' => 'index,follow']);

        $this->getJson('/api/v0.5/personality?locale=en&include_variants=1')
            ->assertOk()
            ->assertJsonPath('items.0.runtime_type_code', 'INTJ-A')
            ->assertJsonPath('items.0.is_indexable', false)
            ->assertJsonPath('items.1.runtime_type_code', 'INTJ-T')
            ->assertJsonPath('items.1.is_indexable', true);

        $this->getJson('/api/v0.5/personality/intj-a?locale=en')
            ->assertOk()
            ->assertJsonPath('profile.is_indexable', false)
            ->assertJsonPath('seo_meta.robots', 'noindex,follow')
            ->assertJsonPath('landing_surface_v1.landing_scope', 'public_noindex_detail')
            ->assertJsonPath('landing_surface_v1.indexability_state', 'noindex')
            ->assertJsonPath('answer_surface_v1.answer_scope', 'public_noindex_detail')
            ->assertJsonPath('answer_surface_v1.public_safety_state', 'public_noindex')
            ->assertJsonPath('answer_surface_v1.indexability_state', 'noindex');

        $this->getJson('/api/v0.5/personality/intj-t?locale=en')
            ->assertOk()
            ->assertJsonPath('profile.is_indexable', true)
            ->assertJsonPath('landing_surface_v1.indexability_state', 'indexable')
            ->assertJsonPath('answer_surface_v1.public_safety_state', 'public_indexable')
            ->assertJsonPath('answer_surface_v1.indexability_state', 'indexable');

        $profile->update(['is_indexable' => false]);

        $this->getJson('/api/v0.5/personality/intj-t?locale=en')
            ->assertOk()
            ->assertJsonPath('profile.is_indexable', false)
            ->assertJsonPath('seo_meta.robots', 'index,follow')
            ->assertJsonPath('landing_surface_v1.indexability_state', 'noindex')
            ->assertJsonPath('answer_surface_v1.public_safety_state', 'public_noindex')
            ->assertJsonPath('answer_surface_v1.indexability_state', 'noindex');
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

    public function test_personality_comparison_index_returns_backend_authoritative_at_list_only(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $intj = $this->createProfile([
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
        $this->createVariant($intj, [
            'canonical_type_code' => 'INTJ',
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
            'type_name' => 'Architect Assertive',
        ]);
        $this->createVariant($intj, [
            'canonical_type_code' => 'INTJ',
            'variant_code' => 'T',
            'runtime_type_code' => 'INTJ-T',
            'type_name' => 'Architect Turbulent',
        ]);
        PersonalityProfileSection::query()->create([
            'profile_id' => (int) $intj->id,
            'section_key' => 'mbti64_comparison_a_vs_t',
            'title' => 'INTJ-A vs INTJ-T',
            'render_variant' => 'rich_text',
            'body_md' => 'INTJ A/T comparison draft.',
            'payload_json' => [
                'seo' => [
                    'seo_title' => 'INTJ-A vs INTJ-T: Key Differences',
                    'h1' => 'INTJ-A vs INTJ-T: Key Differences',
                    'quick_answer_summary' => 'Compare INTJ-A and INTJ-T by confidence, stress feedback, and self-correction.',
                ],
                'content' => [
                    'quick_answer' => 'Compare INTJ-A and INTJ-T by confidence, stress feedback, and self-correction.',
                ],
            ],
            'sort_order' => 920,
            'is_enabled' => true,
        ]);

        $intp = $this->createProfile([
            'type_code' => 'INTP',
            'slug' => 'intp',
            'locale' => 'en',
            'title' => 'INTP Personality Type',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $this->createVariant($intp, [
            'canonical_type_code' => 'INTP',
            'variant_code' => 'A',
            'runtime_type_code' => 'INTP-A',
        ]);

        $entp = $this->createProfile([
            'type_code' => 'ENTP',
            'slug' => 'entp',
            'locale' => 'en',
            'title' => 'ENTP Personality Type',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
            'published_at' => null,
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $this->createVariant($entp, [
            'canonical_type_code' => 'ENTP',
            'variant_code' => 'A',
            'runtime_type_code' => 'ENTP-A',
        ]);
        $this->createVariant($entp, [
            'canonical_type_code' => 'ENTP',
            'variant_code' => 'T',
            'runtime_type_code' => 'ENTP-T',
        ]);

        $response = $this->getJson('/api/v0.5/personality/comparisons?locale=en');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('comparison_list_public_projection_v1.comparison_list_contract_version', 'mbti.comparison_list.v1')
            ->assertJsonPath('comparison_list_public_projection_v1.groups.0.key', 'at_comparisons')
            ->assertJsonPath('comparison_list_public_projection_v1.groups.0.comparison_type', 'mbti_at_comparison')
            ->assertJsonPath('comparison_list_public_projection_v1.groups.0.items.0.slug', 'intj-a-vs-intj-t')
            ->assertJsonPath('comparison_list_public_projection_v1.groups.0.items.0.title', 'INTJ-A vs INTJ-T: Key Differences')
            ->assertJsonPath('comparison_list_public_projection_v1.groups.0.items.0.base_type_code', 'INTJ')
            ->assertJsonPath('comparison_list_public_projection_v1.groups.0.items.0.public_url', 'https://fermatmind.com/en/personality/intj-a-vs-intj-t')
            ->assertJsonPath('comparison_list_public_projection_v1.groups.0.items.0.is_public', true)
            ->assertJsonPath('comparison_list_public_projection_v1.groups.0.items.0.is_indexable', true)
            ->assertJsonPath('at_comparisons.0.slug', 'intj-a-vs-intj-t');

        self::assertCount(1, (array) data_get($response->json(), 'comparison_list_public_projection_v1.groups.0.items'));
        self::assertStringNotContainsString('intp-a-vs-intp-t', (string) $response->getContent());
        self::assertStringNotContainsString('entp-a-vs-entp-t', (string) $response->getContent());
        self::assertStringNotContainsString('intj-vs-intp', (string) $response->getContent());
        self::assertStringNotContainsString('/result', (string) $response->getContent());
        self::assertStringNotContainsString('token', (string) $response->getContent());
    }

    public function test_personality_comparison_index_does_not_synthesize_cross_type_rows_from_local_packages(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $response = $this->getJson('/api/v0.5/personality/comparisons?locale=zh-CN');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('comparison_list_public_projection_v1.groups.1.key', 'cross_type_comparisons')
            ->assertJsonPath('comparison_list_public_projection_v1.groups.1.comparison_type', 'mbti_cross_type')
            ->assertJsonCount(0, 'comparison_list_public_projection_v1.groups.1.items')
            ->assertJsonCount(0, 'cross_type_comparisons');

        self::assertStringNotContainsString('/zh/result', (string) $response->getContent());
        self::assertStringNotContainsString('/zh/orders', (string) $response->getContent());
        self::assertStringNotContainsString('token=', (string) $response->getContent());
        self::assertStringNotContainsString('order_no=', (string) $response->getContent());
    }

    public function test_personality_comparison_sitemap_source_uses_indexability_gate(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $intj = $this->createProfile([
            'type_code' => 'INTJ',
            'canonical_type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'INTJ Personality Type',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $this->createVariant($intj, [
            'canonical_type_code' => 'INTJ',
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
        ]);
        $this->createVariant($intj, [
            'canonical_type_code' => 'INTJ',
            'variant_code' => 'T',
            'runtime_type_code' => 'INTJ-T',
        ]);

        $intp = $this->createProfile([
            'type_code' => 'INTP',
            'canonical_type_code' => 'INTP',
            'slug' => 'intp',
            'locale' => 'en',
            'title' => 'INTP Personality Type',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $this->createVariant($intp, [
            'canonical_type_code' => 'INTP',
            'variant_code' => 'A',
            'runtime_type_code' => 'INTP-A',
        ]);
        $this->createVariant($intp, [
            'canonical_type_code' => 'INTP',
            'variant_code' => 'T',
            'runtime_type_code' => 'INTP-T',
        ]);

        app(PublicCareerAuthorityResponseCache::class)->warm();
        $locs = collect(app(SitemapGenerator::class)->generateUrls())
            ->pluck('loc')
            ->all();

        self::assertContains('https://fermatmind.com/en/personality/intj-a-vs-intj-t', $locs);
        self::assertNotContains('https://fermatmind.com/en/personality/intp-a-vs-intp-t', $locs);
        self::assertNotContains('https://fermatmind.com/zh/personality/intj-vs-intp', $locs);
    }

    public function test_personality_comparison_endpoint_does_not_return_local_cross_type_draft_detail(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $this->getJson('/api/v0.5/personality/comparisons/intj-vs-intp?locale=zh-CN')
            ->assertNotFound()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_personality_cross_type_comparison_endpoint_fails_closed_for_missing_or_unavailable_assets(): void
    {
        $this->getJson('/api/v0.5/personality/comparisons/istj-vs-isfj?locale=zh-CN')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.5/personality/comparisons/intj-vs-intp?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');
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

    public function test_personality_comparison_endpoint_prefers_promoted_mbti64_overlay_when_available(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

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
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariantSeoMeta($assertive, [
            'seo_title' => 'Old INTJ-A title',
            'seo_description' => 'Old INTJ-A description.',
        ]);
        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $assertive->id,
            'section_key' => 'traits.at_difference',
            'render_variant' => 'rich_text',
            'body_md' => 'Old generated assertive difference.',
            'sort_order' => 31,
            'is_enabled' => true,
        ]);

        $turbulent = $this->createVariant($profile, [
            'canonical_type_code' => 'INTJ',
            'variant_code' => 'T',
            'runtime_type_code' => 'INTJ-T',
            'type_name' => 'Architect Turbulent',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariantSeoMeta($turbulent, [
            'seo_title' => 'Old INTJ-T title',
            'seo_description' => 'Old INTJ-T description.',
        ]);
        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $turbulent->id,
            'section_key' => 'traits.at_difference',
            'render_variant' => 'rich_text',
            'body_md' => 'Old generated turbulent difference.',
            'sort_order' => 31,
            'is_enabled' => true,
        ]);
        PersonalityProfileSection::query()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'mbti64_comparison_a_vs_t',
            'title' => 'INTJ-A vs INTJ-T: Key Differences',
            'render_variant' => 'rich_text',
            'body_md' => 'INTJ-A and INTJ-T share the Architect core.',
            'payload_json' => [
                'source' => 'mbti64_comparison_draft_v2_1',
                'claim_boundary' => 'Use this comparison for reflection, not diagnosis or selection.',
                'seo' => [
                    'seo_title' => 'INTJ-A vs INTJ-T: Confidence, Stress and Work Style | FermatMind',
                    'seo_description' => 'Compare INTJ-A and INTJ-T by confidence, stress recovery and work style.',
                    'h1' => 'INTJ-A vs INTJ-T: Key Differences',
                    'quick_answer_summary' => 'INTJ-A and INTJ-T share the Architect core; the A/T layer changes confidence, pressure and self-correction.',
                ],
                'content' => [
                    'quick_answer' => 'INTJ-A and INTJ-T share the Architect core; the A/T layer changes confidence, pressure and self-correction.',
                    'side_by_side_summary' => [
                        'h2' => 'INTJ-A vs INTJ-T at a glance',
                        'rows' => [
                            [
                                'dimension' => 'Decision confidence',
                                'a_variant' => 'INTJ-A commits once the logic is good enough.',
                                't_variant' => 'INTJ-T re-checks assumptions before committing.',
                            ],
                        ],
                    ],
                    'core_traits_comparison' => [
                        'h2' => 'What stays the same',
                        'body' => 'Both patterns are strategic, independent and systems-oriented.',
                    ],
                ],
                'faq' => [
                    [
                        'id' => 'intj-a-vs-intj-t-better',
                        'question' => 'Is INTJ-A better than INTJ-T?',
                        'answer' => 'No. A/T describes confidence and stress style, not rank.',
                    ],
                ],
                'internal_links' => [
                    [
                        'href' => '/en/personality/intj-a',
                        'anchor_text' => 'INTJ-A',
                        'role' => 'assertive_detail',
                        'safe_public_route' => true,
                    ],
                    [
                        'href' => '/en/results/lookup',
                        'anchor_text' => 'Private result lookup',
                        'role' => 'unsafe_result_lookup',
                        'safe_public_route' => true,
                    ],
                ],
            ],
            'sort_order' => 920,
            'is_enabled' => true,
        ]);

        $response = $this->getJson('/api/v0.5/personality/comparisons/intj-a-vs-intj-t?locale=en');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('seo_meta.seo_title', 'INTJ-A vs INTJ-T: Confidence, Stress and Work Style | FermatMind')
            ->assertJsonPath('seo_meta.seo_description', 'Compare INTJ-A and INTJ-T by confidence, stress recovery and work style.')
            ->assertJsonPath('comparison_public_projection_v1.comparison_contract_version', 'mbti.at_comparison.v1.mbti64_overlay')
            ->assertJsonPath('comparison_public_projection_v1.title', 'INTJ-A vs INTJ-T: Key Differences')
            ->assertJsonPath('comparison_public_projection_v1.description', 'INTJ-A and INTJ-T share the Architect core; the A/T layer changes confidence, pressure and self-correction.')
            ->assertJsonPath('comparison_public_projection_v1.comparison_blocks.0.key', 'decision_confidence')
            ->assertJsonPath('comparison_public_projection_v1.comparison_blocks.0.variants.a', 'INTJ-A commits once the logic is good enough.')
            ->assertJsonPath('comparison_public_projection_v1.comparison_blocks.0.variants.t', 'INTJ-T re-checks assumptions before committing.')
            ->assertJsonPath('comparison_public_projection_v1.comparison_blocks.1.key', 'core_traits_comparison')
            ->assertJsonPath('comparison_public_projection_v1.faq.0.question', 'Is INTJ-A better than INTJ-T?')
            ->assertJsonPath('comparison_public_projection_v1.internal_links.0.href', '/en/personality/intj-a')
            ->assertJsonPath('comparison_public_projection_v1.claim_boundary', 'Use this comparison for reflection, not diagnosis or selection.')
            ->assertJsonPath('claim_boundary', 'Use this comparison for reflection, not diagnosis or selection.')
            ->assertJsonPath('landing_surface_v1.summary_blocks.0.title', 'INTJ-A vs INTJ-T: Confidence, Stress and Work Style | FermatMind')
            ->assertJsonPath('landing_surface_v1.cta_bundle.0.href', '/en/personality/intj-a')
            ->assertJsonPath('answer_surface_v1.faq_blocks.0.question', 'Is INTJ-A better than INTJ-T?')
            ->assertJsonPath('answer_surface_v1.next_step_blocks.0.href', '/en/personality/intj-a');

        self::assertContains('mbti64_comparison_draft_v2_1', (array) $response->json('comparison_public_projection_v1.source_refs'));
        self::assertStringContainsString(
            'personality_profile_sections.mbti64_comparison_a_vs_t',
            implode(' ', (array) $response->json('answer_surface_v1.evidence_refs')),
        );
        self::assertStringNotContainsString('/en/results/lookup', (string) $response->getContent());
        self::assertStringNotContainsString('Old generated assertive difference.', (string) $response->getContent());
    }

    public function test_personality_comparison_endpoint_reads_agent_projection_overlay_without_comparison_blocks(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

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
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariantSeoMeta($assertive, [
            'seo_title' => 'Old INTJ-A title',
            'seo_description' => 'Old INTJ-A description.',
        ]);
        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $assertive->id,
            'section_key' => 'traits.at_difference',
            'render_variant' => 'rich_text',
            'body_md' => 'Old generated assertive difference kept as fallback.',
            'sort_order' => 31,
            'is_enabled' => true,
        ]);

        $turbulent = $this->createVariant($profile, [
            'canonical_type_code' => 'INTJ',
            'variant_code' => 'T',
            'runtime_type_code' => 'INTJ-T',
            'type_name' => 'Architect Turbulent',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariantSeoMeta($turbulent, [
            'seo_title' => 'Old INTJ-T title',
            'seo_description' => 'Old INTJ-T description.',
        ]);
        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $turbulent->id,
            'section_key' => 'traits.at_difference',
            'render_variant' => 'rich_text',
            'body_md' => 'Old generated turbulent difference kept as fallback.',
            'sort_order' => 31,
            'is_enabled' => true,
        ]);

        PersonalityProfileSection::query()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'mbti64_comparison_a_vs_t',
            'title' => 'INTJ-A vs INTJ-T: Agent Projection',
            'render_variant' => 'rich_text',
            'body_md' => 'INTJ-A and INTJ-T share the Architect core.',
            'payload_json' => [
                'snapshot_key' => 'mbti64_agent_projection_draft_v1',
                'source' => 'mbti64_agent_projection_draft_v1',
                'seo' => [
                    'title' => 'INTJ-A vs INTJ-T: Confidence, Stress and Work Style | FermatMind',
                    'description' => 'Compare INTJ-A and INTJ-T through strategy, independence, confidence, stress recovery and feedback style.',
                ],
                'content' => [
                    'quick_answer' => 'INTJ-A and INTJ-T share the same Architect core. The useful difference is how each style handles confidence, pressure, feedback and self-correction.',
                ],
                'faq' => [
                    [
                        'id' => 'intj-a-vs-intj-t-main-difference',
                        'question' => 'What is the main INTJ-A vs INTJ-T difference?',
                        'answer' => 'The main difference is confidence style and pressure recovery, not a better or worse INTJ type.',
                    ],
                ],
                'internal_links' => [
                    [
                        'href' => '/en/personality/intj-a',
                        'anchor_text' => 'INTJ-A profile',
                        'role' => 'assertive_detail',
                        'safe_public_route' => true,
                    ],
                    [
                        'href' => '/en/results/lookup',
                        'anchor_text' => 'Private result lookup',
                        'role' => 'unsafe_result_lookup',
                        'safe_public_route' => true,
                    ],
                ],
            ],
            'sort_order' => 920,
            'is_enabled' => true,
        ]);

        $response = $this->getJson('/api/v0.5/personality/comparisons/intj-a-vs-intj-t?locale=en');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('seo_meta.seo_title', 'INTJ-A vs INTJ-T: Confidence, Stress and Work Style | FermatMind')
            ->assertJsonPath('seo_meta.seo_description', 'Compare INTJ-A and INTJ-T through strategy, independence, confidence, stress recovery and feedback style.')
            ->assertJsonPath('seo_surface_v1.title', 'INTJ-A vs INTJ-T: Confidence, Stress and Work Style | FermatMind')
            ->assertJsonPath('seo_surface_v1.description', 'Compare INTJ-A and INTJ-T through strategy, independence, confidence, stress recovery and feedback style.')
            ->assertJsonPath('comparison_public_projection_v1.comparison_contract_version', 'mbti.at_comparison.v1.mbti64_overlay')
            ->assertJsonPath('comparison_public_projection_v1.title', 'INTJ-A vs INTJ-T: Confidence, Stress and Work Style | FermatMind')
            ->assertJsonPath('comparison_public_projection_v1.description', 'INTJ-A and INTJ-T share the same Architect core. The useful difference is how each style handles confidence, pressure, feedback and self-correction.')
            ->assertJsonPath('comparison_public_projection_v1.comparison_blocks.0.key', 'at_difference')
            ->assertJsonPath('comparison_public_projection_v1.faq.0.question', 'What is the main INTJ-A vs INTJ-T difference?')
            ->assertJsonPath('comparison_public_projection_v1.internal_links.0.href', '/en/personality/intj-a')
            ->assertJsonPath('comparison_public_projection_v1.overlay_source.snapshot_key', 'mbti64_agent_projection_draft_v1')
            ->assertJsonPath('answer_surface_v1.summary_blocks.0.body', 'INTJ-A and INTJ-T share the same Architect core. The useful difference is how each style handles confidence, pressure, feedback and self-correction.')
            ->assertJsonPath('answer_surface_v1.faq_blocks.0.question', 'What is the main INTJ-A vs INTJ-T difference?')
            ->assertJsonPath('answer_surface_v1.next_step_blocks.0.href', '/en/personality/intj-a');

        self::assertContains('mbti64_agent_projection_draft_v1', (array) $response->json('comparison_public_projection_v1.source_refs'));
        self::assertStringContainsString(
            'Old generated assertive difference kept as fallback.',
            (string) $response->getContent(),
        );
        self::assertStringNotContainsString('/en/results/lookup', (string) $response->getContent());
    }

    #[DataProvider('atComparisonProjectionCases')]
    public function test_personality_comparison_endpoint_projects_all_approved_sections_from_cms_authority(
        string $baseTypeCode,
        bool $usesTopLevelSections,
    ): void {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $profile = $this->createProfile([
            'type_code' => $baseTypeCode,
            'slug' => strtolower($baseTypeCode),
            'locale' => 'zh-CN',
            'title' => $baseTypeCode.' 人格',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $this->createSeoMeta($profile, [
            'seo_title' => $baseTypeCode.' 人格',
            'seo_description' => $baseTypeCode.' 人格说明。',
            'robots' => 'index,follow',
        ]);

        foreach (['A', 'T'] as $variantCode) {
            $variant = $this->createVariant($profile, [
                'canonical_type_code' => $baseTypeCode,
                'variant_code' => $variantCode,
                'runtime_type_code' => $baseTypeCode.'-'.$variantCode,
                'type_name' => $baseTypeCode.' '.$variantCode,
            ]);
            $this->createVariantSeoMeta($variant, [
                'seo_title' => $baseTypeCode.'-'.$variantCode,
                'seo_description' => $baseTypeCode.'-'.$variantCode.' 人格说明。',
            ]);
            PersonalityProfileVariantSection::query()->create([
                'personality_profile_variant_id' => (int) $variant->id,
                'section_key' => 'traits.at_difference',
                'render_variant' => 'rich_text',
                'body_md' => $baseTypeCode.'-'.$variantCode.' fallback difference.',
                'sort_order' => 31,
                'is_enabled' => true,
            ]);
        }

        $sectionKeys = [
            'biggest_difference',
            'quick_judgment_table',
            'easy_misread',
            'work_scenarios',
            'relationship_scenarios',
            'stress_scenarios',
            'do_not_misjudge',
            'common_ground',
            'usage_boundary',
        ];
        $approvedSections = array_map(
            static fn (string $key, int $index): array => [
                'key' => $key,
                'title' => 'Approved section '.($index + 1),
                'body' => $baseTypeCode.' approved body for '.$key.'.',
                ...($key === 'quick_judgment_table' ? ['rows' => [[
                    'dimension' => 'Feedback',
                    'a' => 'A response',
                    't' => 'T response',
                ]]] : []),
            ],
            $sectionKeys,
            array_keys($sectionKeys),
        );
        $canonical = 'https://fermatmind.com/zh/personality/'.strtolower($baseTypeCode).'-a-vs-'.strtolower($baseTypeCode).'-t';
        $payload = [
            'source' => $usesTopLevelSections ? 'mbti_full_cms_promotion' : 'mbti_content15_legacy_promotion',
            'snapshot_key' => 'approved-'.$baseTypeCode,
            'seo' => [
                'seo_title' => $baseTypeCode.' A/T 对比',
                'seo_description' => $baseTypeCode.' A/T 完整对比。',
            ],
            'content' => [
                'quick_answer' => $baseTypeCode.' A/T direct answer.',
                'sections' => $usesTopLevelSections ? [[
                    'key' => 'stale_nested_section',
                    'title' => 'Stale nested section',
                    'body' => 'This legacy copy must not override top-level authority.',
                ]] : $approvedSections,
            ],
            'faq' => [[
                'question' => $baseTypeCode.'-A 和 '.$baseTypeCode.'-T 有什么区别？',
                'answer' => '差异用于结构化自我观察，不代表能力高低。',
            ]],
            'indexability_held' => false,
        ];
        if ($usesTopLevelSections) {
            $payload['sections'] = $approvedSections;
        }

        PersonalityProfileSection::query()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'mbti64_comparison_a_vs_t',
            'title' => $baseTypeCode.' A/T 对比',
            'render_variant' => 'rich_text',
            'body_md' => $baseTypeCode.' A/T direct answer.',
            'payload_json' => $payload,
            'sort_order' => 920,
            'is_enabled' => true,
        ]);

        $response = $this->getJson('/api/v0.5/personality/comparisons/'.strtolower($baseTypeCode).'-a-vs-'.strtolower($baseTypeCode).'-t?locale=zh-CN');

        $response->assertOk()
            ->assertJsonCount(9, 'comparison_public_projection_v1.sections')
            ->assertJsonCount(9, 'comparison_public_projection_v1.comparison_blocks')
            ->assertJsonPath('sections.0.section_key', 'mbti64_comparison_a_vs_t')
            ->assertJsonPath('sections.0.payload_json.content.biggest_difference.title', 'Approved section 1')
            ->assertJsonPath('comparison_public_projection_v1.canonical_url', $canonical)
            ->assertJsonPath('comparison_public_projection_v1.faq.0.question', $baseTypeCode.'-A 和 '.$baseTypeCode.'-T 有什么区别？')
            ->assertJsonPath('seo_meta.canonical_url', $canonical)
            ->assertJsonPath('seo_meta.robots', 'index,follow')
            ->assertJsonPath('jsonld.@type', 'CollectionPage')
            ->assertJsonPath('jsonld.hasPart.@type', 'FAQPage');

        $projectedSections = (array) $response->json('comparison_public_projection_v1.sections');
        self::assertSame($sectionKeys, array_column($projectedSections, 'id'));
        foreach ($projectedSections as $index => $section) {
            self::assertSame('Approved section '.($index + 1), $section['title'] ?? null);
            self::assertSame([$baseTypeCode.' approved body for '.$sectionKeys[$index].'.'], $section['body'] ?? null);
        }
        self::assertStringNotContainsString('stale_nested_section', (string) $response->getContent());
    }

    #[DataProvider('invalidTopLevelAtComparisonSections')]
    public function test_personality_comparison_endpoint_does_not_project_absent_or_malformed_authority_sections(mixed $invalidSections): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $profile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $this->createSeoMeta($profile);
        foreach (['A', 'T'] as $variantCode) {
            $variant = $this->createVariant($profile, [
                'variant_code' => $variantCode,
                'runtime_type_code' => 'INTJ-'.$variantCode,
            ]);
            $this->createVariantSeoMeta($variant);
            PersonalityProfileVariantSection::query()->create([
                'personality_profile_variant_id' => (int) $variant->id,
                'section_key' => 'traits.at_difference',
                'render_variant' => 'rich_text',
                'body_md' => 'INTJ-'.$variantCode.' fallback difference.',
                'sort_order' => 31,
                'is_enabled' => true,
            ]);
        }

        $payload = [
            'seo' => ['seo_title' => 'INTJ-A vs INTJ-T'],
            'content' => ['quick_answer' => 'INTJ comparison answer.'],
            'faq' => [],
        ];
        if ($invalidSections !== null) {
            $payload['sections'] = $invalidSections;
            $payload['content']['sections'] = [[
                'key' => 'stale_nested_section',
                'title' => 'Stale nested section',
                'body' => 'Malformed top-level authority must fail closed.',
            ]];
        }
        PersonalityProfileSection::query()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'mbti64_comparison_a_vs_t',
            'title' => 'INTJ-A vs INTJ-T',
            'render_variant' => 'rich_text',
            'body_md' => 'INTJ comparison answer.',
            'payload_json' => $payload,
            'sort_order' => 920,
            'is_enabled' => true,
        ]);

        $this->getJson('/api/v0.5/personality/comparisons/intj-a-vs-intj-t?locale=en')
            ->assertOk()
            ->assertJsonMissingPath('comparison_public_projection_v1.sections')
            ->assertJsonPath('comparison_public_projection_v1.comparison_blocks.0.key', 'at_difference');
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
            ->assertJsonPath('mbti_public_projection_v1.seo.canonical_url', 'https://staging.fermatmind.com/en/personality/intj-a')
            ->assertJsonMissingPath('personality_public_projection_v1');

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

    public function test_variant_detail_maps_promoted_profile_faq_into_answer_surface(): void
    {
        $profile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'zh-CN',
            'title' => 'INTJ 人格',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $variant = $this->createVariant($profile, [
            'runtime_type_code' => 'INTJ-A',
            'type_name' => '建筑师',
        ]);
        $this->createVariantSeoMeta($variant, [
            'robots' => 'index,follow',
            'canonical_url' => 'https://fermatmind.com/zh/personality/intj-a',
        ]);
        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'section_key' => 'mbti64_promotion_metadata',
            'render_variant' => 'callout',
            'payload_json' => [
                'raw_row' => [
                    'faq' => [
                        [
                            'id' => 'intj-a-meaning',
                            'question' => 'INTJ-A 是什么意思？',
                            'answer' => 'INTJ-A 是 INTJ 基础偏好与 A 型压力风格的组合。',
                        ],
                    ],
                ],
            ],
            'sort_order' => 990,
            'is_enabled' => true,
        ]);

        $this->getJson('/api/v0.5/personality/intj-a?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('answer_surface_v1.faq_blocks.0.key', 'intj-a-meaning')
            ->assertJsonPath('answer_surface_v1.faq_blocks.0.question', 'INTJ-A 是什么意思？')
            ->assertJsonPath('answer_surface_v1.faq_blocks.0.answer', 'INTJ-A 是 INTJ 基础偏好与 A 型压力风格的组合。');
    }

    public function test_detail_excludes_disabled_and_premium_teaser_sections(): void
    {
        $profile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        PersonalityProfileSection::query()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'overview',
            'title' => 'Overview',
            'render_variant' => 'rich_text',
            'body_md' => 'Public overview.',
            'sort_order' => 10,
            'is_enabled' => true,
        ]);
        PersonalityProfileSection::query()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'draft.internal',
            'title' => 'Draft',
            'render_variant' => 'rich_text',
            'body_md' => 'Draft-only body.',
            'sort_order' => 20,
            'is_enabled' => false,
        ]);
        PersonalityProfileSection::query()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'growth.motivators',
            'title' => 'Premium growth',
            'render_variant' => 'premium_teaser',
            'body_md' => 'Premium-only body.',
            'payload_json' => ['cta' => ['href' => '/checkout']],
            'sort_order' => 30,
            'is_enabled' => true,
        ]);

        $this->getJson('/api/v0.5/personality/intj?locale=en')
            ->assertOk()
            ->assertJsonCount(1, 'sections')
            ->assertJsonPath('sections.0.section_key', 'overview')
            ->assertJsonMissingPath('sections.1')
            ->assertDontSee('Draft-only body.')
            ->assertDontSee('Premium-only body.')
            ->assertDontSee('/checkout');
    }

    public function test_mbti_detail_and_seo_expose_versioned_cache_state_and_fail_closed_after_withdrawal(): void
    {
        Cache::flush();
        $profile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'title' => 'INTJ - Architect',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $variant = $this->createVariant($profile, [
            'runtime_type_code' => 'INTJ-A',
            'type_name' => 'Architect Assertive',
        ]);

        $this->getJson('/api/v0.5/personality/intj-a?locale=en')
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss')
            ->assertJsonPath('profile.type_name', 'Architect Assertive');
        $this->getJson('/api/v0.5/personality/intj-a?locale=en')
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'fresh');
        $this->getJson('/api/v0.5/personality/intj-a/seo?locale=en')
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss');
        $this->getJson('/api/v0.5/personality/intj-a/seo?locale=en')
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'fresh');

        $cache = app(PersonalityPublicReadModelCache::class);
        self::assertTrue($cache->forgetType('INTJ-A', 'en', 0, 'MBTI'));
        $this->getJson('/api/v0.5/personality/intj-a?locale=en')
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss');
        $this->getJson('/api/v0.5/personality/intj-a/seo?locale=en')
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss');

        $this->withUnavailableDatabase(function (): void {
            $this->getJson('/api/v0.5/personality/intj-a?locale=en')
                ->assertOk()
                ->assertHeader('X-Fermat-Public-Read-Cache', 'stale')
                ->assertJsonPath('profile.title', 'INTJ - Architect');
        });

        $variant->update(['type_name' => 'Updated Architect Assertive']);
        $this->getJson('/api/v0.5/personality/intj-a?locale=en')
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss')
            ->assertJsonPath('profile.type_name', 'Updated Architect Assertive');

        $variant->update(['is_published' => false]);
        $this->getJson('/api/v0.5/personality/intj-a?locale=en')
            ->assertNotFound()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss');

        self::assertSame('miss', $cache->stale('detail', 'INTJ-A', 'en', 0, 'MBTI')['state']);
        self::assertSame('miss', $cache->stale('seo', 'INTJ-A', 'en', 0, 'MBTI')['state']);
    }

    public function test_mbti_fresh_detail_and_seo_reads_skip_heavy_content_relations(): void
    {
        Cache::flush();
        $profile = $this->createProfile([
            'type_code' => 'ISTP',
            'slug' => 'istp',
            'title' => 'ISTP - Virtuoso',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $this->createVariant($profile, [
            'runtime_type_code' => 'ISTP-A',
            'type_name' => 'Virtuoso Assertive',
        ]);
        PersonalityProfileSection::query()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'overview',
            'title' => 'Overview',
            'render_variant' => 'rich_text',
            'body_md' => 'Public overview.',
            'sort_order' => 10,
            'is_enabled' => true,
        ]);

        $detailPath = '/api/v0.5/personality/istp-a?locale=en';
        $seoPath = '/api/v0.5/personality/istp-a/seo?locale=en';
        $this->getJson($detailPath)->assertOk()->assertHeader('X-Fermat-Public-Read-Cache', 'miss');
        $this->getJson($seoPath)->assertOk()->assertHeader('X-Fermat-Public-Read-Cache', 'miss');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($detailPath)->assertOk()->assertHeader('X-Fermat-Public-Read-Cache', 'fresh');
        $detailQueries = array_map(
            static fn (array $query): string => strtolower((string) $query['query']),
            DB::getQueryLog(),
        );

        DB::flushQueryLog();
        $this->getJson($seoPath)->assertOk()->assertHeader('X-Fermat-Public-Read-Cache', 'fresh');
        $seoQueries = array_map(
            static fn (array $query): string => strtolower((string) $query['query']),
            DB::getQueryLog(),
        );

        self::assertNotEmpty($detailQueries);
        self::assertNotEmpty($seoQueries);
        foreach (['detail' => $detailQueries, 'seo' => $seoQueries] as $surface => $queries) {
            foreach ($queries as $query) {
                self::assertStringNotContainsString('personality_profile_sections', $query, $surface);
                self::assertStringNotContainsString('personality_profile_variant_sections', $query, $surface);
                self::assertStringNotContainsString('personality_profile_seo_meta', $query, $surface);
                self::assertStringNotContainsString('personality_profile_variant_seo_meta', $query, $surface);
            }
        }
    }

    public function test_big_five_and_enneagram_assets_use_versioned_active_and_lkg_reads(): void
    {
        Cache::flush();
        $bigFive = $this->createPublicContentAsset([
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
            'entity_key' => 'openness',
            'slug' => 'big-five/openness',
            'title' => 'Openness',
        ]);
        $enneagram = $this->createPublicContentAsset([
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_CORE_TYPE,
            'entity_key' => 'type-1',
            'slug' => 'enneagram/type-1',
            'title' => 'Enneagram Type 1',
        ]);

        $bigFivePath = '/api/v0.5/personality-content-assets/big_five/domain/openness?locale=en';
        $enneagramPath = '/api/v0.5/personality-content-assets/enneagram/core_type/type-1?locale=en';
        $bigFiveIndex = '/api/v0.5/personality-content-assets?framework=big_five&locale=en&per_page=100';

        $this->getJson($bigFivePath)
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss')
            ->assertJsonPath('personality_public_content_asset_v1.title', 'Openness');
        $this->getJson($bigFivePath)
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'fresh');
        $this->getJson($enneagramPath)
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss')
            ->assertJsonPath('personality_public_content_asset_v1.title', 'Enneagram Type 1');
        $this->getJson($enneagramPath)
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'fresh');
        $this->getJson($bigFiveIndex)
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss')
            ->assertJsonPath('items.0.title', 'Openness');
        $this->getJson($bigFiveIndex)
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'fresh');

        $bigFive->update(['title' => 'Updated openness']);

        $this->getJson($bigFivePath)
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss')
            ->assertJsonPath('personality_public_content_asset_v1.title', 'Updated openness');
        $this->getJson($bigFiveIndex)
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss')
            ->assertJsonPath('items.0.title', 'Updated openness');
        $this->getJson($enneagramPath)
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'fresh');

        app(PersonalityPublicAssetReadModelCache::class)
            ->invalidateCollections('big_five', 'all', 'en', 0, true);

        $this->withUnavailableDatabase(function () use ($bigFivePath, $bigFiveIndex): void {
            $this->getJson($bigFivePath)
                ->assertOk()
                ->assertHeader('X-Fermat-Public-Read-Cache', 'stale')
                ->assertJsonPath('personality_public_content_asset_v1.title', 'Updated openness');
            $this->getJson($bigFiveIndex)
                ->assertOk()
                ->assertHeader('X-Fermat-Public-Read-Cache', 'stale')
                ->assertJsonPath('items.0.title', 'Updated openness');
        });

        $bigFive->update(['is_public' => false]);

        $this->getJson($bigFivePath)
            ->assertNotFound()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss');
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        self::assertSame('miss', $cache->stale(
            'detail-code', 'big_five', 'domain', 'openness', 'en', 0
        )['state']);
        $this->getJson($enneagramPath)
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'fresh')
            ->assertJsonPath('personality_public_content_asset_v1.id', (int) $enneagram->id);
    }

    public function test_personality_asset_collection_active_hits_skip_database_queries_and_preserve_payloads(): void
    {
        Cache::flush();
        $this->createPublicContentAsset([
            'source_hash' => str_repeat('a', 64),
        ]);
        $this->createPublicContentAsset([
            'locale' => 'zh-CN',
            'title' => '开放性',
            'canonical_json' => ['path' => '/zh/personality/big-five/openness'],
            'source_hash' => str_repeat('b', 64),
        ]);
        $this->createPublicContentAsset([
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_CORE_TYPE,
            'entity_key' => 'type-1',
            'slug' => 'enneagram/type-1',
            'title' => 'Enneagram Type 1',
            'source_hash' => str_repeat('c', 64),
        ]);

        foreach ([
            '/api/v0.5/personality-content-assets?framework=big_five&locale=en&per_page=100',
            '/api/v0.5/personality-content-assets?framework=big_five&locale=zh-CN&per_page=100',
            '/api/v0.5/personality-content-assets?framework=enneagram&locale=en&per_page=100',
        ] as $path) {
            $initial = $this->getJson($path)
                ->assertOk()
                ->assertHeader('X-Fermat-Public-Read-Cache', 'miss');
            $initialPayload = $initial->json();
            self::assertIsArray($initialPayload);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($initialPayload, 'items.0.source_hash'));

            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                for ($attempt = 0; $attempt < 2; $attempt++) {
                    $cached = $this->getJson($path)
                        ->assertOk()
                        ->assertHeader('X-Fermat-Public-Read-Cache', 'fresh');
                    self::assertSame($initialPayload, $cached->json());
                }

                self::assertSame([], DB::getQueryLog(), $path);
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        }
    }

    public function test_personality_asset_collection_rebuilds_when_active_projection_version_is_legacy(): void
    {
        Cache::flush();
        $asset = $this->createPublicContentAsset();
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        $selector = 'page:1:per-page:100';
        $pagination = ['current_page' => 1, 'per_page' => 100, 'total' => 1, 'last_page' => 1];
        $legacyPayload = [
            'ok' => true,
            'items' => [['title' => 'Legacy cached openness']],
            'pagination' => $pagination,
        ];
        $cache->put(
            'index',
            'big_five',
            'all',
            $selector,
            'en',
            0,
            $cache->collectionVersion([$asset], $pagination),
            $legacyPayload,
        );

        $this->getJson('/api/v0.5/personality-content-assets?framework=big_five&locale=en&per_page=100')
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss')
            ->assertJsonPath('items.0.title', 'Openness');

        $activeVersion = Cache::get($cache->activeKey('index', 'big_five', 'all', $selector, 'en'));
        self::assertIsString($activeVersion);
        self::assertStringEndsWith(':projection:public-review-contract-v1', $activeVersion);
    }

    public function test_personality_asset_collection_uses_current_projection_lkg_on_database_failure_and_fails_closed_without_it(): void
    {
        Cache::flush();
        $this->createPublicContentAsset();
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        $path = '/api/v0.5/personality-content-assets?framework=big_five&locale=en&per_page=100';
        $initial = $this->getJson($path)
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss');
        $initialPayload = $initial->json();
        self::assertIsArray($initialPayload);

        $cache->invalidateCollections('big_five', 'all', 'en', 0, true);
        $this->withUnavailableDatabase(function () use ($path, $initialPayload): void {
            $this->getJson($path)
                ->assertOk()
                ->assertHeader('X-Fermat-Public-Read-Cache', 'stale')
                ->assertExactJson($initialPayload);

            Cache::flush();
            $this->getJson($path)->assertStatus(500);
        });
    }

    public function test_personality_asset_detail_uses_one_canonical_projection_within_payload_budget(): void
    {
        Cache::flush();
        $largeSection = [
            'key' => 'overview',
            'title' => 'Overview',
            'body_md' => str_repeat('Public reviewed section. ', 14000),
        ];
        $bigFive = $this->createPublicContentAsset([
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
            'entity_key' => 'openness',
            'slug' => 'big-five/openness',
            'content_sections_json' => [$largeSection],
        ]);
        $enneagram = $this->createPublicContentAsset([
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_CORE_TYPE,
            'entity_key' => 'type-1',
            'slug' => 'enneagram/type-1',
            'content_sections_json' => [$largeSection],
        ]);

        foreach ([
            '/api/v0.5/personality-content-assets/big_five/domain/openness?locale=en' => true,
            '/api/v0.5/personality-content-assets/enneagram/core_type/type-1?locale=en' => true,
        ] as $path => $expectsAuthorityV2) {
            $response = $this->getJson($path)->assertOk();
            $payload = $response->json();
            $v1 = $response->json('personality_public_content_asset_v1');

            self::assertIsArray($payload);
            self::assertIsArray($v1);
            self::assertArrayNotHasKey('asset', $payload);
            self::assertArrayHasKey('sections', $v1);
            self::assertArrayNotHasKey('content_sections', $v1);
            self::assertLessThanOrEqual(
                PersonalityPublicContentAssetController::MAX_DETAIL_PAYLOAD_BYTES,
                strlen((string) $response->getContent()),
            );

            if ($expectsAuthorityV2) {
                $v2 = $response->json('personality_public_content_asset_v2');
                self::assertIsArray($v2);
                self::assertSame(
                    PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
                    $v2['contract_version'] ?? null,
                );
                self::assertArrayNotHasKey('sections', $v2);
                self::assertArrayNotHasKey('content_sections', $v2);
                self::assertArrayNotHasKey('title', $v2);
            } else {
                self::assertArrayNotHasKey('personality_public_content_asset_v2', $payload);
            }
        }

        self::assertGreaterThan(0, $bigFive->id);
        self::assertGreaterThan(0, $enneagram->id);

        $this->getJson('/api/v0.5/personality-content-assets?framework=big_five&locale=en&per_page=100')
            ->assertOk()
            ->assertJsonPath('items.0.sections.0.key', 'overview')
            ->assertJsonMissingPath('items.0.content_sections');
    }

    public function test_personality_asset_detail_enforces_payload_budget_with_lkg_or_structured_unavailable(): void
    {
        Cache::flush();
        $asset = $this->createPublicContentAsset([
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
            'entity_key' => 'openness',
            'slug' => 'big-five/openness',
            'content_sections_json' => [[
                'key' => 'overview',
                'title' => 'Overview',
                'body_md' => 'Last known good section.',
            ]],
        ]);
        $path = '/api/v0.5/personality-content-assets/big_five/domain/openness?locale=en';

        $initialResponse = $this->getJson($path)
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss');
        $initialPayload = $initialResponse->json();
        self::assertIsArray($initialPayload);

        $asset->update(['content_sections_json' => [[
            'key' => 'overview',
            'title' => 'Overview',
            'body_md' => str_repeat('x', PersonalityPublicContentAssetController::MAX_DETAIL_PAYLOAD_BYTES),
        ]]]);
        $oversizedCachedPayload = $initialPayload;
        $oversizedCachedPayload['personality_public_content_asset_v1']['sections'][0]['body_md'] = str_repeat(
            'x',
            PersonalityPublicContentAssetController::MAX_DETAIL_PAYLOAD_BYTES,
        );
        app(PersonalityPublicAssetReadModelCache::class)->put(
            'detail-code',
            'big_five',
            'domain',
            'openness',
            'en',
            0,
            app(PersonalityPublicAssetReadModelCache::class)->versionFor($asset),
            $oversizedCachedPayload,
        );

        $this->getJson($path)
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'stale')
            ->assertJsonPath(
                'personality_public_content_asset_v1.sections.0.body_md',
                'Last known good section.',
            );

        $uncached = $this->createPublicContentAsset([
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_CORE_TYPE,
            'entity_key' => 'type-2',
            'slug' => 'enneagram/type-2',
            'content_sections_json' => [[
                'key' => 'overview',
                'title' => 'Overview',
                'body_md' => str_repeat('y', PersonalityPublicContentAssetController::MAX_DETAIL_PAYLOAD_BYTES),
            ]],
        ]);
        self::assertGreaterThan(0, $uncached->id);
        $uncachedPath = '/api/v0.5/personality-content-assets/enneagram/core_type/type-2?locale=en';

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->getJson($uncachedPath)
                ->assertStatus(503)
                ->assertHeader('X-Fermat-Public-Read-Cache', 'miss')
                ->assertHeader('Retry-After', '60')
                ->assertJsonPath('ok', false)
                ->assertJsonPath('error_code', 'PUBLIC_PAYLOAD_BUDGET_EXCEEDED');
        }
    }

    public function test_personality_asset_responses_normalize_legacy_active_and_lkg_payloads(): void
    {
        Cache::flush();
        $asset = $this->createPublicContentAsset([
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
            'entity_key' => 'openness',
            'slug' => 'big-five/openness',
            'title' => 'Openness',
            'content_sections_json' => [[
                'key' => 'overview',
                'title' => 'Overview',
                'body_md' => 'Reviewed public section.',
            ]],
        ]);
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        $detailPath = '/api/v0.5/personality-content-assets/big_five/domain/openness?locale=en';
        $indexPath = '/api/v0.5/personality-content-assets?framework=big_five&locale=en&per_page=100';

        $detailResponse = $this->getJson($detailPath)->assertOk();
        $legacyDetail = $detailResponse->json();
        $v1 = $detailResponse->json('personality_public_content_asset_v1');
        $v2 = $detailResponse->json('personality_public_content_asset_v2');
        self::assertIsArray($legacyDetail);
        self::assertIsArray($v1);
        self::assertIsArray($v2);
        $legacyDetail['asset'] = $v1;
        $legacyDetail['personality_public_content_asset_v1']['content_sections'] = $v1['sections'];
        $legacyDetail['personality_public_content_asset_v1']['media'] = [
            'hero' => ['url' => 'https://assets.fermatmind.com/personality/big-five/legacy.webp'],
        ];
        $legacyDetail['personality_public_content_asset_v1']['seo']['og_image_url'] = 'https://assets.fermatmind.com/personality/big-five/legacy-og.webp';
        $legacyDetail['personality_public_content_asset_v2'] = array_replace($v1, $v2, [
            'content_sections' => $v1['sections'],
            'media_authority' => ['hero' => ['url' => 'https://assets.fermatmind.com/personality/big-five/legacy.webp']],
        ]);
        $cache->put(
            'detail-code',
            'big_five',
            'domain',
            'openness',
            'en',
            0,
            $cache->versionFor($asset),
            $legacyDetail,
        );

        $indexResponse = $this->getJson($indexPath)->assertOk();
        $legacyIndex = $indexResponse->json();
        self::assertIsArray($legacyIndex);
        self::assertIsArray($legacyIndex['items'][0] ?? null);
        $legacyIndex['items'][0]['content_sections'] = $legacyIndex['items'][0]['sections'];
        $legacyIndex['items'][0]['media'] = ['hero' => ['url' => 'https://assets.fermatmind.com/personality/big-five/legacy.webp']];
        $legacyIndex['items'][0]['seo']['twitter_image_url'] = 'https://assets.fermatmind.com/personality/big-five/legacy-twitter.webp';
        $pagination = ['current_page' => 1, 'per_page' => 100, 'total' => 1, 'last_page' => 1];
        $cache->put(
            'index',
            'big_five',
            'all',
            'page:1:per-page:100',
            'en',
            0,
            $cache->collectionVersion([$asset], $pagination),
            $legacyIndex,
        );

        $this->getJson($detailPath)
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'fresh')
            ->assertJsonMissingPath('asset')
            ->assertJsonMissingPath('personality_public_content_asset_v1.content_sections')
            ->assertJsonMissingPath('personality_public_content_asset_v1.media')
            ->assertJsonMissingPath('personality_public_content_asset_v1.seo.og_image_url')
            ->assertJsonMissingPath('personality_public_content_asset_v2.title')
            ->assertJsonMissingPath('personality_public_content_asset_v2.sections')
            ->assertJsonMissingPath('personality_public_content_asset_v2.content_sections')
            ->assertJsonMissingPath('personality_public_content_asset_v2.media_authority');
        $this->getJson($indexPath)
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss')
            ->assertJsonMissingPath('items.0.content_sections')
            ->assertJsonMissingPath('items.0.media')
            ->assertJsonMissingPath('items.0.seo.twitter_image_url');

        $cache->invalidateCollections('big_five', 'all', 'en', 0, true);

        $this->withUnavailableDatabase(function () use ($detailPath, $indexPath): void {
            $this->getJson($detailPath)
                ->assertOk()
                ->assertHeader('X-Fermat-Public-Read-Cache', 'stale')
                ->assertJsonMissingPath('asset')
                ->assertJsonMissingPath('personality_public_content_asset_v1.content_sections')
                ->assertJsonMissingPath('personality_public_content_asset_v1.media')
                ->assertJsonMissingPath('personality_public_content_asset_v1.seo.og_image_url')
                ->assertJsonMissingPath('personality_public_content_asset_v2.title')
                ->assertJsonMissingPath('personality_public_content_asset_v2.media_authority');
            $this->getJson($indexPath)
                ->assertOk()
                ->assertHeader('X-Fermat-Public-Read-Cache', 'stale')
                ->assertJsonMissingPath('items.0.content_sections')
                ->assertJsonMissingPath('items.0.media')
                ->assertJsonMissingPath('items.0.seo.twitter_image_url');
        });
    }

    public function test_enneagram_authority_v2_detail_projection_busts_v1_only_cache_versions(): void
    {
        Cache::flush();
        $asset = $this->createPublicContentAsset([
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_CORE_TYPE,
            'entity_key' => 'type-3',
            'slug' => 'enneagram/type-3',
        ]);
        $path = '/api/v0.5/personality-content-assets/enneagram/core_type/type-3?locale=en';
        $initial = $this->getJson($path)->assertOk();
        $legacyPayload = $initial->json();
        self::assertIsArray($legacyPayload);
        unset($legacyPayload['personality_public_content_asset_v2']);

        Cache::flush();
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        $cache->put(
            'detail-code',
            PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            PersonalityPublicContentAsset::ENTITY_CORE_TYPE,
            'type-3',
            'en',
            0,
            $cache->versionFor($asset),
            $legacyPayload,
        );

        $this->getJson($path)
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss')
            ->assertJsonPath(
                'personality_public_content_asset_v2.contract_version',
                PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            );
    }

    public function test_enneagram_authority_v2_rejects_v1_only_stale_fallbacks(): void
    {
        Cache::flush();
        $asset = $this->createPublicContentAsset([
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_CORE_TYPE,
            'entity_key' => 'type-4',
            'slug' => 'enneagram/type-4',
            'content_sections_json' => [[
                'key' => 'overview',
                'title' => 'Overview',
                'body_md' => str_repeat('x', PersonalityPublicContentAssetController::MAX_DETAIL_PAYLOAD_BYTES + 1024),
            ]],
        ]);
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        $cache->put(
            'detail-code',
            PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            PersonalityPublicContentAsset::ENTITY_CORE_TYPE,
            'type-4',
            'en',
            0,
            $cache->versionFor($asset),
            [
                'ok' => true,
                'personality_public_content_asset_v1' => [
                    'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
                    'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
                    'entity_type' => PersonalityPublicContentAsset::ENTITY_CORE_TYPE,
                    'code' => 'type-4',
                ],
            ],
        );

        $this->getJson('/api/v0.5/personality-content-assets/enneagram/core_type/type-4?locale=en')
            ->assertStatus(503)
            ->assertHeader('X-Fermat-Public-Read-Cache', 'stale')
            ->assertJsonPath('error_code', 'PUBLIC_PAYLOAD_BUDGET_EXCEEDED')
            ->assertJsonMissingPath('personality_public_content_asset_v1');
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

    /** @return array<string,array{string,bool}> */
    public static function atComparisonProjectionCases(): array
    {
        $cases = [];
        foreach (['INTJ', 'INTP', 'ENTJ', 'ENTP', 'INFJ', 'INFP', 'ENFJ', 'ENFP', 'ISTJ', 'ISFJ', 'ESTJ', 'ESFJ', 'ISTP', 'ISFP', 'ESTP', 'ESFP'] as $baseTypeCode) {
            $cases[$baseTypeCode] = [$baseTypeCode, $baseTypeCode !== 'INTP'];
        }

        return $cases;
    }

    /** @return array<string,array{mixed}> */
    public static function invalidTopLevelAtComparisonSections(): array
    {
        return [
            'absent' => [null],
            'malformed string' => ['not-an-array'],
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createPublicContentAsset(array $overrides = []): PersonalityPublicContentAsset
    {
        /** @var PersonalityPublicContentAsset */
        return PersonalityPublicContentAsset::query()->create(array_merge([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
            'entity_key' => 'openness',
            'slug' => 'big-five/openness',
            'locale' => 'en',
            'title' => 'Openness',
            'summary' => 'Public personality asset summary.',
            'content_sections_json' => [['key' => 'overview', 'body' => 'Overview body.']],
            'seo_json' => ['title' => 'Public asset title'],
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'is_public' => true,
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            'review_state' => 'approved',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'published_at' => now()->subMinute(),
        ], $overrides));
    }

    private function withUnavailableDatabase(callable $callback): mixed
    {
        $defaultConnection = DB::getDefaultConnection();
        $unavailableConnection = 'personality_public_assets_unavailable';
        $connection = (array) config('database.connections.'.$defaultConnection);
        $connection['host'] = '127.0.0.1';
        $connection['port'] = 1;
        config(['database.connections.'.$unavailableConnection => $connection]);
        DB::setDefaultConnection($unavailableConnection);

        try {
            return $callback();
        } finally {
            DB::purge($unavailableConnection);
            DB::setDefaultConnection($defaultConnection);
        }
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
