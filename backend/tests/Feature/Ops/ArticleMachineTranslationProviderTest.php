<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Contracts\Cms\ArticleMachineTranslationProvider;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Services\Cms\ArticleTranslationWorkflowService;
use App\Services\Cms\OpenAiArticleMachineTranslationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ArticleMachineTranslationProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_openai_article_machine_translation_provider_reports_configuration_state(): void
    {
        config()->set('services.article_translation.provider', 'openai');
        config()->set('services.article_translation.openai.api_key', 'test-key');
        config()->set('services.article_translation.openai.model', 'gpt-4.1');

        $provider = app(OpenAiArticleMachineTranslationProvider::class);

        $this->assertTrue($provider->isConfigured());
        $this->assertNull($provider->unavailableReason());
    }

    public function test_openai_article_machine_translation_provider_requires_api_key(): void
    {
        config()->set('services.article_translation.provider', 'openai');
        config()->set('services.article_translation.openai.api_key', '');
        config()->set('services.article_translation.openai.model', 'gpt-4.1');

        $provider = app(OpenAiArticleMachineTranslationProvider::class);

        $this->assertFalse($provider->isConfigured());
        $this->assertSame(
            'Article machine translation provider is not configured. Set ARTICLE_TRANSLATION_OPENAI_API_KEY (or OPENAI_API_KEY).',
            $provider->unavailableReason()
        );
    }

    public function test_openai_article_machine_translation_provider_translates_article_with_structured_output(): void
    {
        config()->set('services.article_translation.provider', 'openai');
        config()->set('services.article_translation.openai.base_url', 'https://api.openai.test/v1');
        config()->set('services.article_translation.openai.api_key', 'test-key');
        config()->set('services.article_translation.openai.model', 'gpt-4.1');

        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'id' => 'resp_test_translation',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'title' => 'English title',
                            'excerpt' => 'English excerpt',
                            'content_md' => "# English heading\n\nTranslated body.\n\nReferences: https://example.test/ref",
                            'seo_title' => 'English SEO title',
                            'seo_description' => 'English SEO description',
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]],
                ]],
            ], 200),
        ]);

        $source = $this->createSourceArticle('provider-translation-fixture');
        $provider = app(OpenAiArticleMachineTranslationProvider::class);

        $translated = $provider->translate($source, 'en');

        $this->assertSame('English title', $translated['title']);
        $this->assertSame('English excerpt', $translated['excerpt']);
        $this->assertStringContainsString('Translated body.', $translated['content_md']);
        $this->assertSame('English SEO title', $translated['seo_title']);
        $this->assertSame('English SEO description', $translated['seo_description']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            $body = $request->data();

            return $request->url() === 'https://api.openai.test/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && ($body['model'] ?? null) === 'gpt-4.1'
                && ($body['text']['format']['type'] ?? null) === 'json_schema';
        });
    }

    public function test_workflow_uses_configured_openai_article_translation_provider_binding(): void
    {
        config()->set('services.article_translation.provider', 'openai');
        config()->set('services.article_translation.openai.base_url', 'https://api.openai.test/v1');
        config()->set('services.article_translation.openai.api_key', 'test-key');
        config()->set('services.article_translation.openai.model', 'gpt-4.1');

        app()->forgetInstance(ArticleMachineTranslationProvider::class);
        app()->forgetInstance(OpenAiArticleMachineTranslationProvider::class);

        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'id' => 'resp_workflow_translation',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'title' => 'Workflow English title',
                            'excerpt' => 'Workflow English excerpt',
                            'content_md' => "Workflow English body.\n\nReferences: https://example.test/ref",
                            'seo_title' => 'Workflow SEO title',
                            'seo_description' => 'Workflow SEO description',
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]],
                ]],
            ], 200),
        ]);

        $source = $this->createSourceArticle('workflow-provider-fixture');

        $result = app(ArticleTranslationWorkflowService::class)->createMachineDraft($source, 'en');

        $article = $result['article']->fresh(['seoMeta']);
        $revision = $result['revision']->fresh();

        $this->assertSame('Workflow English title', $article?->title);
        $this->assertSame(Article::TRANSLATION_STATUS_MACHINE_DRAFT, $article?->translation_status);
        $this->assertSame('Workflow English body.'."\n\n".'References: https://example.test/ref', $revision?->content_md);
        $this->assertSame('Workflow SEO title', $article?->seoMeta?->seo_title);
        $this->assertSame('Workflow SEO description', $article?->seoMeta?->seo_description);

        $boundProvider = app(ArticleMachineTranslationProvider::class);
        $this->assertInstanceOf(OpenAiArticleMachineTranslationProvider::class, $boundProvider);
    }

    private function createSourceArticle(string $slug): Article
    {
        $source = Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => 'zh-CN',
            'translation_group_id' => 'article-source-'.$slug,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => '中文标题 '.$slug,
            'excerpt' => '中文摘要 '.$slug,
            'content_md' => "# 中文标题\n\n中文正文。\n\n参考文献：\n- https://example.test/ref",
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'source_article_id' => null,
            'translated_from_article_id' => null,
            'source_version_hash' => 'source-hash-'.$slug,
        ]);

        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $source->id,
            'locale' => 'zh-CN',
            'seo_title' => '中文 SEO '.$slug,
            'seo_description' => '中文 SEO 描述 '.$slug,
            'is_indexable' => true,
        ]);

        return $source->fresh(['seoMeta']);
    }
}
