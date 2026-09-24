<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ContentMaterialDecision;
use App\Services\SeoIntel\SearchChannelQueue\ArticleIndexNowChangeGate;
use App\Services\SeoIntel\SearchChannelQueue\ArticleIndexNowPublicVerifier;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueApprovalExecutor;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueBoundedLiveExecutor;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueuePlanner;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueWriteService;
use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ArticleIndexNowCandidates extends Command
{
    protected $signature = 'articles:indexnow-candidates
        {--article-id= : Restrict to one exact article id}
        {--limit=20 : Maximum recent article decisions to inspect, 1..100}
        {--execute : Verify public HTML, then queue and submit exact changed URLs}';

    protected $description = 'Plan or submit exact new or search-surface-changed article URLs after public HTML verification.';

    public function handle(
        ArticleIndexNowChangeGate $changes,
        SearchChannelQueuePlanner $queue,
        ArticleIndexNowPublicVerifier $public,
        SearchChannelQueueWriteService $writer,
        SearchChannelQueueApprovalExecutor $approval,
        SearchChannelQueueBoundedLiveExecutor $live,
    ): int {
        $execute = (bool) $this->option('execute');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $articleId = $this->option('article-id');
        if (! is_int($limit) || $limit < 1 || $limit > 100
            || ($articleId !== null && (filter_var($articleId, FILTER_VALIDATE_INT) === false || (int) $articleId < 1))) {
            $this->line((string) json_encode(['status' => 'blocked', 'issue' => 'invalid_scope'], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $decisions = ContentMaterialDecision::query()
            ->where('family', 'article')
            ->where('publication_state', 'published')
            ->whereNotNull('search_surface_fingerprint')
            ->when($articleId !== null, static fn ($query) => $query->where('authority_subject_key', 'article:'.(int) $articleId))
            ->latest('id')
            ->limit($limit)
            ->get();

        $seen = [];
        $items = [];
        foreach ($decisions as $current) {
            $identity = (int) $current->org_id.'|'.(string) $current->locale.'|'.(string) $current->authority_subject_key;
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;

            $latest = ContentMaterialDecision::query()
                ->where('org_id', $current->org_id)
                ->where('family', 'article')
                ->where('locale', $current->locale)
                ->where('authority_subject_key', $current->authority_subject_key)
                ->latest('id')
                ->first();
            if ((int) $latest?->id !== (int) $current->id) {
                continue;
            }

            $previous = ContentMaterialDecision::query()
                ->where('org_id', $current->org_id)
                ->where('family', 'article')
                ->where('locale', $current->locale)
                ->where('authority_subject_key', $current->authority_subject_key)
                ->where('id', '<', $current->id)
                ->latest('id')
                ->first();
            $reason = $changes->reason($current, $previous);
            $articleId = (int) substr((string) $current->authority_subject_key, strlen('article:'));
            $base = [
                'article_id' => $articleId,
                'decision_id' => (int) $current->id,
                'reason' => $reason,
                'state' => 'held',
                'url_hash' => null,
                'issues' => [],
            ];

            if (! $changes->shouldNotify($reason)) {
                $items[] = $base;

                continue;
            }

            $article = Article::query()->withoutGlobalScopes()->find($articleId);
            if (! $article instanceof Article || (int) $article->org_id !== 0
                || (int) $article->org_id !== (int) $current->org_id
                || (string) $article->locale !== (string) $current->locale) {
                $items[] = [...$base, 'issues' => ['article_authority_mismatch']];

                continue;
            }

            $path = (str_starts_with((string) $article->locale, 'zh') ? '/zh/articles/' : '/en/articles/').(string) $article->slug;
            $canonicalUrl = 'https://fermatmind.com'.$path;
            $issues = $this->articleIssues($article, $current, $path);
            $connectionName = (string) config('seo_intel.connection', 'seo_intel');
            try {
                $truth = SchemaBaseline::tableExists('seo_urls', $connectionName)
                    ? DB::connection($connectionName)->table('seo_urls')
                        ->where('canonical_url', $canonicalUrl)
                        ->where('page_entity_type', 'article')
                        ->where('locale', $article->locale)
                        ->get()
                    : collect();
            } catch (Throwable) {
                $truth = collect();
            }
            if ($truth->count() !== 1
                || (string) $truth->first()->entity_id_or_slug !== (string) $article->id
                || (string) $truth->first()->source_authority !== 'backend_cms'
                || (string) $truth->first()->indexability_state !== 'indexable') {
                $issues[] = 'exact_url_truth_not_ready';
            }

            if ($issues !== []) {
                $items[] = [...$base, 'url_hash' => hash('sha256', $canonicalUrl), 'issues' => array_values(array_unique($issues))];

                continue;
            }

            $plan = $queue->plan('indexnow', 'article', 1, $canonicalUrl);
            $candidate = $plan['selected_candidate'] ?? null;
            $ready = (int) ($plan['planned_queue_count'] ?? 0) === 1
                && is_array($candidate)
                && ($candidate['canonical_url'] ?? null) === $canonicalUrl
                && ($candidate['source_authority'] ?? null) === 'backend_cms';
            $plannedItem = $plan['planned_items'][0] ?? null;
            if ($ready && is_array($plannedItem)) {
                $alreadyQueued = $this->existingExactQueueItem((string) ($plannedItem['idempotency_key'] ?? ''));
                if ($alreadyQueued !== null) {
                    $items[] = [
                        ...$base,
                        'state' => match ($alreadyQueued) {
                            'submitted' => 'already_submitted',
                            'source_unavailable' => 'execution_blocked',
                            default => 'already_queued',
                        },
                        'url_hash' => hash('sha256', $canonicalUrl),
                        'issues' => $alreadyQueued === 'submitted' ? [] : [$alreadyQueued === 'source_unavailable' ? 'queue_source_unavailable' : 'existing_queue_item_'.$alreadyQueued],
                    ];

                    continue;
                }
            }
            if ($ready && $execute) {
                $items[] = [
                    ...$base,
                    'url_hash' => hash('sha256', $canonicalUrl),
                    ...$this->executeCandidate($article, $current, $canonicalUrl, $plan, $queue, $public, $writer, $approval, $live),
                ];

                continue;
            }
            $items[] = [
                ...$base,
                'state' => $ready ? 'pending_public_html_verification' : 'held',
                'url_hash' => hash('sha256', $canonicalUrl),
                'issues' => $ready ? [] : ['indexnow_queue_plan_not_ready'],
            ];
        }

        $payload = [
            'status' => count(array_filter($items, static fn (array $item): bool => in_array($item['state'], ['submission_failed', 'execution_blocked'], true))) > 0 ? 'failed' : 'success',
            'read_only' => ! $execute,
            'candidate_count' => count(array_filter($items, static fn (array $item): bool => $item['state'] === 'pending_public_html_verification')),
            'submitted_count' => count(array_filter($items, static fn (array $item): bool => $item['state'] === 'provider_accepted')),
            'items' => $items,
            'external_calls_attempted' => count(array_filter($items, static fn (array $item): bool => (bool) ($item['public_html_checked'] ?? false))) > 0,
            'search_submission_attempted' => count(array_filter($items, static fn (array $item): bool => (bool) ($item['search_submission_attempted'] ?? false))) > 0,
            'writes_attempted' => count(array_filter($items, static fn (array $item): bool => (bool) ($item['writes_attempted'] ?? false))) > 0,
        ];
        $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $payload['status'] === 'success' ? self::SUCCESS : self::FAILURE;
    }

    private function existingExactQueueItem(string $idempotencyKey): ?string
    {
        if ($idempotencyKey === '') {
            return null;
        }

        $connectionName = (string) config('seo_intel.connection', 'seo_intel');
        try {
            $state = DB::connection($connectionName)->table('seo_search_channel_queue_items')
                ->where('idempotency_key', $idempotencyKey)->value('execution_state');

            return is_string($state) ? $state : null;
        } catch (Throwable) {
            return 'source_unavailable';
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function executeCandidate(
        Article $article,
        ContentMaterialDecision $decision,
        string $canonicalUrl,
        array $plan,
        SearchChannelQueuePlanner $queue,
        ArticleIndexNowPublicVerifier $public,
        SearchChannelQueueWriteService $writer,
        SearchChannelQueueApprovalExecutor $approval,
        SearchChannelQueueBoundedLiveExecutor $live,
    ): array {
        if (! (bool) config('seo_intel.article_indexnow_auto_enabled', false)) {
            return ['state' => 'execution_blocked', 'issues' => ['article_indexnow_auto_disabled']];
        }

        $seo = ArticleSeoMeta::query()->withoutGlobalScopes()
            ->where('org_id', 0)->where('article_id', $article->id)->where('locale', $article->locale)->first();
        if (! $seo instanceof ArticleSeoMeta) {
            return ['state' => 'execution_blocked', 'issues' => ['seo_meta_missing']];
        }

        $publicIssues = $public->issues($canonicalUrl, $seo);
        if ($publicIssues !== []) {
            return ['state' => 'public_html_not_ready', 'public_html_checked' => true, 'issues' => $publicIssues];
        }

        $freshArticle = $article->fresh();
        $freshSeo = $seo->fresh();
        if (! $freshArticle instanceof Article || ! $freshSeo instanceof ArticleSeoMeta
            || $freshSeo->getAttributes() !== $seo->getAttributes()
            || (int) ContentMaterialDecision::query()->where('org_id', 0)->where('family', 'article')
                ->where('locale', $article->locale)->where('authority_subject_key', 'article:'.$article->id)
                ->latest('id')->value('id') !== (int) $decision->id
            || $this->articleIssues($freshArticle, $decision, (string) parse_url($canonicalUrl, PHP_URL_PATH)) !== []) {
            return ['state' => 'execution_blocked', 'public_html_checked' => true, 'issues' => ['article_changed_during_public_verification']];
        }

        $planned = $plan['planned_items'] ?? [];
        $replanned = $queue->plan('indexnow', 'article', 1, $canonicalUrl);
        if (! is_array($planned) || count($planned) !== 1 || ! is_array($planned[0])
            || ($planned[0]['canonical_url'] ?? null) !== $canonicalUrl
            || ($planned[0]['channel'] ?? null) !== 'indexnow'
            || (int) ($replanned['planned_queue_count'] ?? 0) !== 1
            || ($replanned['planned_items'][0]['idempotency_key'] ?? null) !== ($planned[0]['idempotency_key'] ?? null)) {
            return ['state' => 'execution_blocked', 'public_html_checked' => true, 'issues' => ['exact_queue_plan_missing']];
        }

        try {
            $written = $writer->write([$planned[0]]);
            $id = (int) ($written['queue_item_ids'][0] ?? 0);
            if ($id < 1) {
                return ['state' => 'execution_blocked', 'public_html_checked' => true, 'writes_attempted' => true, 'issues' => ['queue_write_failed']];
            }
            $phrase = $approval->approvalPhrase([$id], ['indexnow']);
            $approved = $approval->approve([$id], ['indexnow'], null, hash('sha256', $phrase), 'article-indexnow-auto', false);
            if (($approved['status'] ?? null) !== 'success') {
                return ['state' => 'execution_blocked', 'public_html_checked' => true, 'writes_attempted' => true, 'queue_item_id' => $id, 'issues' => ['queue_approval_failed']];
            }
            $submitted = $live->submitVerifiedArticle($id, (int) $article->id, $canonicalUrl);

            return [
                'state' => ($submitted['status'] ?? null) === 'success' ? 'provider_accepted' : 'submission_failed',
                'public_html_checked' => true,
                'writes_attempted' => true,
                'search_submission_attempted' => (bool) ($submitted['search_submission_attempted'] ?? false),
                'queue_item_id' => $id,
                'http_status' => $submitted['http_status'] ?? null,
                'indexing_status' => 'not_verified',
                'issues' => ($submitted['status'] ?? null) === 'success' ? [] : (array) ($submitted['issues'] ?? ['submission_failed']),
            ];
        } catch (Throwable) {
            return ['state' => 'execution_blocked', 'public_html_checked' => true, 'writes_attempted' => true, 'issues' => ['queue_execution_unavailable']];
        }
    }

    /** @return list<string> */
    private function articleIssues(Article $article, ContentMaterialDecision $decision, string $path): array
    {
        $seo = ArticleSeoMeta::query()->withoutGlobalScopes()
            ->where('org_id', $article->org_id)
            ->where('article_id', $article->id)
            ->where('locale', $article->locale)
            ->first();
        $issues = [];
        if ((string) $article->status !== 'published' || ! (bool) $article->is_public
            || ! (bool) $article->is_indexable || ! (bool) $article->sitemap_eligible
            || (int) ($article->published_revision_id ?? 0) !== (int) $decision->authority_revision
            || (string) $decision->public_identity !== '/'.(string) $article->locale.'/articles/'.(string) $article->slug) {
            $issues[] = 'article_not_public_indexable';
        }
        if (! $seo instanceof ArticleSeoMeta || ! (bool) $seo->is_indexable
            || strtolower((string) preg_replace('/\s+/', '', (string) $seo->robots)) !== 'index,follow'
            || ! in_array((string) $seo->canonical_url, [$path, 'https://fermatmind.com'.$path], true)) {
            $issues[] = 'seo_meta_not_canonical_indexable';
        } elseif ($seo->updated_at !== null && $decision->created_at !== null
            && $seo->updated_at->greaterThan($decision->created_at)) {
            $issues[] = 'seo_meta_newer_than_material_decision';
        }

        return $issues;
    }
}
