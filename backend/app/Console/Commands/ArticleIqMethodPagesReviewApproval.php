<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

final class ArticleIqMethodPagesReviewApproval extends Command
{
    private const SCHEMA_VERSION = 'fermatmind.iq_method_pages.review_approval.v1';

    private const PR_ID = 'IQ-METHOD-PAGES-ZH-CN-CMS-REVIEW-APPROVAL-01';

    private const EXPECTED_STATUS = 'draft_review_only';

    protected $signature = 'articles:iq-method-pages-review-approval
        {--package= : Path to fap-web root, generated/iq-method-pages-zh-cn-v0.2, or its cms-dry-run directory}
        {--review-packet= : Review packet JSON path}
        {--article-lock=* : Exact lock in slug:article_id:working_revision_id form}
        {--reviewed-by= : Admin user id or controlled reviewer actor id recorded on revisions in execute mode}
        {--confirm= : Exact confirmation phrase required with --execute}
        {--execute : Write approval metadata to the locked CMS drafts}
        {--json : Emit a JSON summary}';

    protected $description = 'Controlled approval workflow for zh-CN IQ method CMS Article working revisions without publishing or indexing.';

    public function handle(): int
    {
        try {
            $summary = $this->approve();
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
    private function approve(): array
    {
        $execute = (bool) $this->option('execute');
        $reviewedBy = (int) $this->option('reviewed-by');
        $issues = [];
        $gate = $this->runPublishGate($issues);
        $locks = $this->parseLocks($issues);
        $packetPath = $this->reviewPacketPath();
        $packetSha = is_file($packetPath) ? hash_file('sha256', $packetPath) : null;
        $expectedConfirmation = $this->expectedConfirmation($packetSha, $locks);

        if ($reviewedBy <= 0) {
            $issues[] = $this->issue('reviewed_by', 'reviewed_by_required', '--reviewed-by must be a positive actor id.');
        }

        if ($execute && ! hash_equals($expectedConfirmation, trim((string) $this->option('confirm')))) {
            $issues[] = $this->issue('confirm', 'confirmation_mismatch', 'Exact confirmation phrase is required before review approval writes.', [
                'expected_confirmation' => $expectedConfirmation,
            ]);
        }

        $plans = $this->plansFromGate($gate, $locks, $issues);
        $written = [];

        if ($issues === [] && $execute) {
            $written = DB::transaction(function () use ($plans, $reviewedBy, $packetPath, $packetSha): array {
                return $this->writeApprovals($plans, $reviewedBy, $packetPath, (string) $packetSha);
            });
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $issues === [],
            'status' => $issues === [] ? ($execute ? 'approved' : 'dry_run_pass') : 'blocked',
            'dry_run' => ! $execute,
            'execute' => $execute,
            'generated_at' => now()->toIso8601String(),
            'pr_id' => self::PR_ID,
            'source_gate_pr_id' => (string) ($gate['pr_id'] ?? ''),
            'review_packet' => [
                'path' => $packetPath,
                'sha256' => $packetSha,
                'packet_id' => (string) data_get($gate, 'review_packet.packet_id', ''),
            ],
            'reviewed_by' => $reviewedBy > 0 ? $reviewedBy : null,
            'expected_confirmation' => $expectedConfirmation,
            'expected_article_count' => 7,
            'article_locks' => array_values($locks),
            'articles' => $plans,
            'approved_articles' => $written,
            'issues' => $issues,
            'side_effects' => [
                'db_write' => $execute && $issues === [],
                'cms_update' => $execute && $issues === [],
                'review_approval' => $execute && $issues === [],
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
    private function runPublishGate(array &$issues): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('articles:iq-method-pages-publish-gate', [
            '--package' => (string) $this->option('package'),
            '--review-packet' => (string) $this->option('review-packet'),
            '--json' => true,
        ], $output);

        try {
            $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $issues[] = $this->issue('publish_gate', 'invalid_publish_gate_json', $exception->getMessage());

            return [];
        }

        if (! is_array($decoded)) {
            $issues[] = $this->issue('publish_gate', 'invalid_publish_gate_json', 'Publish gate output must be a JSON object.');

            return [];
        }

        if ($exit !== self::SUCCESS || ($decoded['ok'] ?? false) !== true || (int) ($decoded['approval_candidate_count'] ?? 0) !== 7) {
            $issues[] = $this->issue('publish_gate', 'publish_gate_not_passed', 'Publish gate must pass before review approval writes.');
        }

        return $decoded;
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @return array<string,array{slug:string,article_id:int,working_revision_id:int}>
     */
    private function parseLocks(array &$issues): array
    {
        $locks = [];
        foreach ((array) $this->option('article-lock') as $index => $raw) {
            $parts = explode(':', trim((string) $raw));
            if (count($parts) !== 3 || ! is_numeric($parts[1]) || ! is_numeric($parts[2])) {
                $issues[] = $this->issue('article_lock.'.$index, 'invalid_article_lock', 'Lock must be slug:article_id:working_revision_id.');

                continue;
            }

            $slug = trim($parts[0]);
            $locks[$slug] = [
                'slug' => $slug,
                'article_id' => (int) $parts[1],
                'working_revision_id' => (int) $parts[2],
            ];
        }

        if (count($locks) !== 7) {
            $issues[] = $this->issue('article_lock', 'article_lock_count_mismatch', 'Exactly seven article locks are required.');
        }

        return $locks;
    }

    /**
     * @param  array<string,mixed>  $gate
     * @param  array<string,array{slug:string,article_id:int,working_revision_id:int}>  $locks
     * @param  list<array<string,mixed>>  $issues
     * @return list<array<string,mixed>>
     */
    private function plansFromGate(array $gate, array $locks, array &$issues): array
    {
        $plans = [];
        foreach ((array) ($gate['approval_candidates'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $slug = (string) ($candidate['slug'] ?? '');
            $lock = $locks[$slug] ?? null;
            if (! is_array($lock)) {
                $issues[] = $this->issue($slug.'.article_lock', 'article_lock_missing', 'Article lock missing for candidate slug.');

                continue;
            }

            $articleId = (int) ($candidate['article_id'] ?? 0);
            $revisionId = (int) ($candidate['working_revision_id'] ?? 0);
            if ($lock['article_id'] !== $articleId || $lock['working_revision_id'] !== $revisionId) {
                $issues[] = $this->issue($slug.'.article_lock', 'article_lock_mismatch', 'Article/revision lock does not match publish gate candidate.', [
                    'expected' => $candidate,
                    'actual' => $lock,
                ]);
            }

            $plans[] = [
                'slug' => $slug,
                'article_id' => $articleId,
                'working_revision_id' => $revisionId,
                'from_status' => self::EXPECTED_STATUS,
                'to_status' => 'review_pending',
                'from_revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
                'to_revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
                'publish' => false,
                'indexability' => false,
                'sitemap' => false,
                'llms' => false,
            ];
        }

        return $plans;
    }

    /**
     * @param  list<array<string,mixed>>  $plans
     * @return list<array<string,mixed>>
     */
    private function writeApprovals(array $plans, int $reviewedBy, string $packetPath, string $packetSha): array
    {
        $written = [];
        $now = now();

        foreach ($plans as $plan) {
            $article = Article::query()
                ->withoutGlobalScopes()
                ->whereKey((int) $plan['article_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $revision = ArticleTranslationRevision::query()
                ->withoutGlobalScopes()
                ->whereKey((int) $plan['working_revision_id'])
                ->where('article_id', (int) $article->id)
                ->lockForUpdate()
                ->firstOrFail();
            $seo = ArticleSeoMeta::query()
                ->withoutGlobalScopes()
                ->where('article_id', (int) $article->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertWritePreconditions($article, $revision, $seo);

            $revision->forceFill([
                'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => $now,
                'approved_at' => $now,
            ])->save();

            $article->forceFill([
                'status' => 'review_pending',
                'is_public' => false,
                'is_indexable' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'published_at' => null,
                'published_revision_id' => null,
            ])->save();

            $schema = is_array($seo->schema_json) ? $seo->schema_json : [];
            data_set($schema, 'editorial_package_v1.review_approval_v1', [
                'pr_id' => self::PR_ID,
                'review_packet_path' => $packetPath,
                'review_packet_sha256' => $packetSha,
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => $now->toIso8601String(),
                'approved_at' => $now->toIso8601String(),
                'publish_allowed' => false,
                'indexability_allowed' => false,
                'sitemap_llms_allowed' => false,
            ]);

            $seo->forceFill([
                'schema_json' => $schema,
                'robots' => 'noindex,follow',
                'is_indexable' => false,
            ])->save();

            $written[] = [
                'slug' => (string) $article->slug,
                'article_id' => (int) $article->id,
                'working_revision_id' => (int) $revision->id,
                'status' => (string) $article->status,
                'working_revision_status' => (string) $revision->revision_status,
                'is_public' => (bool) $article->is_public,
                'is_indexable' => (bool) $article->is_indexable,
                'robots' => (string) $seo->robots,
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
                'llms_eligible' => (bool) $article->llms_eligible,
            ];
        }

        return $written;
    }

    private function assertWritePreconditions(Article $article, ArticleTranslationRevision $revision, ArticleSeoMeta $seo): void
    {
        if ((string) $article->status !== self::EXPECTED_STATUS || (bool) $article->is_public || (bool) $article->is_indexable) {
            throw new RuntimeException('article draft/noindex precondition failed before approval write.');
        }
        if ((bool) $article->sitemap_eligible || (bool) $article->llms_eligible || $article->published_at !== null || $article->published_revision_id !== null) {
            throw new RuntimeException('article publish/discoverability precondition failed before approval write.');
        }
        if ((string) $revision->revision_status !== ArticleTranslationRevision::STATUS_HUMAN_REVIEW || $revision->approved_at !== null) {
            throw new RuntimeException('working revision precondition failed before approval write.');
        }
        if ((string) $seo->robots !== 'noindex,follow' || (bool) $seo->is_indexable) {
            throw new RuntimeException('seo noindex precondition failed before approval write.');
        }
    }

    /**
     * @param  array<string,array{slug:string,article_id:int,working_revision_id:int}>  $locks
     */
    private function expectedConfirmation(?string $packetSha, array $locks): string
    {
        $ids = collect($locks)
            ->sortKeys()
            ->map(static fn (array $lock): string => $lock['slug'].':'.$lock['article_id'].':'.$lock['working_revision_id'])
            ->implode(',');

        return 'I explicitly approve IQ-METHOD-PAGES-ZH-CN-CMS-REVIEW-APPROVAL-01 to mark IQ method page revisions reviewed and approved for locks ['.$ids.'] using review packet sha256 '.($packetSha ?? 'missing').'; no publish, no indexability, no sitemap, no llms, no search, no deploy.';
    }

    private function reviewPacketPath(): string
    {
        $path = trim((string) $this->option('review-packet'));

        return str_starts_with($path, '/') ? $path : base_path($path);
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
     * @return array<string,mixed>
     */
    private function failureSummary(string $code, string $message): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => false,
            'status' => 'blocked',
            'dry_run' => ! (bool) $this->option('execute'),
            'execute' => (bool) $this->option('execute'),
            'pr_id' => self::PR_ID,
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
        $this->line('dry_run='.(($summary['dry_run'] ?? true) ? '1' : '0'));
        $this->line('execute='.(($summary['execute'] ?? false) ? '1' : '0'));
        $this->line('approved_count='.(string) count((array) ($summary['approved_articles'] ?? [])));

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
