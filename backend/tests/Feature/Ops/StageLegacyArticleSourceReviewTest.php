<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class StageLegacyArticleSourceReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_stage_is_private_read_only_in_preview_and_reversible_before_review(): void
    {
        [$source, $published, $target, $targetPublished] = $this->legacyPair();
        [$path, $sha, $confirm] = $this->package($source, $published, $target, $targetPublished);
        try {
            $sourceBefore = $source->getAttributes();
            $publishedBefore = $published->getAttributes();
            $targetBefore = $target->getAttributes();
            $targetPublishedBefore = $targetPublished->getAttributes();
            $publicBefore = $this->getJson('/api/v0.5/articles/legacy-paired-source?locale=zh-CN')->assertOk()->json();
            $englishBefore = $this->getJson('/api/v0.5/articles/legacy-paired-source?locale=en')->assertOk()->json();
            $counts = [ArticleTranslationRevision::withoutGlobalScopes()->count(), AuditLog::withoutGlobalScopes()->count()];
            $this->assertSame(0, Artisan::call('articles:stage-legacy-source-review', [
                '--file' => $path, '--sha256' => $sha, '--dry-run' => true,
            ]));
            $this->assertSame($counts, [ArticleTranslationRevision::withoutGlobalScopes()->count(), AuditLog::withoutGlobalScopes()->count()]);
            $this->assertSame($sourceBefore, $source->fresh()->getAttributes());

            $this->assertSame(0, Artisan::call('articles:stage-legacy-source-review', [
                '--file' => $path, '--sha256' => $sha, '--execute' => true, '--confirm' => $confirm,
            ]), Artisan::output());
            $source = $source->fresh();
            $draft = $source->workingRevision;
            $this->assertTrue($source->isSourceArticle());
            $this->assertSame((int) $published->id, (int) $source->published_revision_id);
            $this->assertNotSame((int) $published->id, (int) $draft->id);
            $this->assertSame(ArticleTranslationRevision::STATUS_HUMAN_REVIEW, $draft->revision_status);
            $this->assertSame($source->computeSourceVersionHash(), $draft->source_version_hash);
            $this->assertSame((string) $published->seo_title, (string) $draft->seo_title);
            $this->assertSame($publishedBefore, $published->fresh()->getAttributes());
            $this->assertSame($targetBefore, $target->fresh()->getAttributes());
            $this->assertSame($targetPublishedBefore, $targetPublished->fresh()->getAttributes());
            $this->assertSame($publicBefore, $this->getJson('/api/v0.5/articles/legacy-paired-source?locale=zh-CN')->assertOk()->json());
            $this->assertSame($englishBefore, $this->getJson('/api/v0.5/articles/legacy-paired-source?locale=en')->assertOk()->json());
            $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'article_legacy_source_review_staged')->count());
            $this->assertSame(1, Artisan::call('articles:stage-legacy-source-review', [
                '--file' => $path, '--sha256' => $sha, '--dry-run' => true,
            ]));

            $audit = AuditLog::withoutGlobalScopes()->where('action', 'article_legacy_source_review_staged')->firstOrFail();
            $restore = [
                '--source-id' => (int) $source->id,
                '--audit-id' => (int) $audit->id,
                '--draft-id' => (int) $draft->id,
                '--source-updated-at' => (string) $source->getRawOriginal('updated_at'),
                '--draft-updated-at' => (string) $draft->getRawOriginal('updated_at'),
                '--target-updated-at' => (string) $target->getRawOriginal('updated_at'),
            ];
            $this->assertSame(0, Artisan::call('articles:restore-legacy-source-review-stage', [
                ...$restore, '--dry-run' => true,
            ]));
            $this->assertSame(0, Artisan::call('articles:restore-legacy-source-review-stage', [
                ...$restore, '--execute' => true,
                '--confirm' => sprintf('Restore legacy Article source %d from stage audit %d and draft %d.',
                    $source->id, $audit->id, $draft->id),
            ]));
            $source = $source->fresh();
            $this->assertSame((string) $sourceBefore['translation_status'], (string) $source->translation_status);
            $this->assertSame((string) $sourceBefore['source_version_hash'], (string) $source->source_version_hash);
            $this->assertSame((int) $published->id, (int) $source->working_revision_id);
            $this->assertSame((int) $published->id, (int) $source->published_revision_id);
            $this->assertSame(ArticleTranslationRevision::STATUS_ARCHIVED, $draft->fresh()->revision_status);
            $this->assertSame($publishedBefore, $published->fresh()->getAttributes());
            $this->assertSame($targetBefore, $target->fresh()->getAttributes());
            $this->assertSame($targetPublishedBefore, $targetPublished->fresh()->getAttributes());
            $this->assertSame($publicBefore, $this->getJson('/api/v0.5/articles/legacy-paired-source?locale=zh-CN')->assertOk()->json());
            $this->assertSame($englishBefore, $this->getJson('/api/v0.5/articles/legacy-paired-source?locale=en')->assertOk()->json());
        } finally {
            unlink($path);
        }
    }

    public function test_drift_or_review_rejects_execution_and_restore_without_partial_writes(): void
    {
        [$source, $published, $target, $targetPublished] = $this->legacyPair();
        [$path, $sha, $confirm] = $this->package($source, $published, $target, $targetPublished);
        try {
            $target->forceFill(['title' => 'English title changed'])->saveQuietly();
            $this->assertSame(1, Artisan::call('articles:stage-legacy-source-review', [
                '--file' => $path, '--sha256' => $sha, '--execute' => true, '--confirm' => $confirm,
            ]));
            $this->assertSame(2, ArticleTranslationRevision::withoutGlobalScopes()->count());
            $this->assertSame(Article::TRANSLATION_STATUS_APPROVED, $source->fresh()->translation_status);
            $target->forceFill(['title' => 'English title'])->saveQuietly();
            [$path2, $sha2, $confirm2] = $this->package($source->fresh(), $published, $target->fresh(), $targetPublished);
            try {
                $this->assertSame(0, Artisan::call('articles:stage-legacy-source-review', [
                    '--file' => $path2, '--sha256' => $sha2, '--execute' => true, '--confirm' => $confirm2,
                ]), Artisan::output());
                $source = $source->fresh();
                $draft = $source->workingRevision;
                $audit = AuditLog::withoutGlobalScopes()->where('action', 'article_legacy_source_review_staged')->firstOrFail();
                $draft->forceFill(['reviewed_at' => now()])->save();
                $this->assertSame(1, Artisan::call('articles:restore-legacy-source-review-stage', [
                    '--source-id' => (int) $source->id,
                    '--audit-id' => (int) $audit->id,
                    '--draft-id' => (int) $draft->id,
                    '--source-updated-at' => (string) $source->getRawOriginal('updated_at'),
                    '--draft-updated-at' => (string) $draft->getRawOriginal('updated_at'),
                    '--target-updated-at' => (string) $target->getRawOriginal('updated_at'),
                    '--dry-run' => true,
                ]));
                $this->assertTrue($source->fresh()->isSourceArticle());
                $this->assertSame(ArticleTranslationRevision::STATUS_HUMAN_REVIEW, $draft->fresh()->revision_status);
            } finally {
                unlink($path2);
            }
        } finally {
            unlink($path);
        }
    }

    public function test_independent_english_identity_collision_blocks_staging(): void
    {
        [$source, $published, $target, $targetPublished] = $this->legacyPair();
        [$path, $sha] = $this->package($source, $published, $target, $targetPublished);
        try {
            Article::query()->create([
                'org_id' => 0, 'slug' => 'second-linked-english-target', 'locale' => 'en',
                'translation_group_id' => 'independent-english-source', 'source_locale' => 'zh-CN',
                'source_article_id' => (int) $source->id,
                'translated_from_article_id' => (int) $source->id,
                'translation_status' => Article::TRANSLATION_STATUS_MACHINE_DRAFT,
                'title' => 'Second target', 'content_md' => 'Independent content',
                'status' => 'draft', 'is_public' => false,
            ]);
            $this->assertSame(1, Artisan::call('articles:stage-legacy-source-review', [
                '--file' => $path, '--sha256' => $sha, '--dry-run' => true,
            ]));
            $this->assertContains('english_identity_collision', json_decode(Artisan::output(), true)['errors']);
            $this->assertSame(2, ArticleTranslationRevision::withoutGlobalScopes()->count());
        } finally {
            unlink($path);
        }
    }

    public function test_existing_approved_source_work_is_preserved(): void
    {
        [$source, $published, $target, $targetPublished] = $this->legacyPair();
        [$path, $sha] = $this->package($source, $published, $target, $targetPublished);
        try {
            ArticleTranslationRevision::query()->create([
                'org_id' => 0, 'article_id' => (int) $source->id,
                'source_article_id' => (int) $source->id,
                'translation_group_id' => (string) $source->translation_group_id,
                'locale' => 'zh-CN', 'source_locale' => 'zh-CN', 'revision_number' => 2,
                'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
                'source_version_hash' => str_repeat('d', 64),
                'title' => 'Previously reviewed source work', 'content_md' => '## Review work',
            ]);
            $this->assertSame(1, Artisan::call('articles:stage-legacy-source-review', [
                '--file' => $path, '--sha256' => $sha, '--dry-run' => true,
            ]));
            $this->assertContains('existing_active_source_revision', json_decode(Artisan::output(), true)['errors']);
            $this->assertSame(3, ArticleTranslationRevision::withoutGlobalScopes()->count());
        } finally {
            unlink($path);
        }
    }

    /** @return array{Article,ArticleTranslationRevision,Article,ArticleTranslationRevision} */
    private function legacyPair(): array
    {
        $source = Article::query()->create([
            'org_id' => 0, 'slug' => 'legacy-paired-source', 'locale' => 'zh-CN',
            'translation_group_id' => 'legacy-paired-source', 'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_APPROVED,
            'title' => '中文源文', 'excerpt' => '中文摘要', 'content_md' => "## 中文源文\n\n正文。",
            'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'published_at' => now(),
        ]);
        $published = ArticleTranslationRevision::query()->create([
            'org_id' => 0, 'article_id' => (int) $source->id, 'source_article_id' => (int) $source->id,
            'translation_group_id' => (string) $source->translation_group_id,
            'locale' => 'zh-CN', 'source_locale' => 'zh-CN', 'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => str_repeat('a', 64), 'translated_from_version_hash' => str_repeat('a', 64),
            'title' => (string) $source->title, 'excerpt' => (string) $source->excerpt,
            'content_md' => (string) $source->content_md,
            'seo_title' => '公开 SEO 标题', 'seo_description' => '公开 SEO 描述', 'published_at' => now(),
        ]);
        $source->forceFill([
            'source_version_hash' => str_repeat('b', 64),
            'working_revision_id' => (int) $published->id,
            'published_revision_id' => (int) $published->id,
        ])->saveQuietly();
        $target = Article::query()->create([
            'org_id' => 0, 'slug' => 'legacy-paired-source', 'locale' => 'en',
            'translation_group_id' => (string) $source->translation_group_id,
            'source_locale' => 'zh-CN', 'source_article_id' => (int) $source->id,
            'translated_from_article_id' => (int) $source->id,
            'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED,
            'title' => 'English title', 'excerpt' => 'English summary', 'content_md' => '## English source',
            'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'published_at' => now(),
        ]);
        $targetPublished = ArticleTranslationRevision::query()->create([
            'org_id' => 0, 'article_id' => (int) $target->id, 'source_article_id' => (int) $source->id,
            'translation_group_id' => (string) $source->translation_group_id,
            'locale' => 'en', 'source_locale' => 'zh-CN', 'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => str_repeat('c', 64), 'translated_from_version_hash' => str_repeat('a', 64),
            'title' => (string) $target->title, 'excerpt' => (string) $target->excerpt,
            'content_md' => (string) $target->content_md, 'published_at' => now(),
        ]);
        $target->forceFill(['working_revision_id' => (int) $targetPublished->id,
            'published_revision_id' => (int) $targetPublished->id])->saveQuietly();

        return [$source->fresh(), $published->fresh(), $target->fresh(), $targetPublished->fresh()];
    }

    /** @return array{string,string,string} */
    private function package(Article $source, ArticleTranslationRevision $published, Article $target, ArticleTranslationRevision $targetPublished): array
    {
        $package = [
            'schema' => 'fermat_legacy_article_source_review_stage_v1',
            'source_id' => (int) $source->id,
            'group_id' => (string) $source->translation_group_id,
            'slug' => (string) $source->slug,
            'source_hash' => (string) $source->source_version_hash,
            'computed_source_hash' => $source->computeSourceVersionHash(),
            'source_body_sha256' => hash('sha256', (string) $source->content_md),
            'source_updated_at' => (string) $source->getRawOriginal('updated_at'),
            'source_record_sha256' => $this->recordHash($source),
            'published_revision_id' => (int) $published->id,
            'published_revision_hash' => (string) $published->source_version_hash,
            'published_revision_body_sha256' => hash('sha256', (string) $published->content_md),
            'published_revision_updated_at' => (string) $published->getRawOriginal('updated_at'),
            'published_record_sha256' => $this->recordHash($published),
            'target_id' => (int) $target->id,
            'target_hash' => (string) $target->source_version_hash,
            'target_updated_at' => (string) $target->getRawOriginal('updated_at'),
            'target_record_sha256' => $this->recordHash($target),
            'target_published_revision_id' => (int) $targetPublished->id,
            'target_published_revision_hash' => (string) $targetPublished->source_version_hash,
            'target_published_revision_updated_at' => (string) $targetPublished->getRawOriginal('updated_at'),
            'target_published_record_sha256' => $this->recordHash($targetPublished),
        ];
        $path = tempnam(sys_get_temp_dir(), 'legacy-article-source-stage-');
        $bytes = json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $bytes);
        $sha = hash('sha256', $bytes);

        return [$path, $sha, sprintf('Stage legacy Article source %d and target %d for review with package %s.',
            $source->id, $target->id, $sha)];
    }

    private function recordHash(Article|ArticleTranslationRevision $record): string
    {
        $attributes = $record->getAttributes();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
