<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\LandingSurface;
use App\Models\PageBlock;
use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use Illuminate\Console\Command;
use Throwable;

final class ArticleIqMethodPagesSeoGeoActivationGate extends Command
{
    private const SCHEMA_VERSION = 'fermatmind.iq_method_pages.seo_geo_activation_gate.v1';

    private const PR_ID = 'IQ-METHOD-PAGES-ZH-CN-SEO-GEO-ACTIVATE-GATE-01';

    private const POST_PUBLISH_READBACK_PR_ID = 'IQ-METHOD-PAGES-ZH-CN-CMS-POST-PUBLISH-READBACK-01';

    private const SLUGS = [
        'what-is-iq-style-reasoning-test',
        'online-iq-test-vs-professional-assessment',
        'iq-test-score-meaning-boundary',
        'matrix-reasoning-pattern-recognition-guide',
        'why-fermatmind-iq-v1-not-certification',
        'iq-test-privacy-data-boundary',
        'iq-expert-review-disclosure',
    ];

    private const REQUIRED_VISIBLE_TERMS = [
        'IQ',
        '非官方',
        '非临床',
        '非认证',
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
        'scoring_secret' => '/\b(?:answer_key|correct_answer|scoring_rule|score_formula|private_result|payment_id)\b/i',
    ];

    protected $signature = 'articles:iq-method-pages-seo-geo-activation-gate
        {--json : Emit a JSON summary}';

    protected $description = 'Read-only SEO/GEO activation gate for the 7 published zh-CN IQ method CMS Articles.';

    public function handle(): int
    {
        try {
            $summary = $this->gate();
        } catch (Throwable $exception) {
            $summary = [
                'schema_version' => self::SCHEMA_VERSION,
                'ok' => false,
                'status' => 'blocked',
                'pr_id' => self::PR_ID,
                'issues' => [$this->issue('command', 'unexpected_error', $exception->getMessage())],
                'side_effects' => $this->sideEffects(),
            ];
        }

        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function gate(): array
    {
        $issues = [];
        $articles = $this->gateArticles($issues);
        $topic = $this->gateTopic($issues);
        $landing = $this->gateLanding($issues);
        $activationCandidates = array_values(array_map(
            static fn (array $article): array => [
                'article_id' => (int) $article['article_id'],
                'slug' => (string) $article['slug'],
                'canonical_url' => (string) $article['canonical_url'],
                'current_robots' => (string) $article['robots'],
                'next_robots' => 'index,follow',
                'current_is_indexable' => false,
                'next_is_indexable' => true,
                'current_sitemap_eligible' => false,
                'next_sitemap_eligible' => true,
                'current_llms_eligible' => false,
                'next_llms_eligible' => true,
                'activation_command' => 'php artisan articles:iq-method-pages-seo-geo-activate --article-id='.(int) $article['article_id'].' --expected-slug='.(string) $article['slug'].' --dry-run --json',
            ],
            array_filter($articles, static fn (array $article): bool => ($article['activation_candidate'] ?? false) === true),
        ));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $issues === [] && count($activationCandidates) === 7,
            'status' => $issues === [] && count($activationCandidates) === 7 ? 'pass' : 'blocked',
            'dry_run' => true,
            'execute' => false,
            'generated_at' => now()->toIso8601String(),
            'pr_id' => self::PR_ID,
            'source_post_publish_readback_pr_id' => self::POST_PUBLISH_READBACK_PR_ID,
            'expected_article_count' => 7,
            'activation_candidate_count' => count($activationCandidates),
            'activation_candidates' => $activationCandidates,
            'articles' => $articles,
            'topic_gate' => $topic,
            'landing_gate' => $landing,
            'issue_count' => count($issues),
            'issues' => $issues,
            'next_allowed_action' => $issues === [] && count($activationCandidates) === 7
                ? 'run IQ-METHOD-PAGES-ZH-CN-SEO-GEO-ACTIVATE-01 dry-run; production execute still requires exact deployed SHA and command authorization'
                : 'fix SEO/GEO activation prerequisites before indexability, sitemap, or llms writes',
            'side_effects' => $this->sideEffects(),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @return list<array<string,mixed>>
     */
    private function gateArticles(array &$issues): array
    {
        $readbacks = [];

        foreach (self::SLUGS as $slug) {
            $article = Article::query()
                ->withoutGlobalScopes()
                ->with(['publishedRevision', 'workingRevision', 'seoMeta'])
                ->where('org_id', 0)
                ->where('locale', 'zh-CN')
                ->where('slug', $slug)
                ->first();
            $before = count($issues);

            if (! $article instanceof Article) {
                $issues[] = $this->issue($slug, 'article_missing', 'Published IQ method Article was not found.');
                $readbacks[] = ['slug' => $slug, 'ok' => false, 'activation_candidate' => false];

                continue;
            }

            $seo = $article->seoMeta instanceof ArticleSeoMeta ? $article->seoMeta : null;
            $published = $article->publishedRevision instanceof ArticleTranslationRevision ? $article->publishedRevision : null;
            $canonicalUrl = 'https://fermatmind.com/zh/articles/'.$slug;

            $this->same($issues, $slug.'.status', 'published', (string) $article->status);
            $this->same($issues, $slug.'.is_public', true, (bool) $article->is_public);
            $this->same($issues, $slug.'.current_is_indexable', false, (bool) $article->is_indexable);
            $this->same($issues, $slug.'.current_sitemap_eligible', false, (bool) $article->sitemap_eligible);
            $this->same($issues, $slug.'.current_llms_eligible', false, (bool) $article->llms_eligible);
            if ($published === null) {
                $issues[] = $this->issue($slug.'.published_revision', 'published_revision_missing', 'Published revision missing.');
            } else {
                $this->same($issues, $slug.'.published_revision_status', ArticleTranslationRevision::STATUS_PUBLISHED, (string) $published->revision_status);
                $this->same($issues, $slug.'.published_revision_id', (int) $article->published_revision_id, (int) $published->id);
            }

            if (! $seo instanceof ArticleSeoMeta) {
                $issues[] = $this->issue($slug.'.seo_meta', 'seo_meta_missing', 'SEO meta missing.');
            } else {
                $this->same($issues, $slug.'.canonical_url', $canonicalUrl, (string) $seo->canonical_url);
                $this->same($issues, $slug.'.current_robots', 'noindex,follow', (string) $seo->robots);
                $this->same($issues, $slug.'.current_seo_is_indexable', false, (bool) $seo->is_indexable);
                $this->same($issues, $slug.'.publish_pr', 'IQ-METHOD-PAGES-ZH-CN-CMS-PUBLISH-01', (string) data_get($seo->schema_json, 'editorial_package_v1.publish_v1.pr_id'));
            }

            $body = (string) $article->content_md;
            $this->assertVisibleEvidence($issues, $slug, $body);
            $this->assertNoForbiddenClaims($issues, $slug.'.claim_boundary', $this->publicPayloadText($article, $seo));
            $this->assertNoPrivateTokens($issues, $slug.'.public_payload', $this->publicPayloadText($article, $seo));

            $readbacks[] = [
                'slug' => $slug,
                'article_id' => (int) $article->id,
                'published_revision_id' => $article->published_revision_id !== null ? (int) $article->published_revision_id : null,
                'status' => (string) $article->status,
                'is_public' => (bool) $article->is_public,
                'current_is_indexable' => (bool) $article->is_indexable,
                'robots' => (string) ($seo?->robots ?? ''),
                'canonical_url' => (string) ($seo?->canonical_url ?? ''),
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
                'llms_eligible' => (bool) $article->llms_eligible,
                'visible_evidence_ready' => $this->visibleEvidenceReady($body),
                'activation_candidate' => count($issues) === $before,
            ];
        }

        return $readbacks;
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @return array<string,mixed>
     */
    private function gateTopic(array &$issues): array
    {
        $profile = TopicProfile::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->where('slug', 'iq-eq')
            ->first();

        if (! $profile instanceof TopicProfile) {
            $issues[] = $this->issue('topic.iq-eq', 'topic_missing', 'IQ/EQ topic profile missing.');

            return ['ok' => false, 'slug' => 'iq-eq'];
        }

        $entries = TopicProfileEntry::query()
            ->where('profile_id', (int) $profile->id)
            ->where('group_key', 'iq_articles')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $actual = $entries->pluck('target_key')->values()->all();
        if ($actual !== self::SLUGS) {
            $issues[] = $this->issue('topic.iq_articles.items', 'topic_items_mismatch', 'Topic IQ article entries do not match expected slugs.');
        }
        $this->assertNoPrivateTokens($issues, 'topic.iq_articles.payload', $entries->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'ok' => $actual === self::SLUGS,
            'topic_profile_id' => (int) $profile->id,
            'slug' => 'iq-eq',
            'group_key' => 'iq_articles',
            'slugs' => $actual,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @return array<string,mixed>
     */
    private function gateLanding(array &$issues): array
    {
        $surface = LandingSurface::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('surface_key', 'test:iq-test-intelligence-quotient-assessment')
            ->where('locale', 'zh-CN')
            ->first();

        if (! $surface instanceof LandingSurface) {
            $issues[] = $this->issue('landing_surface.iq', 'landing_surface_missing', 'IQ landing surface missing.');

            return ['ok' => false, 'surface_key' => 'test:iq-test-intelligence-quotient-assessment'];
        }

        $block = PageBlock::query()
            ->where('landing_surface_id', (int) $surface->id)
            ->where('block_key', 'iq_methodology_boundary_links')
            ->first();

        if (! $block instanceof PageBlock) {
            $issues[] = $this->issue('landing_block.iq_methodology_boundary_links', 'landing_block_missing', 'IQ methodology boundary links block missing.');

            return ['ok' => false, 'surface_key' => (string) $surface->surface_key];
        }

        $items = collect((array) data_get($block->payload_json, 'items', []));
        $actual = $items->pluck('slug')->values()->all();
        if ($actual !== self::SLUGS) {
            $issues[] = $this->issue('landing_block.iq_methodology_boundary_links.items', 'landing_items_mismatch', 'Landing block article links do not match expected slugs.');
        }
        $this->same($issues, 'landing_block.frontend_hardcode_allowed', false, (bool) data_get($block->payload_json, 'frontend_hardcode_allowed'));
        $this->assertNoPrivateTokens($issues, 'landing_block.iq_methodology_boundary_links.payload', json_encode($block->payload_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        return [
            'ok' => $actual === self::SLUGS,
            'surface_key' => (string) $surface->surface_key,
            'block_key' => 'iq_methodology_boundary_links',
            'slugs' => $actual,
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function sideEffects(): array
    {
        return [
            'db_write' => false,
            'cms_update' => false,
            'publish' => false,
            'indexability' => false,
            'sitemap' => false,
            'llms' => false,
            'search' => false,
            'deploy' => false,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     */
    private function same(array &$issues, string $field, mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) {
            $issues[] = $this->issue($field, 'value_mismatch', 'Activation gate value mismatch.', [
                'expected' => $expected,
                'actual' => $actual,
            ]);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     */
    private function assertVisibleEvidence(array &$issues, string $slug, string $body): void
    {
        if (! $this->visibleEvidenceReady($body)) {
            $issues[] = $this->issue($slug.'.visible_evidence', 'visible_evidence_missing', 'Article body must expose IQ method evidence, FAQ/body explanation, and non-official/non-clinical/non-certification boundary text before SEO/GEO activation.');
        }
    }

    private function visibleEvidenceReady(string $body): bool
    {
        if (mb_strlen(trim($body)) < 300) {
            return false;
        }
        if (preg_match('/(?:FAQ|常见问题|问答)/u', $body) !== 1) {
            return false;
        }

        foreach (self::REQUIRED_VISIBLE_TERMS as $term) {
            if (! str_contains($body, $term)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     */
    private function assertNoForbiddenClaims(array &$issues, string $field, string $text): void
    {
        foreach (self::FORBIDDEN_CLAIM_PATTERNS as $code => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $issues[] = $this->issue($field, 'forbidden_claim_detected', 'Public IQ method activation payload contains a forbidden claim.', [
                    'pattern' => $code,
                ]);
            }
        }
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     */
    private function assertNoPrivateTokens(array &$issues, string $field, string $text): void
    {
        foreach (self::PRIVATE_PATTERNS as $code => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $issues[] = $this->issue($field, 'private_or_scoring_token_leak', 'Public IQ method activation payload contains a private route or scoring token.', [
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
    private function issue(string $field, string $code, string $message, array $context = []): array
    {
        return array_filter([
            'field' => $field,
            'code' => $code,
            'message' => $message,
            'context' => $context === [] ? null : $context,
        ], static fn (mixed $value): bool => $value !== null);
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
        $this->line('status='.(string) ($summary['status'] ?? 'blocked'));
        $this->line('activation_candidate_count='.(string) ($summary['activation_candidate_count'] ?? 0));
        $this->line('issue_count='.(string) ($summary['issue_count'] ?? 0));

        foreach ((array) ($summary['issues'] ?? []) as $issue) {
            if (is_array($issue)) {
                $this->line(sprintf(
                    'issue=%s:%s:%s',
                    (string) ($issue['field'] ?? 'unknown'),
                    (string) ($issue['code'] ?? 'unknown'),
                    (string) ($issue['message'] ?? ''),
                ));
            }
        }
    }
}
