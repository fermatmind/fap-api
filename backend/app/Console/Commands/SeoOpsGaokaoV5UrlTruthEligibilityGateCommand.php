<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class SeoOpsGaokaoV5UrlTruthEligibilityGateCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-ops-gaokao-v5-url-truth-eligibility-gate.v1';

    private const ARTICLE_ID = 55;

    private const CANONICAL_PATH = '/zh/articles/gaokao-major-choice-parent-conflict-riasec-course-checklist';

    protected $signature = 'seo-ops:gaokao-v5-url-truth-eligibility-gate
        {--article=55 : Exact supported article id; only 55 is allowed}
        {--expected-canonical-path=/zh/articles/gaokao-major-choice-parent-conflict-riasec-course-checklist : Exact canonical path lock}
        {--artifact-dir= : Directory for eligibility gate evidence}
        {--execute : Enable sitemap/llms eligibility for the locked article}
        {--confirm-eligibility= : Exact confirmation phrase required with --execute}
        {--json : Emit JSON summary}';

    protected $description = 'Gaokao v5 article 55 post-publish URL Truth eligibility gate; no content, publish, schema, hreflang, search, or revalidation side effects.';

    public function handle(AuditLogger $auditLogger): int
    {
        try {
            $summary = $this->buildSummary($auditLogger);
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary('runtime_error', $exception->getMessage());
        } catch (Throwable $exception) {
            $summary = $this->failureSummary('unexpected_error', $exception->getMessage());
        }

        $artifact = $this->writeEvidence($summary);
        if ($artifact !== null) {
            $summary['artifact'] = $artifact;
        }

        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSummary(AuditLogger $auditLogger): array
    {
        $execute = (bool) $this->option('execute');
        $articleId = (int) $this->option('article');
        $expectedCanonicalPath = $this->normalizePath((string) $this->option('expected-canonical-path'));
        $expectedConfirmation = $this->expectedConfirmation($articleId, $expectedCanonicalPath);
        $confirmation = trim((string) $this->option('confirm-eligibility'));
        $issues = [];

        if ($articleId !== self::ARTICLE_ID) {
            $issues[] = $this->issue('article', 'unsupported_article_id', 'This gate only supports article 55.');
        }
        if ($expectedCanonicalPath !== self::CANONICAL_PATH) {
            $issues[] = $this->issue('expected_canonical_path', 'unsupported_canonical_path', 'This gate only supports the Gaokao v5 article 55 canonical path.');
        }
        if ($execute && ! hash_equals($expectedConfirmation, $confirmation)) {
            $issues[] = $this->issue(
                'confirm_eligibility',
                'confirmation_mismatch',
                'Exact confirmation phrase is required before enabling URL Truth eligibility.',
                ['expected_confirmation' => $expectedConfirmation],
            );
        }

        $plan = $this->preflight($articleId, $expectedCanonicalPath);
        if ($plan === null) {
            $issues[] = $this->issue('article', 'article_not_found', 'Article 55 was not found.');
        } else {
            foreach ((array) ($plan['issues'] ?? []) as $issue) {
                if (is_array($issue)) {
                    $issues[] = $issue;
                }
            }
        }

        if ($issues !== []) {
            return $this->summary(
                ok: false,
                status: 'blocked',
                execute: $execute,
                action: 'will_skip',
                articleId: $articleId,
                expectedCanonicalPath: $expectedCanonicalPath,
                expectedConfirmation: $expectedConfirmation,
                plan: $plan,
                issues: $issues,
            );
        }

        if (! $execute) {
            return $this->summary(
                ok: true,
                status: 'planned',
                execute: false,
                action: 'would_enable_url_truth_eligibility',
                articleId: $articleId,
                expectedCanonicalPath: $expectedCanonicalPath,
                expectedConfirmation: $expectedConfirmation,
                plan: $plan,
                issues: [],
            );
        }

        $executedPlan = DB::transaction(function () use ($articleId, $expectedCanonicalPath): array {
            $lockedPlan = $this->preflight($articleId, $expectedCanonicalPath, lockForUpdate: true);
            if ($lockedPlan === null) {
                throw new RuntimeException('planned article disappeared before URL Truth eligibility write.');
            }
            if ((array) ($lockedPlan['issues'] ?? []) !== []) {
                $codes = collect((array) $lockedPlan['issues'])
                    ->map(static fn (mixed $issue): string => is_array($issue) ? (string) ($issue['code'] ?? '') : '')
                    ->filter()
                    ->implode(',');

                throw new RuntimeException('URL Truth eligibility preflight failed before write: '.$codes);
            }

            DB::table('articles')
                ->where('id', $articleId)
                ->update([
                    'sitemap_eligible' => true,
                    'llms_eligible' => true,
                    'updated_at' => now(),
                ]);

            return $this->preflight($articleId, $expectedCanonicalPath) ?? $lockedPlan;
        });

        $auditLogger->log(
            Request::create('/ops/seo/gaokao-v5/url-truth-eligibility-gate', 'POST'),
            'seo_ops_gaokao_v5_url_truth_eligibility_gate',
            'article',
            (string) $articleId,
            [
                'command' => 'seo-ops:gaokao-v5-url-truth-eligibility-gate',
                'article_id' => $articleId,
                'canonical_path' => $expectedCanonicalPath,
                'confirmation_sha256' => hash('sha256', $confirmation),
                'updates_scope' => ['articles.sitemap_eligible', 'articles.llms_eligible'],
                'no_content_change' => true,
                'no_publish' => true,
                'no_url_truth_write' => true,
                'no_search' => true,
                'no_schema_hreflang' => true,
                'no_revalidation' => true,
                'before' => $plan['before'] ?? null,
                'after' => $executedPlan['after'] ?? null,
            ],
            reason: 'seo_ops_gaokao_v5_url_truth_eligibility_gate',
            result: 'success',
        );

        return $this->summary(
            ok: true,
            status: 'success',
            execute: true,
            action: 'enabled_url_truth_eligibility',
            articleId: $articleId,
            expectedCanonicalPath: $expectedCanonicalPath,
            expectedConfirmation: $expectedConfirmation,
            plan: $executedPlan,
            issues: [],
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function preflight(int $articleId, string $expectedCanonicalPath, bool $lockForUpdate = false): ?array
    {
        $article = Article::query()
            ->withoutGlobalScopes()
            ->with([
                'publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                'seoMeta' => static fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->when($lockForUpdate, static fn ($query) => $query->lockForUpdate())
            ->find($articleId);

        if (! $article instanceof Article) {
            return null;
        }

        $before = $this->snapshot($article);
        $after = $before;
        $after['sitemap_eligible'] = true;
        $after['llms_eligible'] = true;
        $issues = [];
        $actualCanonicalPath = $this->canonicalPathFromSeoMeta($article);

        if ((int) $article->id !== self::ARTICLE_ID) {
            $issues[] = $this->issue('article.id', 'unsupported_article_id', 'This gate only supports article 55.');
        }
        if ((string) $article->locale !== 'zh-CN') {
            $issues[] = $this->issue('article.locale', 'locale_mismatch', 'Article 55 must remain zh-CN.');
        }
        if ($actualCanonicalPath !== $expectedCanonicalPath) {
            $issues[] = $this->issue(
                'seo_meta.canonical_url',
                'canonical_path_mismatch',
                'SEO meta canonical must match the locked Gaokao article route.',
                [
                    'expected_canonical_path' => $expectedCanonicalPath,
                    'actual_canonical_path' => $actualCanonicalPath,
                ],
            );
        }
        if ((string) $article->status !== 'published') {
            $issues[] = $this->issue('article.status', 'article_not_published', 'Article must already be published.');
        }
        if (! (bool) $article->is_public) {
            $issues[] = $this->issue('article.is_public', 'article_not_public', 'Article must already be public.');
        }
        if (! (bool) $article->is_indexable) {
            $issues[] = $this->issue('article.is_indexable', 'article_not_indexable', 'Article must already be indexable.');
        }
        if ((string) $article->lifecycle_state !== '' && in_array((string) $article->lifecycle_state, [
            Article::LIFECYCLE_ARCHIVED,
            Article::LIFECYCLE_SOFT_DELETED,
        ], true)) {
            $issues[] = $this->issue('article.lifecycle_state', 'article_lifecycle_not_eligible', 'Archived or soft-deleted articles cannot be made URL Truth eligible.');
        }
        if (method_exists($article, 'trashed') && $article->trashed()) {
            $issues[] = $this->issue('article.deleted_at', 'article_soft_deleted', 'Soft-deleted articles cannot be made URL Truth eligible.');
        }

        $revision = $article->publishedRevision;
        if (! $revision instanceof ArticleTranslationRevision) {
            $issues[] = $this->issue('article.published_revision_id', 'published_revision_missing', 'Article must have a published revision.');
        } elseif ((string) $revision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED) {
            $issues[] = $this->issue('published_revision.revision_status', 'published_revision_status_invalid', 'Published revision must have published status.');
        }

        $seoMeta = $article->seoMeta;
        if (! $seoMeta instanceof ArticleSeoMeta) {
            $issues[] = $this->issue('seo_meta', 'seo_meta_missing', 'Article SEO meta must exist before URL Truth eligibility.');
        } else {
            if (! (bool) $seoMeta->is_indexable) {
                $issues[] = $this->issue('seo_meta.is_indexable', 'seo_meta_not_indexable', 'SEO meta must already be indexable.');
            }
            if ((string) $seoMeta->robots !== 'index,follow') {
                $issues[] = $this->issue('seo_meta.robots', 'seo_meta_robots_not_index_follow', 'SEO meta robots must be index,follow.');
            }
        }

        return [
            'article_id' => $articleId,
            'canonical_path' => $actualCanonicalPath,
            'already_eligible' => (bool) $article->sitemap_eligible && (bool) $article->llms_eligible,
            'would_make_url_truth_candidate' => $issues === [],
            'before' => $before,
            'after' => $after,
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(Article $article): array
    {
        $revision = $article->publishedRevision;
        $seoMeta = $article->seoMeta;

        return [
            'article_id' => (int) $article->id,
            'slug_hash' => hash('sha256', (string) $article->slug),
            'locale' => (string) $article->locale,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'published_revision_id' => $article->published_revision_id === null ? null : (int) $article->published_revision_id,
            'published_revision_status' => $revision instanceof ArticleTranslationRevision ? (string) $revision->revision_status : null,
            'content_md_sha256' => hash('sha256', (string) $article->content_md),
            'content_html_sha256' => hash('sha256', (string) $article->content_html),
            'seo_meta_exists' => $seoMeta instanceof ArticleSeoMeta,
            'seo_robots' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->robots : null,
            'seo_is_indexable' => $seoMeta instanceof ArticleSeoMeta ? (bool) $seoMeta->is_indexable : null,
            'canonical_path' => $this->canonicalPathFromSeoMeta($article),
            'schema_json_sha256' => $seoMeta instanceof ArticleSeoMeta
                ? hash('sha256', (string) json_encode($seoMeta->schema_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE))
                : null,
        ];
    }

    private function canonicalPathFromSeoMeta(Article $article): string
    {
        $seoMeta = $article->seoMeta;
        if (! $seoMeta instanceof ArticleSeoMeta) {
            return '';
        }

        return $this->normalizePath((string) $seoMeta->canonical_url);
    }

    private function normalizePath(string $canonical): string
    {
        $trimmed = trim($canonical);
        $path = parse_url($trimmed, PHP_URL_PATH);
        $normalized = is_string($path) && $path !== '' ? $path : $trimmed;

        return str_starts_with($normalized, '/') ? $normalized : '/'.$normalized;
    }

    private function expectedConfirmation(int $articleId, string $expectedCanonicalPath): string
    {
        return "I explicitly approve SEO-OPS-GAOKAO-V5-URL-TRUTH-ELIGIBILITY-GATE-01 to enable sitemap/llms eligibility for article {$articleId} canonical {$expectedCanonicalPath}; no content change, no publish, no URL Truth write, no schema/hreflang, no Search Channel, no IndexNow/Baidu/GSC, no deploy/revalidation.";
    }

    /**
     * @param  array<string,mixed>|null  $plan
     * @param  list<array<string,mixed>>  $issues
     * @return array<string,mixed>
     */
    private function summary(bool $ok, string $status, bool $execute, string $action, int $articleId, string $expectedCanonicalPath, string $expectedConfirmation, ?array $plan, array $issues): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $ok,
            'status' => $status,
            'dry_run' => ! $execute,
            'execute' => $execute,
            'action' => $action,
            'article_id' => $articleId,
            'expected_canonical_path' => $expectedCanonicalPath,
            'required_confirmation_phrase' => $expectedConfirmation,
            'planned_write_scope' => ['articles.sitemap_eligible', 'articles.llms_eligible'],
            'url_truth_candidate_expected_after_execute' => $ok,
            'plan' => $plan,
            'issues' => $issues,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function negativeGuarantees(): array
    {
        return [
            'content_write' => false,
            'cms_publish' => false,
            'url_truth_write' => false,
            'schema_hreflang_write' => false,
            'search_channel_enqueue' => false,
            'indexnow_submit' => false,
            'baidu_submit' => false,
            'gsc_request_indexing' => false,
            'scheduler_activation' => false,
            'queue_worker_start' => false,
            'deploy' => false,
            'revalidation' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function issue(string $field, string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ], $extra);
    }

    /**
     * @return array<string,mixed>
     */
    private function failureSummary(string $code, string $message): array
    {
        $articleId = (int) $this->option('article');
        $canonical = $this->normalizePath((string) $this->option('expected-canonical-path'));

        return $this->summary(
            ok: false,
            status: 'blocked',
            execute: (bool) $this->option('execute'),
            action: 'will_skip',
            articleId: $articleId,
            expectedCanonicalPath: $canonical,
            expectedConfirmation: $this->expectedConfirmation($articleId, $canonical),
            plan: null,
            issues: [$this->issue('command', $code, $message)],
        );
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>|null
     */
    private function writeEvidence(array $summary): ?array
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '') {
            return null;
        }

        File::ensureDirectoryExists($dir);
        if (! is_dir($dir) || ! is_writable($dir)) {
            $summary['issues'][] = $this->issue('artifact_dir', 'artifact_dir_unwritable', 'Artifact directory is not writable.');
            $dir = storage_path('app/seo-ops/gaokao-v5-url-truth-eligibility-gate');
            File::ensureDirectoryExists($dir);
        }

        $path = rtrim($dir, '/').'/seo-ops-gaokao-v5-url-truth-eligibility-gate-'.Carbon::now('UTC')->format('Ymd\THis\Z').'.json';
        File::put($path, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n");

        return [
            'path' => $path,
            'size_bytes' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('status='.(string) ($summary['status'] ?? ''));
        $this->line('dry_run='.(($summary['dry_run'] ?? false) ? '1' : '0'));
        $this->line('execute='.(($summary['execute'] ?? false) ? '1' : '0'));
        $this->line('article_id='.(string) ($summary['article_id'] ?? ''));
        $this->line('expected_canonical_path='.(string) ($summary['expected_canonical_path'] ?? ''));
        $this->line('issues_count='.(string) count((array) ($summary['issues'] ?? [])));
        $this->line('required_confirmation_phrase='.(string) ($summary['required_confirmation_phrase'] ?? ''));
    }
}
