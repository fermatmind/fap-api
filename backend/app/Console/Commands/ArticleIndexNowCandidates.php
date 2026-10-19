<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ContentMaterialDecision;
use App\Services\Cms\ArticleMaterialDecisionService;
use App\Services\SeoIntel\SearchChannelQueue\ArticleIndexNowChangeGate;
use App\Services\SeoIntel\SearchChannelQueue\ArticleIndexNowPublicVerifier;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueApprovalExecutor;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueBoundedLiveExecutor;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueIdempotency;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueuePlanner;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueProviderHoldRecorder;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueWriteService;
use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @review-surface search_submission_queue_approval
 */
final class ArticleIndexNowCandidates extends Command
{
    protected $signature = 'articles:indexnow-candidates
        {--article-id= : Restrict to one exact article id}
        {--channels=indexnow : Comma-separated indexnow,baidu_push channels}
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
        $channels = array_values(array_unique(explode(',', (string) $this->option('channels'))));
        if ($channels === [] || array_diff($channels, ['indexnow', 'baidu_push']) !== [] || ! is_int($limit) || $limit < 1 || $limit > 100
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

            $metadata = json_decode((string) ($truth->first()->metadata_json ?? '{}'), true);
            if (($metadata['content_hash'] ?? null) !== $current->search_surface_fingerprint) {
                $items[] = [...$base, 'issues' => ['url_truth_search_surface_not_current']];

                continue;
            }

            foreach ($channels as $channel) {
                $plan = $this->articlePlan($queue, $article, $current, $canonicalUrl, $channel);
                $state = $plan['state'];
                $item = [...$base, 'channel' => $channel, 'state' => $state, 'url_hash' => hash('sha256', $canonicalUrl), 'issues' => $plan['issues']];
                if ($state === 'pending_public_html_verification' && $execute) {
                    $item = [...$item, ...$this->executeCandidate($article, $current, $canonicalUrl, $plan, $queue, $public, $writer, $approval, $live, $channel)];
                }
                $items[] = $item;
            }
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

    /** @return array<string, mixed> */
    private function articlePlan(SearchChannelQueuePlanner $queue, Article $article, ContentMaterialDecision $decision, string $url, string $channel): array
    {
        $plan = $queue->plan($channel, 'article', 1, $url);
        $candidate = $plan['selected_candidate'] ?? null;
        $blocked = ['state' => 'held', 'issues' => ['exact_queue_plan_not_ready']];
        if (! is_array($candidate) || ($candidate['canonical_url'] ?? null) !== $url
            || ($candidate['source_authority'] ?? null) !== 'backend_cms'
            || (int) ($plan['eligible_count'] ?? 0) !== 1) {
            return $blocked;
        }
        $key = app(SearchChannelQueueIdempotency::class)->key($url, (string) $article->locale, $channel, 'content:'.$decision->search_surface_fingerprint);
        $connection = DB::connection((string) config('seo_intel.connection', 'seo_intel'));
        $truth = $connection->table('seo_urls')->where('canonical_url', $url)->where('page_entity_type', 'article')->where('locale', $article->locale)->get();
        $metadata = json_decode((string) ($truth->first()->metadata_json ?? '{}'), true);
        if ($truth->count() !== 1 || (string) $truth->first()->entity_id_or_slug !== (string) $article->id
            || ($metadata['content_hash'] ?? null) !== $decision->search_surface_fingerprint) {
            return $blocked;
        }
        $existing = $connection->table('seo_search_channel_queue_items')->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            if ((string) $existing->canonical_url !== $url || (string) $existing->locale !== (string) $article->locale
                || (string) $existing->entity_id !== (string) $article->id || (string) $existing->page_entity_type !== 'article'
                || (string) $existing->source_authority !== 'backend_cms' || (string) $existing->channel !== $channel
                || (string) $existing->content_hash !== (string) $decision->search_surface_fingerprint) {
                return $blocked;
            }
            if ((string) $existing->execution_state === 'submitted') {
                return ['state' => 'already_submitted', 'issues' => []];
            }
            $duplicate = $plan['blocked_items'][0]['duplicate_queue_item_id'] ?? null;
            if ((int) $duplicate !== (int) $existing->id
                || (string) $existing->execution_state !== 'dry_run_ready'
                || ! in_array((string) $existing->approval_state, ['pending', 'approved'], true)) {
                return ['state' => 'already_queued', 'issues' => ['existing_queue_item_'.(string) $existing->execution_state]];
            }

            return ['state' => 'pending_public_html_verification', 'issues' => [], 'idempotency_key' => $key, 'existing_id' => (int) $existing->id];
        }
        $planned = $plan['planned_items'][0] ?? null;
        if ((int) ($plan['planned_queue_count'] ?? 0) !== 1 || ! is_array($planned)
            || ($planned['idempotency_key'] ?? null) !== $key
            || ($planned['content_hash'] ?? null) !== $decision->search_surface_fingerprint) {
            return $blocked;
        }

        return ['state' => 'pending_public_html_verification', 'issues' => [], 'idempotency_key' => $key, 'planned_item' => $planned];
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
        string $channel,
    ): array {
        if (! (bool) config('seo_intel.article_'.($channel === 'indexnow' ? 'indexnow' : 'baidu').'_auto_enabled', false)) {
            return ['state' => 'execution_blocked', 'issues' => ['article_channel_auto_disabled']];
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

        $replanned = $this->articlePlan($queue, $freshArticle, $decision, $canonicalUrl, $channel);
        if ($replanned['state'] !== 'pending_public_html_verification'
            || ($replanned['idempotency_key'] ?? null) !== ($plan['idempotency_key'] ?? null)) {
            return ['state' => 'execution_blocked', 'public_html_checked' => true, 'issues' => ['exact_queue_plan_changed']];
        }

        try {
            $id = (int) ($replanned['existing_id'] ?? 0);
            if ($id < 1) {
                $written = $writer->write([$replanned['planned_item']]);
                $id = (int) ($written['queue_item_ids'][0] ?? 0);
            }
            if ($id < 1) {
                return ['state' => 'execution_blocked', 'public_html_checked' => true, 'writes_attempted' => true, 'issues' => ['queue_write_failed']];
            }
            $item = DB::connection((string) config('seo_intel.connection', 'seo_intel'))->table('seo_search_channel_queue_items')->find($id);
            if ($item?->approval_state === 'pending' && $item?->execution_state === 'dry_run_ready') {
                $phrase = $approval->approvalPhrase([$id], [$channel]);
                $approved = $approval->approve([$id], [$channel], null, hash('sha256', $phrase), 'article-search-auto', false);
                if (($approved['status'] ?? null) !== 'success') {
                    return ['state' => 'execution_blocked', 'public_html_checked' => true, 'writes_attempted' => true, 'queue_item_id' => $id, 'issues' => ['queue_approval_failed']];
                }
            }
            if ($channel === 'baidu_push') {
                $hold = $this->recordTransportHold($id);
                if ($hold !== null) {
                    return $hold;
                }
            }
            $submitted = $live->submitVerifiedArticle($id, (int) $article->id, $canonicalUrl, $channel);
            if ($channel === 'baidu_push' && ($submitted['status'] ?? null) !== 'success') {
                $hold = $this->recordTransportHold($id);
                if ($hold !== null) {
                    return [...$hold, 'search_submission_attempted' => (bool) ($submitted['search_submission_attempted'] ?? false)];
                }
            }

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

    /** @return array<string, mixed>|null */
    private function recordTransportHold(int $id): ?array
    {
        $recorder = app(SearchChannelQueueProviderHoldRecorder::class);
        $probe = $recorder->record([$id], ['baidu_push'], 'transport_security_unavailable', null, null, 'article-search-auto', true);
        if (($probe['status'] ?? null) !== 'success') {
            return null;
        }
        $phrase = $recorder->approvalPhrase([$id], ['baidu_push'], 'transport_security_unavailable');
        $hold = $recorder->record([$id], ['baidu_push'], 'transport_security_unavailable', null, hash('sha256', $phrase), 'article-search-auto', false);

        return ['state' => ($hold['status'] ?? null) === 'success' ? 'provider_security_hold' : 'execution_blocked',
            'queue_item_id' => $id, 'public_html_checked' => true, 'writes_attempted' => true,
            'search_submission_attempted' => false, 'issues' => ['transport_security_unavailable'], 'indexing_status' => 'not_verified'];
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
        } else {
            try {
                if (! hash_equals((string) $decision->search_surface_fingerprint, app(ArticleMaterialDecisionService::class)->currentSearchFingerprint($article))) {
                    $issues[] = 'seo_surface_differs_from_material_decision';
                }
            } catch (Throwable) {
                $issues[] = 'current_search_surface_unavailable';
            }
        }

        return $issues;
    }
}
