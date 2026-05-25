<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\ContentPage;
use App\Services\SeoIntel\TranslationParity\TranslationParityMatrixReadModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EnParity02TranslationGroupReadModelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function generated_artifact_records_read_model_contract_and_content_boundaries(): void
    {
        $path = base_path('docs/seo/generated/en-parity-02-translation-group-read-model.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('en-parity-02-translation-group-read-model.v1', $payload['schema_version'] ?? null);
        $this->assertSame('EN-PARITY-02', $payload['task'] ?? null);
        $this->assertSame('backend_cms_url_truth', $payload['source_authority'] ?? null);
        $this->assertFalse((bool) ($payload['frontend_fallback_authority_used'] ?? true));
        $this->assertFalse((bool) ($payload['cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['production_migration_performed'] ?? true));
        $this->assertFalse((bool) ($payload['deploy_performed'] ?? true));
        $this->assertFalse((bool) ($payload['search_channel_action_performed'] ?? true));
        $this->assertFalse((bool) ($payload['content_generation_performed'] ?? true));
        $this->assertFalse((bool) data_get($payload, 'content_generation_policy.auto_publish_allowed'));
        $this->assertFalse((bool) data_get($payload, 'content_generation_policy.mass_english_generation_allowed'));
        $this->assertTrue((bool) data_get($payload, 'acceptance_contract.slug_mismatch_can_pair_when_translation_group_exists'));
        $this->assertContains('translation_group_id', data_get($payload, 'read_model.pairing_preference_order', []));

        foreach ([
            'content_pages',
            'articles',
            'career_guides',
            'research_reports',
            'topics',
            'personality',
            'tests',
            'landing_surfaces_page_blocks',
            'media_assets',
        ] as $family) {
            $this->assertArrayHasKey($family, $payload['covered_entity_families'] ?? []);
        }

        $this->assertSame('EN-PARITY-03 content pages EN/ZH parity', $payload['next_task'] ?? null);
    }

    #[Test]
    public function read_model_pairs_content_pages_by_translation_group_not_slug_guessing(): void
    {
        config(['seo_intel.public_canonical_host' => 'https://fermatmind.com']);

        $this->createContentPage([
            'slug' => 'about',
            'locale' => 'zh-CN',
            'translation_group_id' => 'content-page-about-group',
            'translation_status' => ContentPage::TRANSLATION_STATUS_SOURCE,
            'source_locale' => 'zh-CN',
            'canonical_path' => '/about',
            'title' => '关于费马测试',
        ]);
        $this->createContentPage([
            'slug' => 'about-fermatmind',
            'locale' => 'en',
            'translation_group_id' => 'content-page-about-group',
            'translation_status' => ContentPage::TRANSLATION_STATUS_PUBLISHED,
            'source_locale' => 'zh-CN',
            'canonical_path' => '/en/about',
            'title' => 'About FermatMind',
        ]);

        $rows = app(TranslationParityMatrixReadModel::class)->build()['rows'];
        $aboutRows = collect($rows)
            ->where('entity_type', 'content_page')
            ->where('translation_group_id', 'content-page-about-group')
            ->values();

        $this->assertCount(2, $aboutRows);

        $zh = $aboutRows->firstWhere('locale', 'zh-CN');
        $en = $aboutRows->firstWhere('locale', 'en');

        $this->assertSame('about', $zh['slug'] ?? null);
        $this->assertSame('about-fermatmind', $en['slug'] ?? null);
        $this->assertSame('https://fermatmind.com/en/about', $zh['counterpart_canonical_url'] ?? null);
        $this->assertSame('https://fermatmind.com/zh/about', $en['counterpart_canonical_url'] ?? null);
        $this->assertSame('published_indexable', $zh['counterpart_status'] ?? null);
        $this->assertSame('published_indexable', $en['counterpart_status'] ?? null);
    }

    #[Test]
    public function read_model_pairs_articles_by_translation_group_when_slugs_differ(): void
    {
        config(['seo_intel.public_canonical_host' => 'https://fermatmind.com']);

        $this->createArticle([
            'slug' => 'mbti-basics',
            'locale' => 'zh-CN',
            'translation_group_id' => 'article-mbti-basics-group',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'source_locale' => 'zh-CN',
            'title' => 'MBTI 基础',
        ]);
        $this->createArticle([
            'slug' => 'mbti-basics-guide',
            'locale' => 'en',
            'translation_group_id' => 'article-mbti-basics-group',
            'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED,
            'source_locale' => 'zh-CN',
            'title' => 'MBTI Basics Guide',
        ]);

        $rows = app(TranslationParityMatrixReadModel::class)->build()['rows'];
        $articleRows = collect($rows)
            ->where('entity_type', 'article')
            ->where('translation_group_id', 'article-mbti-basics-group')
            ->values();

        $this->assertCount(2, $articleRows);
        $this->assertSame(
            'https://fermatmind.com/en/articles/mbti-basics-guide',
            $articleRows->firstWhere('locale', 'zh-CN')['counterpart_canonical_url'] ?? null
        );
        $this->assertSame(
            'https://fermatmind.com/zh/articles/mbti-basics',
            $articleRows->firstWhere('locale', 'en')['counterpart_canonical_url'] ?? null
        );
    }

    #[Test]
    public function missing_counterparts_are_explicit_and_backend_authority_backed(): void
    {
        $this->createCareerGuide([
            'guide_code' => 'salary-negotiation-framework',
            'slug' => 'salary-negotiation-framework',
            'locale' => 'zh-CN',
            'title' => '薪资沟通框架',
        ]);

        $matrix = app(TranslationParityMatrixReadModel::class)->build();
        $missing = collect($matrix['missing_counterparts'])
            ->firstWhere('translation_group_id', 'career_guide:salary-negotiation-framework');

        $this->assertIsArray($missing);
        $this->assertSame('career_guide', $missing['entity_type'] ?? null);
        $this->assertSame('en', $missing['missing_locale'] ?? null);
        $this->assertSame('zh-CN', $missing['source_locale'] ?? null);
        $this->assertSame('career_guides.guide_code', $missing['source_of_truth'] ?? null);
        $this->assertSame('missing_counterpart_explicit', $missing['classification'] ?? null);
        $this->assertFalse((bool) ($matrix['frontend_fallback_authority_used'] ?? true));
        $this->assertFalse((bool) ($matrix['summary']['counterpart_lookup_uses_slug_guessing_only'] ?? true));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createContentPage(array $overrides = []): ContentPage
    {
        return ContentPage::query()->create($overrides + [
            'org_id' => 0,
            'slug' => 'about',
            'path' => '/about',
            'kind' => ContentPage::KIND_COMPANY,
            'page_type' => 'about',
            'title' => 'About FermatMind',
            'summary' => 'Authority-backed content page.',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'en',
            'translation_group_id' => 'content-page-about',
            'source_locale' => 'zh-CN',
            'translation_status' => ContentPage::TRANSLATION_STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'review_state' => 'approved',
            'content_md' => '## Overview'.PHP_EOL.'Authority-backed body.',
            'content_html' => '',
            'seo_title' => 'About FermatMind',
            'seo_description' => 'Authority-backed content page.',
            'meta_description' => 'Authority-backed content page.',
            'canonical_path' => '/en/about',
            'status' => ContentPage::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createArticle(array $overrides = []): Article
    {
        return Article::query()->create($overrides + [
            'org_id' => 0,
            'slug' => 'mbti-basics',
            'locale' => 'en',
            'translation_group_id' => 'article-mbti-basics',
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED,
            'title' => 'MBTI Basics',
            'excerpt' => 'A bounded guide.',
            'content_md' => '## Overview'.PHP_EOL.'Non-diagnostic educational content.',
            'content_html' => '',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subDay(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCareerGuide(array $overrides = []): CareerGuide
    {
        return CareerGuide::query()->create($overrides + [
            'org_id' => 0,
            'guide_code' => 'salary-negotiation-framework',
            'slug' => 'salary-negotiation-framework',
            'locale' => 'zh-CN',
            'title' => '薪资沟通框架',
            'excerpt' => '职业方向参考。',
            'category_slug' => 'career-growth',
            'body_md' => '## Overview'.PHP_EOL.'方向性建议。',
            'body_html' => '',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'sort_order' => 10,
            'published_at' => now()->subDay(),
            'schema_version' => 'v1',
        ]);
    }
}
