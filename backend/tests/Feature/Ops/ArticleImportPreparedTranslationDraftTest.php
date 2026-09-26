<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ArticleImportPreparedTranslationDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_is_read_only_and_execute_creates_only_private_reviewable_draft(): void
    {
        $source = $this->source('prepared-translation-test');
        [$path, $sha, $confirm] = $this->package($source);

        try {
            $before = [Article::withoutGlobalScopes()->count(), ArticleTranslationRevision::withoutGlobalScopes()->count(), AuditLog::withoutGlobalScopes()->count()];
            $this->assertSame(0, Artisan::call('articles:import-prepared-translation-draft', [
                '--file' => $path, '--sha256' => $sha, '--dry-run' => true, '--json' => true,
            ]));
            $this->assertSame($before, [Article::withoutGlobalScopes()->count(), ArticleTranslationRevision::withoutGlobalScopes()->count(), AuditLog::withoutGlobalScopes()->count()]);

            $this->assertSame(0, Artisan::call('articles:import-prepared-translation-draft', [
                '--file' => $path, '--sha256' => $sha, '--execute' => true,
                '--confirm' => $confirm, '--json' => true,
            ]));
            $target = Article::withoutGlobalScopes()->where('locale', 'en')->where('slug', $source->slug)->firstOrFail();
            $this->assertSame('draft', $target->status);
            $this->assertSame(Article::TRANSLATION_STATUS_MACHINE_DRAFT, $target->translation_status);
            $this->assertFalse($target->is_public);
            $this->assertFalse($target->is_indexable);
            $this->assertFalse($target->sitemap_eligible);
            $this->assertFalse($target->llms_eligible);
            $this->assertNull($target->published_revision_id);
            $this->assertSame((int) $source->id, (int) $target->source_article_id);
            $this->assertSame((string) $source->source_version_hash, (string) $target->translated_from_version_hash);
            $this->assertSame(ArticleTranslationRevision::STATUS_MACHINE_DRAFT, $target->workingRevision?->revision_status);
            $this->assertSame('operator_supplied_ai_draft', $target->workingRevision?->authority_metadata_json['draft_origin']);
            $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'article_translation_prepared_draft_imported')->count());

            $this->assertSame(1, Artisan::call('articles:import-prepared-translation-draft', [
                '--file' => $path, '--sha256' => $sha, '--dry-run' => true, '--json' => true,
            ]));
            $this->assertSame('english_identity_collision', json_decode(Artisan::output(), true)['errors'][0]);
        } finally {
            unlink($path);
        }
    }

    public function test_stale_source_lock_and_existing_independent_english_slug_fail_closed(): void
    {
        $source = $this->source('prepared-translation-collision');
        [$path, $sha] = $this->package($source);

        try {
            Article::query()->create([
                'org_id' => 0,
                'slug' => (string) $source->slug,
                'locale' => 'en',
                'translation_group_id' => 'independent-english-group',
                'source_locale' => 'en',
                'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
                'title' => 'Independent English source',
                'content_md' => '# Independent English source',
                'status' => 'draft',
                'is_public' => false,
            ]);
            $this->assertSame(1, Artisan::call('articles:import-prepared-translation-draft', [
                '--file' => $path, '--sha256' => $sha, '--dry-run' => true,
            ]));
            $this->assertContains('english_identity_collision', json_decode(Artisan::output(), true)['errors']);

            Article::withoutGlobalScopes()->where('locale', 'en')->delete();
            $source->forceFill(['content_md' => "# Source\n\nChanged after package export."])->save();
            $this->assertSame(1, Artisan::call('articles:import-prepared-translation-draft', [
                '--file' => $path, '--sha256' => $sha, '--dry-run' => true,
            ]));
            $this->assertContains('source_lock_mismatch', json_decode(Artisan::output(), true)['errors']);
            $this->assertSame(0, Article::withoutGlobalScopes()->where('locale', 'en')->whereNull('deleted_at')->count());
        } finally {
            unlink($path);
        }
    }

    public function test_changed_published_revision_and_package_bytes_cannot_import(): void
    {
        $source = $this->source('prepared-translation-revision-drift');
        [$path, $sha, $confirm] = $this->package($source);

        try {
            file_put_contents($path, "\n", FILE_APPEND);
            $this->assertSame(1, Artisan::call('articles:import-prepared-translation-draft', [
                '--file' => $path, '--sha256' => $sha, '--dry-run' => true,
            ]));
            $this->assertContains('package_or_snapshot_invalid', json_decode(Artisan::output(), true)['errors']);

            [$path2, $sha2, $confirm2] = $this->package($source);
            try {
                $source->publishedRevision?->forceFill(['content_md' => '# Changed published source'])->save();
                $this->assertSame(1, Artisan::call('articles:import-prepared-translation-draft', [
                    '--file' => $path2, '--sha256' => $sha2, '--execute' => true,
                    '--confirm' => $confirm2,
                ]));
                $this->assertContains('source_revision_mismatch', json_decode(Artisan::output(), true)['errors']);
                $this->assertSame(0, Article::withoutGlobalScopes()->where('locale', 'en')->count());
                $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'article_translation_prepared_draft_imported')->count());
            } finally {
                unlink($path2);
            }
        } finally {
            unlink($path);
        }
    }

    private function source(string $slug): Article
    {
        $source = Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => 'zh-CN',
            'translation_group_id' => 'source-'.$slug,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => '中文源文',
            'excerpt' => '中文摘要',
            'content_md' => "# 中文源文\n\n有引用的正文。",
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
        ]);
        $revision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $source->id,
            'source_article_id' => (int) $source->id,
            'translation_group_id' => (string) $source->translation_group_id,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => (string) $source->source_version_hash,
            'title' => (string) $source->title,
            'excerpt' => (string) $source->excerpt,
            'content_md' => (string) $source->content_md,
            'published_at' => now(),
        ]);
        $source->forceFill([
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ])->saveQuietly();

        return $source->fresh();
    }

    /** @return array{string,string,string} */
    private function package(Article $source): array
    {
        $package = [
            'schema' => 'fermat_article_translation_draft_v1',
            'source' => [
                'article_id' => (int) $source->id,
                'translation_group_id' => (string) $source->translation_group_id,
                'slug' => (string) $source->slug,
                'locale' => 'zh-CN',
                'source_version_hash' => (string) $source->source_version_hash,
                'content_sha256' => hash('sha256', (string) $source->content_md),
                'published_revision_id' => (int) $source->published_revision_id,
                'published_revision_content_sha256' => hash('sha256', (string) $source->publishedRevision?->content_md),
                'published_revision_updated_at' => (string) $source->publishedRevision?->getRawOriginal('updated_at'),
                'updated_at' => (string) $source->getRawOriginal('updated_at'),
            ],
            'translation' => [
                'locale' => 'en',
                'title' => 'Prepared English title',
                'excerpt' => 'Prepared English summary',
                'content_md' => "# Prepared English title\n\nDraft body with a source reference.",
                'seo_title' => 'Prepared English SEO title',
                'seo_description' => 'Prepared English SEO description',
            ],
        ];
        $path = tempnam(sys_get_temp_dir(), 'prepared-translation-');
        file_put_contents($path, json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $sha = hash_file('sha256', $path);

        return [$path, $sha, sprintf(
            'Import private English draft for source %d in group %s with package %s.',
            $source->id, $source->translation_group_id, $sha,
        )];
    }
}
