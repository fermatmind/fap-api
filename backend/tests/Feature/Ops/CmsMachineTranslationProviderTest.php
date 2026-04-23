<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Models\ContentPage;
use App\Models\InterpretationGuide;
use App\Models\SupportArticle;
use App\Services\Cms\OpenAiCmsMachineTranslationProvider;
use App\Services\Cms\SiblingTranslationWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CmsMachineTranslationProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_openai_cms_machine_translation_provider_reports_configuration_state(): void
    {
        config()->set('services.cms_translation.openai.api_key', 'test-key');
        config()->set('services.cms_translation.openai.model', 'gpt-4.1');

        $provider = app(OpenAiCmsMachineTranslationProvider::class);

        $this->assertTrue($provider->supports('support_article'));
        $this->assertTrue($provider->supports('interpretation_guide'));
        $this->assertTrue($provider->supports('content_page'));
        $this->assertTrue($provider->isConfigured());
        $this->assertNull($provider->unavailableReason('support_article'));
    }

    public function test_openai_cms_machine_translation_provider_requires_api_key(): void
    {
        config()->set('services.cms_translation.openai.api_key', '');
        config()->set('services.cms_translation.openai.model', 'gpt-4.1');

        $provider = app(OpenAiCmsMachineTranslationProvider::class);

        $this->assertFalse($provider->isConfigured());
        $this->assertSame(
            'CMS machine translation provider is not configured. Set CMS_TRANSLATION_OPENAI_API_KEY (or OPENAI_API_KEY).',
            $provider->unavailableReason('support_article')
        );
    }

    public function test_openai_cms_machine_translation_provider_translates_support_article_with_structured_output(): void
    {
        config()->set('services.cms_translation.openai.base_url', 'https://api.openai.test/v1');
        config()->set('services.cms_translation.openai.api_key', 'test-key');
        config()->set('services.cms_translation.openai.model', 'gpt-4.1');

        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'id' => 'resp_test_translation',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'title' => 'English support title',
                            'summary' => 'English support summary',
                            'body_md' => "# Help\n\nTranslated support body.",
                            'seo_title' => 'English support SEO title',
                            'seo_description' => 'English support SEO description',
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]],
                ]],
            ], 200),
        ]);

        $source = $this->createSourceSupportArticle('recover-report');
        $provider = app(OpenAiCmsMachineTranslationProvider::class);

        $translated = $provider->translate(
            'support_article',
            $source,
            [
                'title' => $source->title,
                'summary' => $source->summary,
                'body_md' => $source->body_md,
                'seo_title' => $source->seo_title,
                'seo_description' => $source->seo_description,
            ],
            'en'
        );

        $this->assertSame('English support title', $translated['title']);
        $this->assertSame('English support summary', $translated['summary']);
        $this->assertStringContainsString('Translated support body.', $translated['body_md']);
        $this->assertSame('English support SEO title', $translated['seo_title']);
        $this->assertSame('English support SEO description', $translated['seo_description']);
    }

    public function test_workflow_uses_configured_openai_cms_translation_provider_binding(): void
    {
        config()->set('services.cms_translation.openai.base_url', 'https://api.openai.test/v1');
        config()->set('services.cms_translation.openai.api_key', 'test-key');
        config()->set('services.cms_translation.openai.model', 'gpt-4.1');

        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'id' => 'resp_workflow_translation',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'title' => 'Workflow EN support title',
                            'summary' => 'Workflow EN support summary',
                            'body_md' => 'Workflow EN support body.',
                            'seo_title' => 'Workflow EN support SEO title',
                            'seo_description' => 'Workflow EN support SEO description',
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]],
                ]],
            ], 200),
        ]);

        $source = $this->createSourceSupportArticle('workflow-support');

        $created = app(SiblingTranslationWorkflowService::class)->createMachineDraft('support_article', $source, 'en');

        $this->assertSame('Workflow EN support title', (string) $created->title);
        $this->assertSame(SupportArticle::TRANSLATION_STATUS_MACHINE_DRAFT, (string) $created->translation_status);
        $this->assertSame('Workflow EN support SEO title', (string) $created->seo_title);
        $this->assertNotNull($created->working_revision_id);
    }

    public function test_openai_cms_machine_translation_provider_normalizes_connection_failures(): void
    {
        config()->set('services.cms_translation.openai.base_url', 'https://api.openai.test/v1');
        config()->set('services.cms_translation.openai.api_key', 'test-key');
        config()->set('services.cms_translation.openai.model', 'gpt-4.1');

        Http::fake(static fn () => throw new ConnectionException('connection refused'));

        $source = $this->createSourceContentPage('help-privacy', '/help/privacy');
        $provider = app(OpenAiCmsMachineTranslationProvider::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenAI CMS translation request failed: connection error.');

        $provider->translate(
            'content_page',
            $source,
            [
                'title' => $source->title,
                'summary' => $source->summary,
                'body_md' => $source->content_md,
                'seo_title' => $source->seo_title,
                'seo_description' => $source->seo_description,
            ],
            'en'
        );
    }

    private function createSourceSupportArticle(string $slug): SupportArticle
    {
        return SupportArticle::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'title' => '中文 support '.$slug,
            'summary' => 'support summary',
            'body_md' => 'support body',
            'body_html' => '<p>support body</p>',
            'support_category' => SupportArticle::CATEGORIES[0],
            'support_intent' => SupportArticle::INTENTS[0],
            'locale' => 'zh-CN',
            'translation_group_id' => 'support-'.$slug,
            'source_locale' => 'zh-CN',
            'translation_status' => SupportArticle::TRANSLATION_STATUS_SOURCE,
            'status' => SupportArticle::STATUS_PUBLISHED,
            'review_state' => SupportArticle::REVIEW_APPROVED,
            'published_at' => now(),
            'seo_title' => 'support seo',
            'seo_description' => 'support seo description',
            'canonical_path' => '/support/articles/'.$slug,
        ]);
    }

    private function createSourceInterpretationGuide(string $slug): InterpretationGuide
    {
        return InterpretationGuide::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'title' => '中文 guide '.$slug,
            'summary' => 'guide summary',
            'body_md' => 'guide body',
            'body_html' => '<p>guide body</p>',
            'test_family' => InterpretationGuide::TEST_FAMILIES[0],
            'result_context' => InterpretationGuide::RESULT_CONTEXTS[0],
            'audience' => 'general',
            'locale' => 'zh-CN',
            'translation_group_id' => 'guide-'.$slug,
            'source_locale' => 'zh-CN',
            'translation_status' => InterpretationGuide::TRANSLATION_STATUS_SOURCE,
            'status' => InterpretationGuide::STATUS_PUBLISHED,
            'review_state' => InterpretationGuide::REVIEW_APPROVED,
            'published_at' => now(),
            'seo_title' => 'guide seo',
            'seo_description' => 'guide seo description',
            'canonical_path' => '/support/guides/'.$slug,
        ]);
    }

    private function createSourceContentPage(string $slug, string $path): ContentPage
    {
        return ContentPage::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'path' => $path,
            'canonical_path' => $path,
            'locale' => 'zh-CN',
            'kind' => ContentPage::KIND_HELP,
            'page_type' => 'standard',
            'template' => 'default',
            'animation_profile' => 'default',
            'title' => '中文 page '.$slug,
            'summary' => 'page summary',
            'content_md' => 'page body',
            'content_html' => '<p>page body</p>',
            'translation_group_id' => 'page-'.$slug,
            'source_locale' => 'zh-CN',
            'translation_status' => ContentPage::TRANSLATION_STATUS_SOURCE,
            'status' => ContentPage::STATUS_PUBLISHED,
            'review_state' => 'approved',
            'is_public' => true,
            'published_at' => now(),
            'seo_title' => 'page seo',
            'seo_description' => 'page seo description',
            'source_version_hash' => 'page-source-'.$slug,
        ]);
    }
}
