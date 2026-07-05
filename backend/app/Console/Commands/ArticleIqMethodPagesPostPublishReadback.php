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

final class ArticleIqMethodPagesPostPublishReadback extends Command
{
    private const SCHEMA_VERSION = 'fermatmind.iq_method_pages.post_publish_readback.v1';

    private const PR_ID = 'IQ-METHOD-PAGES-ZH-CN-CMS-POST-PUBLISH-READBACK-01';

    private const PUBLISH_PR_ID = 'IQ-METHOD-PAGES-ZH-CN-CMS-PUBLISH-01';

    private const SLUGS = [
        'what-is-iq-style-reasoning-test',
        'online-iq-test-vs-professional-assessment',
        'iq-test-score-meaning-boundary',
        'matrix-reasoning-pattern-recognition-guide',
        'why-fermatmind-iq-v1-not-certification',
        'iq-test-privacy-data-boundary',
        'iq-expert-review-disclosure',
    ];

    private const PRIVATE_PATTERNS = [
        'attempt_route' => '~/(?:zh/|en/)?attempt(?:/|[?#\s)"\']|$)~i',
        'result_route' => '~/(?:zh/|en/)?(?:result|results)(?:/|[?#\s)"\']|$)~i',
        'order_route' => '~/(?:zh/|en/)?(?:orders|order)(?:/|[?#\s)"\']|$)~i',
        'payment_route' => '~/(?:zh/|en/)?(?:pay|payment)(?:/|[?#\s)"\']|$)~i',
        'recovery_route' => '~/(?:zh/|en/)?(?:recover|restore)(?:/|[?#\s)"\']|$)~i',
        'scoring_secret' => '/\b(?:answer_key|correct_answer|scoring_rule|score_formula|private_result|payment_id)\b/i',
    ];

    protected $signature = 'articles:iq-method-pages-post-publish-readback
        {--json : Emit a JSON summary}';

    protected $description = 'Read-only post-publish verification for zh-CN IQ method CMS Articles before indexing or llms activation.';

    public function handle(): int
    {
        try {
            $summary = $this->readback();
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
    private function readback(): array
    {
        $issues = [];
        $articles = $this->readbackArticles($issues);
        $topic = $this->readbackTopic($issues);
        $landing = $this->readbackLanding($issues);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $issues === [],
            'status' => $issues === [] ? 'pass' : 'blocked',
            'dry_run' => true,
            'execute' => false,
            'generated_at' => now()->toIso8601String(),
            'pr_id' => self::PR_ID,
            'source_publish_pr_id' => self::PUBLISH_PR_ID,
            'expected_article_count' => 7,
            'article_readbacks' => $articles,
            'topic_readback' => $topic,
            'landing_readback' => $landing,
            'mismatch_count' => count($issues),
            'issues' => $issues,
            'side_effects' => $this->sideEffects(),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @return list<array<string,mixed>>
     */
    private function readbackArticles(array &$issues): array
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
            $seo = $article?->seoMeta instanceof ArticleSeoMeta ? $article->seoMeta : null;
            $published = $article?->publishedRevision instanceof ArticleTranslationRevision ? $article->publishedRevision : null;
            $before = count($issues);

            if (! $article instanceof Article) {
                $issues[] = $this->issue($slug, 'article_missing', 'Published IQ method Article was not found.');
                $readbacks[] = ['slug' => $slug, 'ok' => false, 'status' => 'missing'];

                continue;
            }

            $this->same($issues, $slug.'.status', 'published', (string) $article->status);
            $this->same($issues, $slug.'.is_public', true, (bool) $article->is_public);
            $this->same($issues, $slug.'.is_indexable', false, (bool) $article->is_indexable);
            $this->same($issues, $slug.'.sitemap_eligible', false, (bool) $article->sitemap_eligible);
            $this->same($issues, $slug.'.llms_eligible', false, (bool) $article->llms_eligible);
            if ($article->published_at === null || $article->published_revision_id === null) {
                $issues[] = $this->issue($slug.'.published_at', 'published_marker_missing', 'Published article must have published_at and published_revision_id.');
            }

            if (! $published instanceof ArticleTranslationRevision) {
                $issues[] = $this->issue($slug.'.published_revision', 'published_revision_missing', 'Published revision missing.');
            } else {
                $this->same($issues, $slug.'.published_revision_id', (int) $article->working_revision_id, (int) $published->id);
                $this->same($issues, $slug.'.published_revision_status', ArticleTranslationRevision::STATUS_PUBLISHED, (string) $published->revision_status);
            }

            if (! $seo instanceof ArticleSeoMeta) {
                $issues[] = $this->issue($slug.'.seo', 'seo_meta_missing', 'SEO meta missing.');
            } else {
                $this->same($issues, $slug.'.canonical_url', 'https://fermatmind.com/zh/articles/'.$slug, (string) $seo->canonical_url);
                $this->same($issues, $slug.'.robots', 'noindex,follow', (string) $seo->robots);
                $this->same($issues, $slug.'.seo_is_indexable', false, (bool) $seo->is_indexable);
                $this->same($issues, $slug.'.publish_pr', self::PUBLISH_PR_ID, (string) data_get($seo->schema_json, 'editorial_package_v1.publish_v1.pr_id'));
            }

            $this->assertNoPrivateTokens($issues, $slug.'.public_payload', json_encode([
                'title' => $article->title,
                'excerpt' => $article->excerpt,
                'content_md' => $article->content_md,
                'seo' => $seo?->toArray(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

            $readbacks[] = [
                'slug' => $slug,
                'article_id' => (int) $article->id,
                'published_revision_id' => $article->published_revision_id !== null ? (int) $article->published_revision_id : null,
                'status' => (string) $article->status,
                'is_public' => (bool) $article->is_public,
                'is_indexable' => (bool) $article->is_indexable,
                'robots' => (string) ($seo?->robots ?? ''),
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
                'llms_eligible' => (bool) $article->llms_eligible,
                'ok' => count($issues) === $before,
            ];
        }

        return $readbacks;
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @return array<string,mixed>
     */
    private function readbackTopic(array &$issues): array
    {
        $profile = TopicProfile::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->where('slug', 'iq-eq')
            ->first();

        if (! $profile instanceof TopicProfile) {
            $issues[] = $this->issue('topic.iq-eq', 'topic_missing', 'IQ/EQ topic profile missing.');

            return ['ok' => false, 'slug' => 'iq-eq', 'status' => 'missing'];
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
            'expected_items_count' => 7,
            'actual_items_count' => $entries->count(),
            'slugs' => $actual,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @return array<string,mixed>
     */
    private function readbackLanding(array &$issues): array
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
            'expected_items_count' => 7,
            'actual_items_count' => $items->count(),
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
            $issues[] = $this->issue($field, 'value_mismatch', 'Readback value mismatch.', [
                'expected' => $expected,
                'actual' => $actual,
            ]);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     */
    private function assertNoPrivateTokens(array &$issues, string $field, string $text): void
    {
        foreach (self::PRIVATE_PATTERNS as $code => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $issues[] = $this->issue($field, 'private_or_scoring_token_leak', 'Public IQ method payload contains a private route or scoring token.', [
                    'pattern' => $code,
                ]);
            }
        }
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
        $this->line('mismatch_count='.(string) ($summary['mismatch_count'] ?? 0));

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
