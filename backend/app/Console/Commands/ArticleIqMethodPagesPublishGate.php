<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

final class ArticleIqMethodPagesPublishGate extends Command
{
    private const SCHEMA_VERSION = 'fermatmind.iq_method_pages.publish_gate.v1';

    private const PR_ID = 'IQ-METHOD-PAGES-ZH-CN-CMS-PUBLISH-GATE-01';

    private const REVIEW_PACKET_SCHEMA_VERSION = 'fermatmind.iq_method_pages.review_packet.v1';

    private const REVIEW_PACKET_ID = 'IQ-METHOD-PAGES-ZH-CN-REVIEW-PACKET-2026-07-05';

    private const DEFAULT_REVIEW_PACKET = 'docs/seo/iq-v1/review-packets/iq-method-pages-zh-cn-review-packet.v1.json';

    private const EXPECTED_STATE = [
        'status' => 'draft_review_only',
        'is_public' => false,
        'is_indexable' => false,
        'robots' => 'noindex,follow',
        'sitemap_eligible' => false,
        'llms_eligible' => false,
    ];

    private const APPROVAL_WRITE_FIELDS = [
        'working_revision.reviewed_by',
        'working_revision.reviewed_at',
        'working_revision.approved_at',
        'working_revision.revision_status=approved',
        'article.status=review_pending',
        'approval_metadata.review_packet_id',
    ];

    protected $signature = 'articles:iq-method-pages-publish-gate
        {--package= : Path to fap-web root, generated/iq-method-pages-zh-cn-v0.2, or its cms-dry-run directory}
        {--review-packet= : Review packet JSON path; defaults to backend/docs/seo/iq-v1/review-packets/iq-method-pages-zh-cn-review-packet.v1.json}
        {--json : Emit a JSON summary}';

    protected $description = 'Read-only gate for zh-CN IQ method CMS Article drafts before review approval or publish.';

    public function handle(): int
    {
        try {
            $summary = $this->gate();
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
    private function gate(): array
    {
        $issues = [];
        $readback = $this->runReadback($issues);
        $reviewPacket = $this->readReviewPacket($issues);
        $reviewBySlug = $this->validateReviewPacket($reviewPacket, $issues);
        $articles = $this->gateArticles($readback, $reviewBySlug, $issues);

        $approvalCandidates = array_values(array_map(
            static fn (array $article): array => [
                'slug' => (string) $article['slug'],
                'article_id' => (int) $article['article_id'],
                'working_revision_id' => (int) $article['working_revision_id'],
            ],
            array_filter($articles, static fn (array $article): bool => ($article['approval_candidate'] ?? false) === true),
        ));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $issues === [] && count($approvalCandidates) === 7,
            'status' => $issues === [] && count($approvalCandidates) === 7 ? 'pass' : 'blocked',
            'dry_run' => true,
            'execute' => false,
            'generated_at' => now()->toIso8601String(),
            'pr_id' => self::PR_ID,
            'source_readback_pr_id' => (string) ($readback['pr_id'] ?? ''),
            'review_packet' => [
                'path' => $this->reviewPacketPath(),
                'schema_version' => (string) ($reviewPacket['schema_version'] ?? ''),
                'packet_id' => (string) ($reviewPacket['packet_id'] ?? ''),
                'sha256' => $this->reviewPacketSha256(),
                'method_review' => (string) data_get($reviewPacket, 'global_review_decision.method_review', ''),
                'claim_review' => (string) data_get($reviewPacket, 'global_review_decision.claim_review', ''),
                'forbidden_claim_scan' => (string) data_get($reviewPacket, 'global_review_decision.forbidden_claim_scan', ''),
            ],
            'expected_article_count' => 7,
            'approval_candidate_count' => count($approvalCandidates),
            'approval_candidates' => $approvalCandidates,
            'articles' => $articles,
            'issues' => $issues,
            'next_allowed_action' => $issues === [] && count($approvalCandidates) === 7
                ? 'run IQ-METHOD-PAGES-ZH-CN-CMS-REVIEW-APPROVAL-01 dry-run with exact article/revision locks'
                : 'fix missing review/readback/draft-noindex prerequisites before approval',
            'side_effects' => [
                'db_write' => false,
                'cms_update' => false,
                'review_approval' => false,
                'publish' => false,
                'indexability' => false,
                'sitemap' => false,
                'llms' => false,
                'deploy' => false,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @return array<string,mixed>
     */
    private function runReadback(array &$issues): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('articles:iq-method-pages-readback', [
            '--package' => (string) $this->option('package'),
            '--json' => true,
        ], $output);

        try {
            $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $issues[] = $this->issue('readback', 'invalid_readback_json', $exception->getMessage());

            return [];
        }

        if (! is_array($decoded)) {
            $issues[] = $this->issue('readback', 'invalid_readback_json', 'Readback command did not return a JSON object.');

            return [];
        }

        if ($exit !== self::SUCCESS || ($decoded['ok'] ?? false) !== true || (string) ($decoded['status'] ?? '') !== 'pass') {
            $issues[] = $this->issue('readback', 'readback_not_passed', 'CMS readback must pass before publish gate approval.', [
                'exit_code' => $exit,
                'status' => (string) ($decoded['status'] ?? ''),
                'mismatch_count' => (int) ($decoded['mismatch_count'] ?? 0),
            ]);
        }

        return $decoded;
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @return array<string,mixed>
     */
    private function readReviewPacket(array &$issues): array
    {
        $path = $this->reviewPacketPath();
        if (! is_file($path)) {
            $issues[] = $this->issue('review_packet', 'review_packet_missing', 'Review packet JSON file was not found.', [
                'path' => $path,
            ]);

            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $issues[] = $this->issue('review_packet', 'invalid_review_packet_json', $exception->getMessage(), [
                'path' => $path,
            ]);

            return [];
        }

        if (! is_array($decoded)) {
            $issues[] = $this->issue('review_packet', 'invalid_review_packet_json', 'Review packet must decode to an object.', [
                'path' => $path,
            ]);

            return [];
        }

        return $decoded;
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @return array<string,array<string,mixed>>
     */
    private function validateReviewPacket(array $packet, array &$issues): array
    {
        if ((string) ($packet['schema_version'] ?? '') !== self::REVIEW_PACKET_SCHEMA_VERSION) {
            $issues[] = $this->issue('review_packet.schema_version', 'unexpected_review_packet_schema', 'Review packet schema mismatch.');
        }
        if ((string) ($packet['packet_id'] ?? '') !== self::REVIEW_PACKET_ID) {
            $issues[] = $this->issue('review_packet.packet_id', 'unexpected_review_packet_id', 'Review packet id mismatch.');
        }
        if ((string) data_get($packet, 'global_review_decision.method_review') !== 'pass') {
            $issues[] = $this->issue('review_packet.method_review', 'method_review_not_passed', 'Global method review must pass.');
        }
        if ((string) data_get($packet, 'global_review_decision.claim_review') !== 'pass') {
            $issues[] = $this->issue('review_packet.claim_review', 'claim_review_not_passed', 'Global claim review must pass.');
        }
        if ((string) data_get($packet, 'global_review_decision.publication_readiness') !== 'ready_for_backend_publish_gate_only') {
            $issues[] = $this->issue('review_packet.publication_readiness', 'publication_readiness_not_gate_only', 'Review packet must be limited to backend publish gate readiness.');
        }
        if ((string) data_get($packet, 'global_review_decision.public_indexing_readiness') !== 'not_ready_until_separate_seo_geo_activation_gate') {
            $issues[] = $this->issue('review_packet.public_indexing_readiness', 'indexing_readiness_too_broad', 'Review packet must not authorize indexing.');
        }

        $pages = (array) ($packet['pages'] ?? []);
        if (count($pages) !== 7) {
            $issues[] = $this->issue('review_packet.pages', 'review_packet_page_count_mismatch', 'Review packet must cover exactly seven IQ method pages.');
        }

        $bySlug = [];
        foreach ($pages as $index => $page) {
            if (! is_array($page)) {
                $issues[] = $this->issue('review_packet.pages.'.$index, 'invalid_review_page', 'Review page entry must be an object.');

                continue;
            }

            $slug = (string) ($page['slug'] ?? '');
            $bySlug[$slug] = $page;

            if ((string) data_get($page, 'method_review.status') !== 'pass') {
                $issues[] = $this->issue($slug.'.method_review', 'page_method_review_not_passed', 'Page method review must pass.');
            }
            if ((string) data_get($page, 'claim_review.status') !== 'pass') {
                $issues[] = $this->issue($slug.'.claim_review', 'page_claim_review_not_passed', 'Page claim review must pass.');
            }
            if ((array) data_get($page, 'claim_review.forbidden_claims_found', []) !== []) {
                $issues[] = $this->issue($slug.'.claim_review.forbidden_claims_found', 'forbidden_claims_found', 'Page review must not contain forbidden claim findings.');
            }
            if (data_get($page, 'approved_for_next_gate') !== true) {
                $issues[] = $this->issue($slug.'.approved_for_next_gate', 'next_gate_not_approved', 'Page must be approved for the backend publish gate.');
            }
        }

        return $bySlug;
    }

    /**
     * @param  array<string,mixed>  $readback
     * @param  array<string,array<string,mixed>>  $reviewBySlug
     * @param  list<array<string,mixed>>  $issues
     * @return list<array<string,mixed>>
     */
    private function gateArticles(array $readback, array $reviewBySlug, array &$issues): array
    {
        $articles = [];

        foreach ((array) ($readback['article_readbacks'] ?? []) as $index => $readbackArticle) {
            if (! is_array($readbackArticle)) {
                $issues[] = $this->issue('article_readbacks.'.$index, 'invalid_article_readback', 'Article readback entry must be an object.');

                continue;
            }

            $slug = (string) ($readbackArticle['slug'] ?? '');
            $article = Article::query()
                ->withoutGlobalScopes()
                ->with(['seoMeta', 'workingRevision'])
                ->where('org_id', 0)
                ->where('locale', 'zh-CN')
                ->where('slug', $slug)
                ->first();

            $articleIssues = [];
            $revision = $article?->workingRevision;
            $seo = $article?->seoMeta;

            if (! $article instanceof Article) {
                $articleIssues[] = $this->issue($slug.'.article', 'article_missing', 'CMS Article draft was not found.');
            } else {
                $this->assertDraftValue($articleIssues, $slug.'.article.status', self::EXPECTED_STATE['status'], (string) $article->status);
                $this->assertDraftValue($articleIssues, $slug.'.article.is_public', false, (bool) $article->is_public);
                $this->assertDraftValue($articleIssues, $slug.'.article.is_indexable', false, (bool) $article->is_indexable);
                $this->assertDraftValue($articleIssues, $slug.'.article.sitemap_eligible', false, (bool) $article->sitemap_eligible);
                $this->assertDraftValue($articleIssues, $slug.'.article.llms_eligible', false, (bool) $article->llms_eligible);
                if ($article->published_at !== null || $article->published_revision_id !== null) {
                    $articleIssues[] = $this->issue($slug.'.article.publication', 'published_marker_present', 'Article must not have publication markers before approval.');
                }
            }

            if (! $seo instanceof ArticleSeoMeta) {
                $articleIssues[] = $this->issue($slug.'.seo_meta', 'seo_meta_missing', 'Article SEO meta was not found.');
            } else {
                $this->assertDraftValue($articleIssues, $slug.'.seo_meta.robots', self::EXPECTED_STATE['robots'], (string) $seo->robots);
                $this->assertDraftValue($articleIssues, $slug.'.seo_meta.is_indexable', false, (bool) $seo->is_indexable);
            }

            if (! $revision instanceof ArticleTranslationRevision) {
                $articleIssues[] = $this->issue($slug.'.working_revision', 'working_revision_missing', 'Working revision was not found.');
            } else {
                $this->assertDraftValue($articleIssues, $slug.'.working_revision.revision_status', ArticleTranslationRevision::STATUS_HUMAN_REVIEW, (string) $revision->revision_status);
            }

            if (! array_key_exists($slug, $reviewBySlug)) {
                $articleIssues[] = $this->issue($slug.'.review_packet', 'review_packet_page_missing', 'Review packet does not include this slug.');
            }

            foreach ($articleIssues as $issue) {
                $issues[] = $issue;
            }

            $approvalCandidate = $article instanceof Article
                && $revision instanceof ArticleTranslationRevision
                && $seo instanceof ArticleSeoMeta
                && $articleIssues === []
                && (string) ($readbackArticle['status'] ?? '') === self::EXPECTED_STATE['status']
                && ($readbackArticle['ok'] ?? false) === true;

            $articles[] = [
                'slug' => $slug,
                'article_id' => $article instanceof Article ? (int) $article->id : null,
                'working_revision_id' => $revision instanceof ArticleTranslationRevision ? (int) $revision->id : null,
                'status' => $article instanceof Article ? (string) $article->status : 'missing',
                'working_revision_status' => $revision instanceof ArticleTranslationRevision ? (string) $revision->revision_status : null,
                'is_public' => $article instanceof Article ? (bool) $article->is_public : null,
                'is_indexable' => $article instanceof Article ? (bool) $article->is_indexable : null,
                'robots' => $seo instanceof ArticleSeoMeta ? (string) $seo->robots : null,
                'sitemap_eligible' => $article instanceof Article ? (bool) $article->sitemap_eligible : null,
                'llms_eligible' => $article instanceof Article ? (bool) $article->llms_eligible : null,
                'review_packet_page_passed' => array_key_exists($slug, $reviewBySlug),
                'approval_candidate' => $approvalCandidate,
                'missing_fields_before_approval_write' => $approvalCandidate ? self::APPROVAL_WRITE_FIELDS : [],
                'blocked_by' => array_values(array_map(static fn (array $issue): string => (string) $issue['code'], $articleIssues)),
            ];
        }

        return $articles;
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     */
    private function assertDraftValue(array &$issues, string $field, mixed $expected, mixed $actual): void
    {
        if ($actual !== $expected) {
            $issues[] = $this->issue($field, 'value_mismatch', sprintf(
                'Expected %s but found %s.',
                json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ));
        }
    }

    private function reviewPacketPath(): string
    {
        $option = trim((string) $this->option('review-packet'));
        $path = $option !== '' ? $option : base_path(self::DEFAULT_REVIEW_PACKET);

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    private function reviewPacketSha256(): ?string
    {
        $path = $this->reviewPacketPath();

        return is_file($path) ? hash_file('sha256', $path) : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function failureSummary(string $code, string $message): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => false,
            'status' => 'blocked',
            'dry_run' => true,
            'execute' => false,
            'pr_id' => self::PR_ID,
            'expected_article_count' => 7,
            'approval_candidate_count' => 0,
            'approval_candidates' => [],
            'articles' => [],
            'issues' => [$this->issue('command', $code, $message)],
            'side_effects' => [
                'db_write' => false,
                'cms_update' => false,
                'review_approval' => false,
                'publish' => false,
                'indexability' => false,
                'sitemap' => false,
                'llms' => false,
                'deploy' => false,
            ],
        ];
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
        $this->line('dry_run=1');
        $this->line('execute=0');
        $this->line('expected_article_count='.(string) ($summary['expected_article_count'] ?? 0));
        $this->line('approval_candidate_count='.(string) ($summary['approval_candidate_count'] ?? 0));

        foreach ((array) ($summary['approval_candidates'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $this->line(sprintf(
                'approval_candidate=%s:article_id=%s:working_revision_id=%s',
                (string) ($candidate['slug'] ?? ''),
                (string) ($candidate['article_id'] ?? ''),
                (string) ($candidate['working_revision_id'] ?? ''),
            ));
        }

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
