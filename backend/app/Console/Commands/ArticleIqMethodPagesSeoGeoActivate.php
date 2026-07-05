<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ArticleIqMethodPagesSeoGeoActivate extends Command
{
    private const SCHEMA_VERSION = 'fermatmind.iq_method_pages.seo_geo_activate.v1';

    private const PR_ID = 'IQ-METHOD-PAGES-ZH-CN-SEO-GEO-ACTIVATE-01';

    private const ACTIVATION_GATE_PR_ID = 'IQ-METHOD-PAGES-ZH-CN-SEO-GEO-ACTIVATE-GATE-01';

    private const SLUGS = [
        'what-is-iq-style-reasoning-test',
        'online-iq-test-vs-professional-assessment',
        'iq-test-score-meaning-boundary',
        'matrix-reasoning-pattern-recognition-guide',
        'why-fermatmind-iq-v1-not-certification',
        'iq-test-privacy-data-boundary',
        'iq-expert-review-disclosure',
    ];

    private const FORBIDDEN_CLAIM_PATTERNS = [
        'official_iq' => '/(?:官方\s*IQ|official\s+IQ)/iu',
        'certified_iq' => '/(?:认证\s*IQ|certified\s+IQ|PDF\s*certificate|certificate)/iu',
        'clinical_diagnosis' => '/(?:临床诊断|诊断级|clinical\s+diagnosis|clinical-grade)/iu',
        'mensa_claim' => '/\bMensa\b|门萨/u',
        'percentile_claim' => '/(?:percentile|百分位)/iu',
        'employment_or_admission' => '/(?:招聘|录用|升学录取|薪资预测|hire|admission|salary\s+prediction)/iu',
        'fixed_intelligence' => '/(?:真实智商|固定智力|固定能力|real\s+IQ)/iu',
    ];

    private const PRIVATE_PATTERNS = [
        'attempt_route' => '~/(?:zh/|en/)?attempt(?:/|[?#\s)"\']|$)~i',
        'result_route' => '~/(?:zh/|en/)?(?:result|results)(?:/|[?#\s)"\']|$)~i',
        'order_route' => '~/(?:zh/|en/)?(?:orders|order)(?:/|[?#\s)"\']|$)~i',
        'payment_route' => '~/(?:zh/|en/)?(?:pay|payment)(?:/|[?#\s)"\']|$)~i',
        'recovery_route' => '~/(?:zh/|en/)?(?:recover|restore)(?:/|[?#\s)"\']|$)~i',
        'scoring_secret' => '/(?:answer_key|correct_answer|scoring_rule|score_formula|private_result|payment_id)/i',
    ];

    protected $signature = 'articles:iq-method-pages-seo-geo-activate
        {--article-id= : Exact article id to lock}
        {--expected-slug= : Expected IQ method article slug lock}
        {--confirm= : Exact user confirmation phrase for execute mode}
        {--dry-run : Validate and plan without writing}
        {--execute : Activate indexability, sitemap eligibility, and llms eligibility}
        {--json : Emit a JSON summary}
        {--no-content-change : Required execute-mode hold: do not modify article content}
        {--no-publish : Required execute-mode hold: do not publish or promote}
        {--no-search : Required execute-mode hold: do not submit search channels}
        {--no-schema-hreflang : Required execute-mode hold: do not modify schema or hreflang gates}
        {--no-revalidation : Required execute-mode hold: do not revalidate frontend paths}';

    protected $description = 'Controlled SEO/GEO activation for one locked zh-CN IQ method CMS Article.';

    public function handle(AuditLogger $auditLogger): int
    {
        try {
            $summary = $this->buildSummary($auditLogger);
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary('runtime_error', $exception->getMessage());
        } catch (Throwable $exception) {
            $summary = $this->failureSummary('unexpected_error', $exception->getMessage());
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
        $dryRun = ! $execute;
        $articleId = (int) $this->option('article-id');
        $expectedSlug = trim((string) $this->option('expected-slug'));
        $expectedConfirmation = $this->expectedConfirmation($articleId, $expectedSlug);
        $confirmation = trim((string) $this->option('confirm'));
        $errors = [];

        if ((bool) $this->option('dry-run') && $execute) {
            $errors[] = $this->issue('dry_run', 'execute_dry_run_conflict', '--execute cannot be combined with --dry-run.');
        }
        if ($execute) {
            foreach (['no-content-change', 'no-publish', 'no-search', 'no-schema-hreflang', 'no-revalidation'] as $flag) {
                if ((bool) $this->option($flag) !== true) {
                    $errors[] = $this->issue($flag, 'required_safety_flag_missing', 'All no-side-effect safety flags are required for execute mode.');
                }
            }
            if (! hash_equals($expectedConfirmation, $confirmation)) {
                $errors[] = $this->issue('confirm', 'confirmation_mismatch', 'Exact confirmation phrase is required before IQ method SEO/GEO activation.', [
                    'expected_confirmation' => $expectedConfirmation,
                ]);
            }
        }

        if ($articleId <= 0) {
            $errors[] = $this->issue('article_id', 'article_id_required', '--article-id is required.');
        }
        if ($expectedSlug === '') {
            $errors[] = $this->issue('expected_slug', 'expected_slug_required', '--expected-slug is required.');
        }

        $plan = $articleId > 0 ? $this->preflight($articleId, $expectedSlug) : null;
        if ($plan === null) {
            $errors[] = $this->issue('article_id', 'article_not_found', 'Article was not found.');
        } else {
            foreach ((array) ($plan['errors'] ?? []) as $error) {
                if (is_array($error)) {
                    $errors[] = $error;
                }
            }
        }

        if ($errors !== []) {
            return $this->summary(false, $dryRun, 'will_skip', $articleId, $expectedSlug, $expectedConfirmation, $plan, $errors);
        }

        if ($dryRun) {
            return $this->summary(true, true, 'would_activate_iq_method_article_discoverability', $articleId, $expectedSlug, $expectedConfirmation, $plan, []);
        }

        $executedPlan = DB::transaction(function () use ($articleId, $expectedSlug): array {
            $lockedPlan = $this->preflight($articleId, $expectedSlug, lockForUpdate: true);
            if ($lockedPlan === null) {
                throw new RuntimeException('planned article disappeared before IQ method SEO/GEO activation.');
            }

            $errors = (array) ($lockedPlan['errors'] ?? []);
            if ($errors !== []) {
                $codes = collect($errors)
                    ->map(static fn (mixed $error): string => is_array($error) ? (string) ($error['code'] ?? '') : '')
                    ->filter()
                    ->implode(',');

                throw new RuntimeException('IQ method SEO/GEO activation preflight failed before write: '.$codes);
            }

            DB::table('articles')
                ->where('id', $articleId)
                ->update([
                    'is_indexable' => true,
                    'sitemap_eligible' => true,
                    'llms_eligible' => true,
                    'updated_at' => now(),
                ]);

            DB::table('article_seo_meta')
                ->where('article_id', $articleId)
                ->update([
                    'is_indexable' => true,
                    'robots' => 'index,follow',
                    'updated_at' => now(),
                ]);

            return $this->postActivationReadback($articleId, $expectedSlug, (array) ($lockedPlan['before'] ?? [])) ?? $lockedPlan;
        });

        $auditLogger->log(
            Request::create('/ops/articles/iq-method-pages-seo-geo-activate', 'POST'),
            'articles_iq_method_pages_seo_geo_activate',
            'article',
            (string) $articleId,
            [
                'command' => 'articles:iq-method-pages-seo-geo-activate',
                'pr_id' => self::PR_ID,
                'activation_gate_pr_id' => self::ACTIVATION_GATE_PR_ID,
                'article_id' => $articleId,
                'slug' => $expectedSlug,
                'confirmation_sha256' => hash('sha256', $confirmation),
                'updates_scope' => [
                    'articles.is_indexable',
                    'articles.sitemap_eligible',
                    'articles.llms_eligible',
                    'article_seo_meta.is_indexable',
                    'article_seo_meta.robots',
                ],
                'no_content_change' => true,
                'no_publish' => true,
                'no_search' => true,
                'no_schema_hreflang' => true,
                'no_revalidation' => true,
                'before' => $plan['before'] ?? null,
                'after' => $executedPlan['after'] ?? null,
            ],
            reason: 'controlled_iq_method_pages_seo_geo_activation',
            result: 'success',
        );

        return $this->summary(true, false, 'activated_iq_method_article_discoverability', $articleId, $expectedSlug, $expectedConfirmation, $executedPlan, []);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function preflight(int $articleId, string $expectedSlug, bool $lockForUpdate = false): ?array
    {
        $article = $this->articleQuery($lockForUpdate)->find($articleId);
        if (! $article instanceof Article) {
            return null;
        }

        $before = $this->snapshot($article);
        $after = $before;
        $after['is_indexable'] = true;
        $after['sitemap_eligible'] = true;
        $after['llms_eligible'] = true;
        $after['seo_robots'] = 'index,follow';
        $after['seo_is_indexable'] = true;
        $errors = [];

        if (! in_array($expectedSlug, self::SLUGS, true)) {
            $errors[] = $this->issue('expected_slug', 'slug_not_in_iq_method_set', 'Expected slug is not one of the seven IQ method pages.');
        }
        if ((string) $article->slug !== $expectedSlug) {
            $errors[] = $this->issue('article.slug', 'expected_slug_mismatch', 'Article slug does not match expected lock.');
        }
        if ((int) $article->org_id !== 0 || (string) $article->locale !== 'zh-CN') {
            $errors[] = $this->issue('article.locale', 'article_scope_mismatch', 'Article must be org_id=0 and locale zh-CN.');
        }
        if ((string) $article->status !== 'published' || ! (bool) $article->is_public) {
            $errors[] = $this->issue('article.status', 'article_not_public_published', 'Article must already be public and published.');
        }
        if ((bool) $article->is_indexable || (bool) $article->sitemap_eligible || (bool) $article->llms_eligible) {
            $errors[] = $this->issue('article.discoverability', 'article_already_or_partly_discoverable', 'Article must still be noindex and excluded from sitemap/llms before controlled activation.');
        }

        $revision = $article->publishedRevision;
        if (! $revision instanceof ArticleTranslationRevision || (string) $revision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED) {
            $errors[] = $this->issue('article.published_revision_id', 'published_revision_missing_or_invalid', 'Article must have a published revision.');
        }

        $seoMeta = $article->seoMeta;
        if (! $seoMeta instanceof ArticleSeoMeta) {
            $errors[] = $this->issue('seo_meta', 'seo_meta_missing', 'Article SEO meta must exist before activation.');
        } else {
            if ((bool) $seoMeta->is_indexable || (string) $seoMeta->robots !== 'noindex,follow') {
                $errors[] = $this->issue('seo_meta.robots', 'seo_meta_not_in_noindex_hold', 'SEO meta must still be noindex,follow before controlled activation.');
            }
            if ((string) $seoMeta->canonical_url !== 'https://fermatmind.com/zh/articles/'.$expectedSlug) {
                $errors[] = $this->issue('seo_meta.canonical_url', 'canonical_url_mismatch', 'SEO meta canonical must match the locked article URL.');
            }
        }

        $publicPayload = $this->publicPayloadText($article, $seoMeta instanceof ArticleSeoMeta ? $seoMeta : null);
        $this->assertNoForbiddenClaims($errors, 'claim_boundary', $publicPayload);
        $this->assertNoPrivateTokens($errors, 'public_payload', $publicPayload);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'article_id' => $articleId,
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'canonical_url' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->canonical_url : null,
            'before' => $before,
            'after' => $after,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function postActivationReadback(int $articleId, string $expectedSlug, array $before): ?array
    {
        $article = $this->articleQuery()->find($articleId);
        if (! $article instanceof Article) {
            return null;
        }

        $after = $this->snapshot($article);
        $errors = [];

        if ((string) $article->slug !== $expectedSlug) {
            $errors[] = $this->issue('article.slug', 'expected_slug_mismatch_after_write', 'Article slug changed during activation.');
        }
        if (! (bool) $article->is_indexable || ! (bool) $article->sitemap_eligible || ! (bool) $article->llms_eligible) {
            $errors[] = $this->issue('article.discoverability', 'article_discoverability_write_incomplete', 'Article discoverability flags were not fully activated.');
        }
        if (! $article->seoMeta instanceof ArticleSeoMeta || ! (bool) $article->seoMeta->is_indexable || (string) $article->seoMeta->robots !== 'index,follow') {
            $errors[] = $this->issue('seo_meta.robots', 'seo_meta_activation_write_incomplete', 'SEO meta indexability and robots were not fully activated.');
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'article_id' => $articleId,
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'canonical_url' => $article->seoMeta instanceof ArticleSeoMeta ? (string) $article->seoMeta->canonical_url : null,
            'before' => $before,
            'after' => $after,
            'errors' => $errors,
        ];
    }

    private function articleQuery(bool $lockForUpdate = false): mixed
    {
        return Article::query()
            ->withoutGlobalScopes()
            ->with([
                'publishedRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
                'seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes(),
            ])
            ->when($lockForUpdate, static fn ($query) => $query->lockForUpdate());
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
            'slug' => (string) $article->slug,
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
            'canonical_url' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->canonical_url : null,
            'schema_json_sha256' => $seoMeta instanceof ArticleSeoMeta
                ? hash('sha256', (string) json_encode($seoMeta->schema_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE))
                : null,
        ];
    }

    private function expectedConfirmation(int $articleId, string $expectedSlug): string
    {
        return "I explicitly approve articles:iq-method-pages-seo-geo-activate execute for article id {$articleId} slug {$expectedSlug} after activation gate passes.";
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function assertNoForbiddenClaims(array &$errors, string $field, string $text): void
    {
        foreach (self::FORBIDDEN_CLAIM_PATTERNS as $code => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $errors[] = $this->issue($field, 'forbidden_claim_detected', 'Public IQ method activation payload contains a forbidden claim.', [
                    'pattern' => $code,
                ]);
            }
        }
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function assertNoPrivateTokens(array &$errors, string $field, string $text): void
    {
        foreach (self::PRIVATE_PATTERNS as $code => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $errors[] = $this->issue($field, 'private_or_scoring_token_leak', 'Public IQ method activation payload contains a private route or scoring token.', [
                    'pattern' => $code,
                ]);
            }
        }
    }

    private function publicPayloadText(Article $article, ?ArticleSeoMeta $seo): string
    {
        return json_encode([
            'title' => $article->title,
            'excerpt' => $article->excerpt,
            'content_md' => $article->content_md,
            'seo' => $seo?->toArray(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(bool $ok, bool $dryRun, string $action, int $articleId, string $expectedSlug, string $expectedConfirmation, ?array $plan, array $errors): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $ok,
            'dry_run' => $dryRun,
            'execute' => ! $dryRun,
            'action' => $action,
            'would_write' => $ok && ! $dryRun,
            'pr_id' => self::PR_ID,
            'activation_gate_pr_id' => self::ACTIVATION_GATE_PR_ID,
            'article_id' => $articleId,
            'expected_slug' => $expectedSlug,
            'expected_confirmation' => $expectedConfirmation,
            'updates_scope' => [
                'articles.is_indexable',
                'articles.sitemap_eligible',
                'articles.llms_eligible',
                'article_seo_meta.is_indexable',
                'article_seo_meta.robots',
            ],
            'protected_holds' => [
                'no_content_change' => true,
                'no_publish' => true,
                'no_search' => true,
                'no_schema_hreflang' => true,
                'no_revalidation' => true,
            ],
            'external_search_submission_attempted' => false,
            'schema_hreflang_write_attempted' => false,
            'content_write_attempted' => false,
            'publish_attempted' => false,
            'revalidation_attempted' => false,
            'plan' => $plan,
            'errors' => $errors,
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
        $articleId = (int) $this->option('article-id');
        $expectedSlug = trim((string) $this->option('expected-slug'));

        return $this->summary(false, ! (bool) $this->option('execute'), 'will_skip', $articleId, $expectedSlug, $this->expectedConfirmation($articleId, $expectedSlug), null, [[
            'field' => 'command',
            'code' => $code,
            'message' => $message,
        ]]);
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
        $this->line('dry_run='.(($summary['dry_run'] ?? false) ? '1' : '0'));
        $this->line('action='.(string) ($summary['action'] ?? ''));
        $this->line('article_id='.(string) ($summary['article_id'] ?? ''));
        $this->line('expected_slug='.(string) ($summary['expected_slug'] ?? ''));
        $this->line('expected_confirmation='.(string) ($summary['expected_confirmation'] ?? ''));
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));

        foreach ((array) ($summary['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $this->line('error='.$this->issueLine($error));
            }
        }
    }

    /**
     * @param  array<string,mixed>  $issue
     */
    private function issueLine(array $issue): string
    {
        return implode('|', array_filter([
            'field='.(string) ($issue['field'] ?? ''),
            'code='.(string) ($issue['code'] ?? ''),
            'message='.(string) ($issue['message'] ?? ''),
        ]));
    }
}
