<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Contracts\Cms\CmsMachineTranslationProvider;
use App\Models\SupportArticle;
use App\Services\Cms\RowBackedRevisionWorkspace;
use App\Services\Cms\SiblingTranslationWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CmsTranslationShadowRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cms_translation.providers.support_article', FakeSupportArticleTranslationProvider::class);
        app()->bind(FakeSupportArticleTranslationProvider::class, fn (): FakeSupportArticleTranslationProvider => new FakeSupportArticleTranslationProvider);
    }

    public function test_resync_creates_new_working_revision_without_overwriting_public_row(): void
    {
        $workspace = app(RowBackedRevisionWorkspace::class);
        $workflow = app(SiblingTranslationWorkflowService::class);

        $source = $this->createSourceSupportArticle();
        $target = $this->createPublishedTargetTranslation($source);
        $workspace->ensureInitialRevision('support_article', $target);
        $target = $target->fresh();

        $publishedRevisionId = (int) $target->published_revision_id;
        $publishedBody = (string) $target->body_md;

        $source->forceFill([
            'title' => 'Updated zh source',
            'body_md' => 'Updated zh source body',
            'body_html' => '<p>Updated zh source body</p>',
        ])->save();

        $resynced = $workflow->resyncFromSource('support_article', $target->fresh());

        $this->assertSame($publishedRevisionId, (int) $resynced->published_revision_id);
        $this->assertNotSame($publishedRevisionId, (int) $resynced->working_revision_id);
        $this->assertSame($publishedBody, (string) $resynced->body_md);
        $this->assertDatabaseHas('support_articles', [
            'id' => (int) $resynced->id,
            'body_md' => $publishedBody,
            'published_revision_id' => $publishedRevisionId,
        ]);

        $editorRecord = $workspace->editorRecord('support_article', $resynced->fresh());
        $this->assertSame('Resynced machine draft body', (string) $editorRecord->body_md);
        $this->assertSame('Resynced machine draft title', (string) $editorRecord->title);
    }

    public function test_publish_promotes_working_revision_to_public_row(): void
    {
        $workspace = app(RowBackedRevisionWorkspace::class);
        $workflow = app(SiblingTranslationWorkflowService::class);

        $source = $this->createSourceSupportArticle();
        $target = $this->createPublishedTargetTranslation($source);
        $workspace->ensureInitialRevision('support_article', $target);

        $source->forceFill([
            'title' => 'Updated zh source',
            'body_md' => 'Updated zh source body',
            'body_html' => '<p>Updated zh source body</p>',
        ])->save();

        $resynced = $workflow->resyncFromSource('support_article', $target->fresh());
        $publishedRevisionId = (int) $resynced->published_revision_id;

        $workflow->promoteToHumanReview('support_article', $resynced->fresh());
        $workflow->approveTranslation('support_article', $resynced->fresh());
        $published = $workflow->publishTranslation('support_article', $resynced->fresh());

        $this->assertNotSame($publishedRevisionId, (int) $published->published_revision_id);
        $this->assertSame((int) $published->working_revision_id, (int) $published->published_revision_id);
        $this->assertSame('Resynced machine draft body', (string) $published->body_md);

        $this->getJson('/api/v0.5/support/articles/recover-report?locale=en')
            ->assertOk()
            ->assertJsonPath('article.body_md', 'Resynced machine draft body')
            ->assertJsonPath('article.title', 'Resynced machine draft title');
    }

    private function createSourceSupportArticle(): SupportArticle
    {
        return SupportArticle::query()->create([
            'org_id' => 0,
            'slug' => 'recover-report',
            'title' => '中文源文',
            'summary' => '源文摘要',
            'body_md' => '源文正文',
            'body_html' => '<p>源文正文</p>',
            'support_category' => 'reports',
            'support_intent' => 'recover_report',
            'locale' => 'zh-CN',
            'translation_group_id' => 'support-recover-report',
            'source_locale' => 'zh-CN',
            'translation_status' => SupportArticle::TRANSLATION_STATUS_SOURCE,
            'status' => SupportArticle::STATUS_PUBLISHED,
            'review_state' => SupportArticle::REVIEW_APPROVED,
            'published_at' => now(),
            'seo_title' => '中文 SEO',
            'seo_description' => '中文 SEO 摘要',
            'canonical_path' => '/support/recover-report',
        ]);
    }

    private function createPublishedTargetTranslation(SupportArticle $source): SupportArticle
    {
        return SupportArticle::query()->create([
            'org_id' => 0,
            'slug' => 'recover-report',
            'title' => 'Published EN title',
            'summary' => 'Published EN summary',
            'body_md' => 'Published EN body',
            'body_html' => '<p>Published EN body</p>',
            'support_category' => 'reports',
            'support_intent' => 'recover_report',
            'locale' => 'en',
            'translation_group_id' => (string) $source->translation_group_id,
            'source_locale' => 'zh-CN',
            'translation_status' => SupportArticle::TRANSLATION_STATUS_PUBLISHED,
            'source_content_id' => (int) $source->id,
            'translated_from_version_hash' => (string) $source->source_version_hash,
            'status' => SupportArticle::STATUS_PUBLISHED,
            'review_state' => SupportArticle::REVIEW_APPROVED,
            'published_at' => now(),
            'seo_title' => 'Published EN SEO',
            'seo_description' => 'Published EN SEO description',
            'canonical_path' => '/support/recover-report',
        ]);
    }
}

final class FakeSupportArticleTranslationProvider implements CmsMachineTranslationProvider
{
    public function supports(string $contentType): bool
    {
        return $contentType === 'support_article';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function unavailableReason(string $contentType): ?string
    {
        return null;
    }

    public function translate(string $contentType, object $sourceRecord, array $normalizedSource, string $targetLocale): array
    {
        return [
            'title' => 'Resynced machine draft title',
            'summary' => 'Resynced machine draft summary',
            'body_md' => 'Resynced machine draft body',
            'seo_title' => 'Resynced machine draft SEO',
            'seo_description' => 'Resynced machine draft SEO description',
        ];
    }
}
