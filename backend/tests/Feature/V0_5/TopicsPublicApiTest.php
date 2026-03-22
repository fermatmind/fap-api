<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\Article;
use App\Models\PersonalityProfile;
use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use App\Models\TopicProfileRevision;
use App\Models\TopicProfileSection;
use App\Models\TopicProfileSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TopicsPublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_returns_published_public_only(): void
    {
        $visible = $this->createTopicProfile([
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'title' => 'MBTI',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createTopicSeoMeta($visible, [
            'seo_title' => 'MBTI Guide and Type Hub',
            'seo_description' => 'Explore MBTI concepts, type profiles, guides, and tests.',
        ]);

        $this->createTopicProfile([
            'topic_code' => 'big-five',
            'slug' => 'big-five',
            'title' => 'Big Five draft',
            'status' => TopicProfile::STATUS_DRAFT,
            'is_public' => true,
        ]);
        $this->createTopicProfile([
            'topic_code' => 'enneagram',
            'slug' => 'enneagram',
            'title' => 'Enneagram private',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => false,
            'published_at' => now()->subMinute(),
        ]);
        $this->createTopicProfile([
            'topic_code' => 'self-awareness',
            'slug' => 'self-awareness',
            'title' => 'Scheduled topic',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->addHour(),
        ]);

        $response = $this->getJson('/api/v0.5/topics?locale=en');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.slug', 'mbti')
            ->assertJsonPath('items.0.seo_meta.seo_title', 'MBTI Guide and Type Hub');
    }

    public function test_list_respects_locale_and_org_scope(): void
    {
        $this->createTopicProfile([
            'org_id' => 0,
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'locale' => 'en',
            'title' => 'MBTI EN',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createTopicProfile([
            'org_id' => 0,
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'locale' => 'zh-CN',
            'title' => 'MBTI ZH',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createTopicProfile([
            'org_id' => 7,
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'locale' => 'en',
            'title' => 'MBTI Org 7',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v0.5/topics?locale=en')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.title', 'MBTI EN');

        $this->getJson('/api/v0.5/topics?locale=zh-CN')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.title', 'MBTI ZH');

        $this->getJson('/api/v0.5/topics?locale=en&org_id=7')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.org_id', 7)
            ->assertJsonPath('items.0.title', 'MBTI Org 7');
    }

    public function test_detail_returns_sections_entry_groups_and_seo_meta(): void
    {
        $topic = $this->createTopicProfile([
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'title' => 'MBTI',
            'excerpt' => 'Explore MBTI concepts, type profiles, guides, and tests.',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        TopicProfileSection::query()->create([
            'profile_id' => (int) $topic->id,
            'section_key' => 'overview',
            'title' => 'What is MBTI?',
            'render_variant' => 'rich_text',
            'body_md' => 'MBTI is a typology framework.',
            'sort_order' => 10,
            'is_enabled' => true,
        ]);
        TopicProfileSection::query()->create([
            'profile_id' => (int) $topic->id,
            'section_key' => 'faq',
            'title' => 'FAQ',
            'render_variant' => 'faq',
            'payload_json' => ['items' => [['q' => 'What is MBTI?', 'a' => 'A typology framework']]],
            'sort_order' => 20,
            'is_enabled' => false,
        ]);

        $this->createTopicSeoMeta($topic, [
            'seo_title' => 'MBTI Guide and Type Hub | FermatMind',
            'seo_description' => 'Explore MBTI concepts, type profiles, guides, and tests.',
            'robots' => 'index,follow',
        ]);
        TopicProfileRevision::query()->create([
            'profile_id' => (int) $topic->id,
            'revision_no' => 1,
            'snapshot_json' => ['title' => 'MBTI'],
            'note' => 'initial',
            'created_at' => now(),
        ]);

        $article = $this->createArticle([
            'slug' => 'how-to-read-mbti-results',
            'locale' => 'en',
            'title' => 'How to read MBTI results',
            'excerpt' => 'A practical guide to understanding type results.',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $personality = $this->createPersonalityProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'title' => 'INTJ - Architect',
            'excerpt' => 'Independent, strategic, and future-oriented.',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createScaleRegistryRow('MBTI', 'mbti-personality-test-16-personality-types');

        $this->createTopicEntry($topic, [
            'entry_type' => 'personality_profile',
            'group_key' => 'featured',
            'target_key' => 'INTJ',
            'badge_label' => 'Personality',
            'cta_label' => 'Explore',
            'sort_order' => 10,
            'is_featured' => true,
        ]);
        $this->createTopicEntry($topic, [
            'entry_type' => 'article',
            'group_key' => 'articles',
            'target_key' => (string) $article->slug,
            'target_locale' => 'en',
            'sort_order' => 20,
        ]);
        $this->createTopicEntry($topic, [
            'entry_type' => 'scale',
            'group_key' => 'tests',
            'target_key' => 'MBTI',
            'sort_order' => 30,
            'is_featured' => true,
        ]);
        $this->createTopicEntry($topic, [
            'entry_type' => 'custom_link',
            'group_key' => 'related',
            'target_key' => 'docs',
            'title_override' => 'MBTI methodology',
            'excerpt_override' => 'Read the methodology behind the MBTI topic hub.',
            'target_url_override' => '/en/about/methodology',
            'sort_order' => 40,
        ]);
        $this->createTopicEntry($topic, [
            'entry_type' => 'article',
            'group_key' => 'articles',
            'target_key' => 'missing-article',
            'target_locale' => 'en',
            'sort_order' => 50,
        ]);

        $response = $this->getJson('/api/v0.5/topics/mbti?locale=en');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('profile.topic_code', 'mbti')
            ->assertJsonPath('profile.slug', 'mbti')
            ->assertJsonCount(1, 'sections')
            ->assertJsonPath('sections.0.section_key', 'overview')
            ->assertJsonPath('seo_meta.seo_title', 'MBTI Guide and Type Hub | FermatMind')
            ->assertJsonPath('seo_surface_v1.metadata_contract_version', 'seo.surface.v1')
            ->assertJsonPath('seo_surface_v1.surface_type', 'topic_public_detail')
            ->assertJsonPath('entry_groups.featured.0.entry_type', 'personality_profile')
            ->assertJsonPath('entry_groups.featured.0.title', (string) $personality->title)
            ->assertJsonPath('entry_groups.featured.0.url', '/en/personality/intj')
            ->assertJsonPath('entry_groups.articles.0.title', (string) $article->title)
            ->assertJsonPath('entry_groups.articles.0.url', '/en/articles/how-to-read-mbti-results')
            ->assertJsonPath('entry_groups.tests.0.url', '/en/tests/mbti-personality-test-16-personality-types')
            ->assertJsonPath('entry_groups.related.0.url', '/en/about/methodology')
            ->assertJsonMissingPath('revisions');
    }

    public function test_detail_returns_not_found_for_missing_hidden_or_locale_mismatch_topics(): void
    {
        $draft = $this->createTopicProfile([
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'status' => TopicProfile::STATUS_DRAFT,
            'is_public' => true,
        ]);

        $this->createTopicProfile([
            'topic_code' => 'big-five',
            'slug' => 'big-five',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => false,
            'published_at' => now()->subMinute(),
        ]);
        $this->createTopicProfile([
            'topic_code' => 'enneagram',
            'slug' => 'enneagram',
            'locale' => 'zh-CN',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v0.5/topics/missing?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.5/topics/'.$draft->slug.'?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.5/topics/big-five?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.5/topics/enneagram?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_resolver_skips_invalid_targets_safely(): void
    {
        $topic = $this->createTopicProfile([
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->createTopicEntry($topic, [
            'entry_type' => 'article',
            'group_key' => 'articles',
            'target_key' => 'missing-article',
        ]);
        $this->createTopicEntry($topic, [
            'entry_type' => 'custom_link',
            'group_key' => 'related',
            'target_key' => 'docs',
            'title_override' => 'External docs',
            'target_url_override' => 'https://example.com/docs',
        ]);
        $this->createTopicEntry($topic, [
            'entry_type' => 'scale',
            'group_key' => 'tests',
            'target_key' => 'MISSING_SCALE',
        ]);

        $response = $this->getJson('/api/v0.5/topics/mbti?locale=en');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonMissingPath('entry_groups.articles')
            ->assertJsonMissingPath('entry_groups.related')
            ->assertJsonMissingPath('entry_groups.tests');
    }

    public function test_seo_endpoint_returns_locale_aware_meta_and_jsonld(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $enTopic = $this->createTopicProfile([
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'locale' => 'en',
            'title' => 'MBTI Guide and Type Hub',
            'excerpt' => 'Explore MBTI concepts, type profiles, guides, and tests.',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createTopicSeoMeta($enTopic, [
            'seo_title' => 'MBTI Guide and Type Hub | FermatMind',
            'seo_description' => 'Explore MBTI concepts, type profiles, guides, and tests.',
        ]);

        $zhTopic = $this->createTopicProfile([
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'locale' => 'zh-CN',
            'title' => 'MBTI 主题中心',
            'excerpt' => '探索 MBTI 概念、人格画像、指南与测试。',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now()->subMinute(),
        ]);
        $this->createTopicSeoMeta($zhTopic, [
            'seo_title' => 'MBTI 主题中心 | FermatMind',
            'seo_description' => '探索 MBTI 概念、人格画像、指南与测试。',
        ]);

        $enResponse = $this->getJson('/api/v0.5/topics/mbti/seo?locale=en');
        $enResponse->assertOk()
            ->assertJsonPath('meta.title', 'MBTI Guide and Type Hub | FermatMind')
            ->assertJsonPath('meta.canonical', 'https://staging.fermatmind.com/en/topics/mbti')
            ->assertJsonPath('seo_surface_v1.metadata_contract_version', 'seo.surface.v1')
            ->assertJsonPath('seo_surface_v1.surface_type', 'topic_public_detail')
            ->assertJsonPath('meta.robots', 'index,follow');
        self::assertSame('CollectionPage', data_get($enResponse->json(), 'jsonld.@type'));
        self::assertSame(
            'https://staging.fermatmind.com/en/topics/mbti',
            data_get($enResponse->json(), 'jsonld.mainEntityOfPage')
        );

        $zhResponse = $this->getJson('/api/v0.5/topics/mbti/seo?locale=zh-CN');
        $zhResponse->assertOk()
            ->assertJsonPath('meta.canonical', 'https://staging.fermatmind.com/zh/topics/mbti')
            ->assertJsonPath('seo_surface_v1.metadata_contract_version', 'seo.surface.v1')
            ->assertJsonPath('meta.robots', 'noindex,follow');
        self::assertSame(
            'https://staging.fermatmind.com/zh/topics/mbti',
            data_get($zhResponse->json(), 'jsonld.mainEntityOfPage')
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTopicProfile(array $overrides = []): TopicProfile
    {
        /** @var TopicProfile */
        return TopicProfile::query()->create(array_merge([
            'org_id' => 0,
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'locale' => 'en',
            'title' => 'MBTI',
            'subtitle' => 'Understand personality preferences and type dynamics.',
            'excerpt' => 'Explore MBTI concepts, type profiles, guides, and tests.',
            'hero_kicker' => 'Topic hub',
            'hero_quote' => 'Start from the core ideas, then go deeper.',
            'cover_image_url' => null,
            'status' => TopicProfile::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
            'published_at' => null,
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'sort_order' => 0,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTopicSeoMeta(TopicProfile $profile, array $overrides = []): TopicProfileSeoMeta
    {
        /** @var TopicProfileSeoMeta */
        return TopicProfileSeoMeta::query()->create(array_merge([
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
    private function createTopicEntry(TopicProfile $profile, array $overrides = []): TopicProfileEntry
    {
        /** @var TopicProfileEntry */
        return TopicProfileEntry::query()->create(array_merge([
            'profile_id' => (int) $profile->id,
            'entry_type' => 'article',
            'group_key' => 'articles',
            'target_key' => 'target-key',
            'target_locale' => 'en',
            'title_override' => null,
            'excerpt_override' => null,
            'badge_label' => null,
            'cta_label' => null,
            'target_url_override' => null,
            'payload_json' => null,
            'sort_order' => 0,
            'is_featured' => false,
            'is_enabled' => true,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createArticle(array $overrides = []): Article
    {
        /** @var Article */
        return Article::query()->create(array_merge([
            'org_id' => 0,
            'category_id' => null,
            'author_admin_user_id' => null,
            'slug' => 'article-slug',
            'locale' => 'en',
            'title' => 'Article title',
            'excerpt' => 'Article excerpt',
            'content_md' => '# Article',
            'content_html' => null,
            'cover_image_url' => null,
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
            'published_at' => null,
            'scheduled_at' => null,
        ], $overrides));
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

    private function createScaleRegistryRow(string $code, string $primarySlug): void
    {
        DB::table('scales_registry')->updateOrInsert([
            'org_id' => 0,
            'primary_slug' => $primarySlug,
        ], [
            'code' => strtoupper($code),
            'org_id' => 0,
            'primary_slug' => $primarySlug,
            'slugs_json' => json_encode([$primarySlug], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
