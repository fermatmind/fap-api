<?php

declare(strict_types=1);

namespace Tests\Feature\ContentPages;

use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Services\Cms\CmsTranslationWorkflowException;
use App\Services\Cms\SiblingTranslationWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ContentPagePreparedTraditionalDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_offline_draft_uses_native_lineage_without_publishing_or_source_writes(): void
    {
        Http::preventStrayRequests();
        $source = $this->source();
        $original = $source->getRawOriginal();
        $target = app(SiblingTranslationWorkflowService::class)->createPreparedContentPageDraft(
            $source->id, $source->freshSourceVersionHash(), $this->fields(),
        );

        $this->assertSame($original, $source->fresh()->getRawOriginal());
        $this->assertSame('zh-TW', $target->locale);
        $this->assertSame('zh-CN', $target->source_locale);
        $this->assertSame($source->id, $target->source_content_id);
        $this->assertSame($source->translation_group_id, $target->translation_group_id);
        $this->assertSame($source->freshSourceVersionHash(), $target->translated_from_version_hash);
        $this->assertSame('/zh-TW/about', $target->path);
        $this->assertSame('/zh-TW/about', $target->canonical_path);
        $this->assertSame('draft', $target->status);
        $this->assertFalse($target->is_public);
        $this->assertFalse($target->is_indexable);
        $this->assertFalse($target->publish_allowed);
        $this->assertNull($target->operator_approved_at);
        $this->assertNull($target->published_revision_id);
        $revision = CmsTranslationRevision::query()->withoutGlobalScopes()->findOrFail($target->working_revision_id);
        $this->assertSame('machine_draft', $revision->revision_status);
        $this->assertSame($source->id, $revision->source_content_id);
        $this->assertSame('關於費馬測試', $revision->payload_json['title']);
        $this->getJson('/api/v0.5/content-pages/about?locale=zh-TW')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_stale_source_hash_fails_without_creating_target_or_revisions(): void
    {
        $source = $this->source();
        $hash = $source->freshSourceVersionHash();
        $source->forceFill(['summary' => '新的来源。'])->save();
        $this->assertRejectedWithoutWrites(fn () => app(SiblingTranslationWorkflowService::class)
            ->createPreparedContentPageDraft($source->id, $hash, $this->fields()));
    }

    public function test_existing_target_and_unapproved_source_are_rejected_without_overwrites(): void
    {
        $source = $this->source();
        $workflow = app(SiblingTranslationWorkflowService::class);
        $target = $workflow->createPreparedContentPageDraft($source->id, $source->freshSourceVersionHash(), $this->fields());
        $before = $target->getRawOriginal();
        $this->assertRejectedWithoutWrites(fn () => $workflow->createPreparedContentPageDraft($source->id, $source->freshSourceVersionHash(), $this->fields()));
        $this->assertSame($before, $target->fresh()->getRawOriginal());
        $source->forceFill(['review_state' => 'draft'])->save();
        $this->assertRejectedWithoutWrites(fn () => $workflow->createPreparedContentPageDraft($source->id, $source->freshSourceVersionHash(), $this->fields()));
    }

    public function test_payload_cannot_set_publication_state_or_import_incomplete_body(): void
    {
        $source = $this->source();
        foreach ([$this->fields() + ['is_public' => true], array_replace($this->fields(), ['content_md' => ''])] as $fields) {
            $this->assertRejectedWithoutWrites(fn () => app(SiblingTranslationWorkflowService::class)
                ->createPreparedContentPageDraft($source->id, $source->freshSourceVersionHash(), $fields));
        }
    }

    public function test_outer_transaction_failure_leaves_no_draft_or_revision(): void
    {
        $source = $this->source();
        $before = CmsTranslationRevision::query()->withoutGlobalScopes()->count();
        try {
            DB::transaction(function () use ($source): void {
                app(SiblingTranslationWorkflowService::class)->createPreparedContentPageDraft(
                    $source->id, $source->freshSourceVersionHash(), $this->fields(),
                );
                throw new \RuntimeException('injected business transaction failure');
            });
            $this->fail('Expected transaction failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('injected business transaction failure', $error->getMessage());
        }
        $this->assertSame(1, ContentPage::query()->withoutGlobalScopes()->count());
        $this->assertSame($before, CmsTranslationRevision::query()->withoutGlobalScopes()->count());
    }

    private function assertRejectedWithoutWrites(callable $operation): void
    {
        $pages = ContentPage::query()->withoutGlobalScopes()->get()->map->getRawOriginal()->all();
        $revisions = CmsTranslationRevision::query()->withoutGlobalScopes()->get()->map->getRawOriginal()->all();
        try {
            $operation();
            $this->fail('Expected prepared import rejection');
        } catch (CmsTranslationWorkflowException) {
            $this->assertSame($pages, ContentPage::query()->withoutGlobalScopes()->get()->map->getRawOriginal()->all());
            $this->assertSame($revisions, CmsTranslationRevision::query()->withoutGlobalScopes()->get()->map->getRawOriginal()->all());
        }
    }

    private function source(): ContentPage
    {
        return ContentPage::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'slug' => 'about', 'path' => '/about', 'locale' => 'zh-CN',
            'kind' => 'company', 'page_type' => 'company', 'template' => 'company', 'animation_profile' => 'none',
            'title' => '关于费马测试', 'summary' => '关于我们。', 'content_md' => "## 我们\n\n兴趣不是能力。", 'content_html' => '',
            'seo_title' => '关于费马测试', 'seo_description' => '关于我们。', 'meta_description' => '关于我们。',
            'status' => 'published', 'review_state' => 'approved', 'is_public' => true, 'is_indexable' => true,
        ])->refresh();
    }

    private function fields(): array
    {
        return [
            'title' => '關於費馬測試', 'kicker' => '公司', 'summary' => '關於我們。',
            'content_md' => "## 我們\n\n興趣不是能力。", 'headings' => ['我們'], 'faq_items' => [],
            'seo_title' => '關於費馬測試', 'seo_description' => '關於我們。', 'meta_description' => '關於我們。',
        ];
    }
}
