<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class Seo13BigFiveAuthorityLabelBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private const TARGETS = [
        [1, 'big-five-growth-guide', 'big5-v2-f29331ce54d2f28a7051702932c39aaf69d2bf61', 446],
        [2, 'big-five-narrative-portrait', 'big5-v2-8381cc150e7180b365a397ce3e3a25e2626b8970', 445],
    ];

    public function test_dry_run_binds_exact_missing_labels_without_writes(): void
    {
        $this->createCohort();
        $before = $this->authorityState();

        $exitCode = Artisan::call('articles:seo13-big-five-authority-label-bootstrap', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['production_write_execution']);
        $this->assertSame(2, $payload['target_count']);
        $this->assertSame(2, $payload['missing_count']);
        $this->assertSame(0, $payload['complete_count']);
        $this->assertTrue($payload['repair_required']);
        $this->assertTrue($payload['apply_supported']);
        $this->assertFalse($payload['readback_complete']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $payload['state_sha256']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $payload['target_set_sha256']);
        $this->assertSame($before, $this->authorityState());
    }

    public function test_execute_sets_exact_labels_and_preserves_article_surfaces(): void
    {
        $this->createCohort();
        $before = $this->authorityState();
        $preflight = $this->preflight();

        $exitCode = Artisan::call('articles:seo13-big-five-authority-label-bootstrap', [
            '--execute' => true,
            '--expected-state-sha256' => $preflight['state_sha256'],
            '--expected-target-set-sha256' => $preflight['target_set_sha256'],
            '--confirm' => $preflight['expected_confirmation'],
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['production_write_execution']);
        $this->assertSame(2, $payload['article_metadata_write_count']);
        $this->assertSame(1, $payload['audit_write_count']);
        $this->assertSame(0, $payload['article_body_write_count']);
        $this->assertSame(0, $payload['revision_write_count']);
        $this->assertSame(0, $payload['publication_write_count']);
        $this->assertSame(0, $payload['indexability_write_count']);
        $this->assertSame(0, $payload['schema_write_count']);
        $this->assertSame(0, $payload['hreflang_write_count']);
        $this->assertSame(0, $payload['revalidation_count']);
        $this->assertSame(0, $payload['sitemap_eligibility_write_count']);
        $this->assertSame(0, $payload['llms_eligibility_write_count']);
        $this->assertSame(0, $payload['search_submission_count']);
        $this->assertSame(0, $payload['queue_dispatch_count']);
        $this->assertFalse($payload['repair_required']);
        $this->assertTrue($payload['readback_complete']);

        $after = $this->authorityState();
        foreach (self::TARGETS as [$articleId]) {
            $this->assertSame('FermatMind Editorial', $after[$articleId]['author_name']);
            $this->assertSame('Content Review Desk', $after[$articleId]['reviewer_name']);
            foreach ([
                'title_hash',
                'body_hash',
                'published_revision_id',
                'article_status',
                'revision_status',
                'is_public',
                'is_indexable',
                'sitemap_eligible',
                'llms_eligible',
            ] as $field) {
                $this->assertSame($before[$articleId][$field], $after[$articleId][$field]);
            }
        }
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()
            ->where('action', 'seo13_big_five_authority_label_bootstrap')
            ->count());
    }

    public function test_partial_existing_label_fails_closed_without_writes(): void
    {
        $this->createCohort();
        Article::query()->withoutGlobalScopes()->whereKey(1)->update(['author_name' => 'Unexpected']);

        $exitCode = Artisan::call('articles:seo13-big-five-authority-label-bootstrap', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('big_five_authority_label_partial_or_drifted', array_column($payload['errors'], 'code'));
        $this->assertNull(Article::query()->withoutGlobalScopes()->whereKey(2)->value('author_name'));
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()
            ->where('action', 'seo13_big_five_authority_label_bootstrap')
            ->count());
    }

    public function test_revision_drift_after_preflight_rejects_apply(): void
    {
        $this->createCohort();
        $preflight = $this->preflight();
        ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereKey(446)
            ->update(['content_md' => 'drifted body']);

        $exitCode = Artisan::call('articles:seo13-big-five-authority-label-bootstrap', [
            '--execute' => true,
            '--expected-state-sha256' => $preflight['state_sha256'],
            '--expected-target-set-sha256' => $preflight['target_set_sha256'],
            '--confirm' => $preflight['expected_confirmation'],
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('expected_state_sha256_mismatch', array_column($payload['errors'], 'code'));
        foreach ([1, 2] as $articleId) {
            $article = Article::query()->withoutGlobalScopes()->findOrFail($articleId);
            $this->assertNull($article->author_name);
            $this->assertNull($article->reviewer_name);
        }
    }

    public function test_completed_readback_is_idempotent_and_not_applyable(): void
    {
        $this->createCohort();
        $preflight = $this->preflight();
        Artisan::call('articles:seo13-big-five-authority-label-bootstrap', [
            '--execute' => true,
            '--expected-state-sha256' => $preflight['state_sha256'],
            '--expected-target-set-sha256' => $preflight['target_set_sha256'],
            '--confirm' => $preflight['expected_confirmation'],
            '--json' => true,
        ]);

        $exitCode = Artisan::call('articles:seo13-big-five-authority-label-bootstrap', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame(0, $payload['missing_count']);
        $this->assertSame(2, $payload['complete_count']);
        $this->assertFalse($payload['repair_required']);
        $this->assertFalse($payload['apply_supported']);
        $this->assertTrue($payload['readback_complete']);
    }

    /**
     * @return array<string,mixed>
     */
    private function preflight(): array
    {
        Artisan::call('articles:seo13-big-five-authority-label-bootstrap', [
            '--dry-run' => true,
            '--json' => true,
        ]);

        return $this->jsonOutput();
    }

    private function createCohort(): void
    {
        foreach (self::TARGETS as [$articleId, $slug, $translationGroupId, $revisionId]) {
            $title = '文章 '.$articleId.' 标题';
            $excerpt = '文章 '.$articleId.' 摘要。';
            $body = '## 快速答案'.PHP_EOL.PHP_EOL.'这是文章 '.$articleId.' 的公开正文。';
            $publishedAt = Carbon::create(2026, 7, 27, 8, 0, 0, 'UTC');
            $reviewedAt = Carbon::create(2026, 7, 27, 7, 0, 0, 'UTC');

            $article = new Article;
            $article->forceFill([
                'id' => $articleId,
                'org_id' => 0,
                'category_id' => null,
                'author_admin_user_id' => 41,
                'author_name' => null,
                'reviewer_name' => null,
                'slug' => $slug,
                'locale' => 'zh-CN',
                'translation_group_id' => $translationGroupId,
                'source_locale' => 'zh-CN',
                'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
                'title' => $title,
                'excerpt' => $excerpt,
                'content_md' => $body,
                'content_html' => null,
                'cover_image_url' => null,
                'status' => 'published',
                'is_public' => true,
                'is_indexable' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'published_at' => $publishedAt,
                'scheduled_at' => null,
            ])->save();

            $revision = new ArticleTranslationRevision;
            $revision->forceFill([
                'id' => $revisionId,
                'org_id' => 0,
                'article_id' => $articleId,
                'source_article_id' => $articleId,
                'translation_group_id' => $translationGroupId,
                'locale' => 'zh-CN',
                'source_locale' => 'zh-CN',
                'revision_number' => 2,
                'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
                'source_version_hash' => hash('sha256', $body),
                'translated_from_version_hash' => hash('sha256', $body),
                'supersedes_revision_id' => null,
                'title' => $title,
                'excerpt' => $excerpt,
                'content_md' => $body,
                'seo_title' => $title,
                'seo_description' => $excerpt,
                'authority_metadata_json' => null,
                'created_by' => null,
                'reviewed_by' => 42,
                'reviewed_at' => $reviewedAt,
                'published_at' => $publishedAt,
            ])->save();

            $article->forceFill(['published_revision_id' => $revisionId])->saveQuietly();
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function authorityState(): array
    {
        $state = [];
        foreach (self::TARGETS as [$articleId]) {
            $article = Article::query()->withoutGlobalScopes()->findOrFail($articleId);
            $revision = ArticleTranslationRevision::query()
                ->withoutGlobalScopes()
                ->findOrFail((int) $article->published_revision_id);
            $state[$articleId] = [
                'author_name' => $article->author_name,
                'reviewer_name' => $article->reviewer_name,
                'title_hash' => hash('sha256', (string) $article->title),
                'body_hash' => hash('sha256', (string) $article->content_md),
                'published_revision_id' => (int) $article->published_revision_id,
                'article_status' => (string) $article->status,
                'revision_status' => (string) $revision->revision_status,
                'is_public' => (bool) $article->is_public,
                'is_indexable' => (bool) $article->is_indexable,
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
                'llms_eligible' => (bool) $article->llms_eligible,
            ];
        }

        return $state;
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, Artisan::output());

        return $payload;
    }
}
