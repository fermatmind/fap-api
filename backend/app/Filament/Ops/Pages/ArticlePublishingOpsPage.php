<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\EditorialReviewAudit;
use App\Filament\Ops\Support\EditorialReviewChecklist;
use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\AuditLog;
use App\Models\EditorialReview;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class ArticlePublishingOpsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 16;

    protected static ?string $slug = 'article-publishing-ops';

    protected static string $view = 'filament.ops.pages.article-publishing-ops';

    /** @var list<array<string,mixed>> */
    public array $queueFields = [];

    /** @var list<array<string,mixed>> */
    public array $dailyHealthFields = [];

    /** @var list<array<string,mixed>> */
    public array $queueRows = [];

    /** @var list<array<string,mixed>> */
    public array $recentImportRows = [];

    /** @var list<array<string,mixed>> */
    public array $releaseRows = [];

    /** @var list<array<string,mixed>> */
    public array $reviewDueRows = [];

    public function mount(): void
    {
        $today = Carbon::now()->startOfDay();
        $lastDay = Carbon::now()->subDay();

        /** @var Collection<int, Article> $articles */
        $articles = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->with(['seoMeta', 'workingRevision'])
            ->latest('updated_at')
            ->limit(500)
            ->get();

        /** @var Collection<int, ArticleEditorialPackageImport> $imports */
        $imports = ArticleEditorialPackageImport::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->latest('created_at')
            ->limit(200)
            ->get();

        /** @var Collection<int, AuditLog> $releaseFailures */
        $releaseFailures = AuditLog::query()
            ->withoutGlobalScopes()
            ->whereIn('action', [
                'content_release_cache_signal',
                'content_release_broadcast',
                'content_release_failure_alert',
            ])
            ->where('created_at', '>=', $lastDay)
            ->where('result', 'failed')
            ->latest('created_at')
            ->limit(10)
            ->get();

        $draftArticles = $articles->where('status', 'draft');
        $reviewPendingCount = EditorialReview::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('content_type', 'article')
            ->where('workflow_state', EditorialReview::STATE_IN_REVIEW)
            ->count();

        $claimBlockedCount = $imports
            ->where('status', ArticleEditorialPackageImport::STATUS_BLOCKED)
            ->filter(fn (ArticleEditorialPackageImport $row): bool => data_get($row->claim_result_json, 'status') === 'blocked')
            ->count();
        $missingMetadataCount = $draftArticles->filter(fn (Article $article): bool => $this->missingMetadata($article) !== [])->count();
        $missingMediaCount = $this->missingImportSummaryCount($imports, 'media_json.status');
        $missingReferencesCount = $this->missingImportSummaryCount($imports, 'references_json.status');
        $missingGraphCount = $this->missingImportSummaryCount($imports, 'graph_json.status');
        $publishReadyCount = $articles->filter(fn (Article $article): bool => $this->isPublishReady($article))->count();
        $reviewDue = $this->reviewDueRows($articles);

        $this->queueFields = [
            $this->field(__('ops.custom_pages.article_publishing_ops.fields.draft_queue'), (string) $draftArticles->count(), __('ops.custom_pages.article_publishing_ops.fields.draft_queue_hint')),
            $this->field(__('ops.custom_pages.article_publishing_ops.fields.review_pending'), (string) $reviewPendingCount, __('ops.custom_pages.article_publishing_ops.fields.review_pending_hint')),
            $this->field(__('ops.custom_pages.article_publishing_ops.fields.claim_blocked'), (string) $claimBlockedCount, __('ops.custom_pages.article_publishing_ops.fields.claim_blocked_hint'), $claimBlockedCount > 0 ? 'danger' : 'success'),
            $this->field(__('ops.custom_pages.article_publishing_ops.fields.publish_ready'), (string) $publishReadyCount, __('ops.custom_pages.article_publishing_ops.fields.publish_ready_hint'), $publishReadyCount > 0 ? 'success' : 'gray'),
        ];

        $this->dailyHealthFields = [
            $this->field(__('ops.custom_pages.article_publishing_ops.fields.today_zh_drafts'), (string) $this->todayDraftCount($articles, 'zh-CN', $today), __('ops.custom_pages.article_publishing_ops.fields.today_zh_drafts_hint')),
            $this->field(__('ops.custom_pages.article_publishing_ops.fields.today_en_drafts'), (string) $this->todayDraftCount($articles, 'en', $today), __('ops.custom_pages.article_publishing_ops.fields.today_en_drafts_hint')),
            $this->field(__('ops.custom_pages.article_publishing_ops.fields.missing_metadata'), (string) $missingMetadataCount, __('ops.custom_pages.article_publishing_ops.fields.missing_metadata_hint'), $missingMetadataCount > 0 ? 'warning' : 'success'),
            $this->field(__('ops.custom_pages.article_publishing_ops.fields.missing_media'), (string) $missingMediaCount, __('ops.custom_pages.article_publishing_ops.fields.missing_media_hint'), $missingMediaCount > 0 ? 'warning' : 'success'),
            $this->field(__('ops.custom_pages.article_publishing_ops.fields.missing_references'), (string) $missingReferencesCount, __('ops.custom_pages.article_publishing_ops.fields.missing_references_hint'), $missingReferencesCount > 0 ? 'warning' : 'success'),
            $this->field(__('ops.custom_pages.article_publishing_ops.fields.missing_graph'), (string) $missingGraphCount, __('ops.custom_pages.article_publishing_ops.fields.missing_graph_hint'), $missingGraphCount > 0 ? 'warning' : 'success'),
            $this->field(__('ops.custom_pages.article_publishing_ops.fields.release_failed'), (string) $releaseFailures->count(), __('ops.custom_pages.article_publishing_ops.fields.release_failed_hint'), $releaseFailures->isNotEmpty() ? 'danger' : 'success'),
            $this->field(__('ops.custom_pages.article_publishing_ops.fields.review_due'), (string) count($reviewDue), __('ops.custom_pages.article_publishing_ops.fields.review_due_hint'), $reviewDue !== [] ? 'warning' : 'gray'),
        ];

        $this->queueRows = $articles
            ->filter(fn (Article $article): bool => (string) $article->status !== 'published' || $this->isPublishReady($article) || $this->missingMetadata($article) !== [])
            ->take(12)
            ->map(fn (Article $article): array => $this->articleQueueRow($article, $imports))
            ->values()
            ->all();

        $this->recentImportRows = $imports
            ->take(12)
            ->map(fn (ArticleEditorialPackageImport $row): array => $this->importRow($row))
            ->values()
            ->all();

        $this->releaseRows = $releaseFailures
            ->map(fn (AuditLog $row): array => $this->releaseRow($row))
            ->values()
            ->all();

        $this->reviewDueRows = $reviewDue;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content_overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.article_publishing_ops');
    }

    public function getTitle(): string
    {
        return __('ops.custom_pages.article_publishing_ops.title');
    }

    public static function canAccess(): bool
    {
        return ContentAccess::canRead();
    }

    /**
     * @return array<string,string>
     */
    private function field(string $label, string $value, string $hint, string $state = 'gray'): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'hint' => $hint,
            'kind' => 'pill',
            'state' => $state,
        ];
    }

    private function todayDraftCount(Collection $articles, string $locale, Carbon $today): int
    {
        return $articles
            ->where('status', 'draft')
            ->where('locale', $locale)
            ->filter(fn (Article $article): bool => $article->created_at instanceof \DateTimeInterface && $article->created_at >= $today)
            ->count();
    }

    private function missingImportSummaryCount(Collection $imports, string $path): int
    {
        return $imports
            ->filter(fn (ArticleEditorialPackageImport $row): bool => data_get($row, $path) === 'missing')
            ->count();
    }

    /**
     * @return list<string>
     */
    private function missingMetadata(Article $article): array
    {
        $missing = [];
        foreach ([
            'seo_title' => 'seo_title',
            'seo_description' => 'meta_description',
            'canonical_url' => 'canonical',
            'robots' => 'robots',
        ] as $field => $label) {
            if (! filled(data_get($article->seoMeta, $field))) {
                $missing[] = $label;
            }
        }

        if (! filled($article->excerpt)) {
            $missing[] = 'excerpt';
        }

        return $missing;
    }

    private function isPublishReady(Article $article): bool
    {
        $review = EditorialReviewAudit::latestState('article', $article);

        return (string) $article->status === 'draft'
            && ($review['state'] ?? null) === EditorialReviewAudit::STATE_APPROVED
            && EditorialReviewChecklist::missing('article', $article) === [];
    }

    private function articleQueueRow(Article $article, Collection $imports): array
    {
        $latestImport = $imports
            ->first(fn (ArticleEditorialPackageImport $row): bool => (int) $row->article_id === (int) $article->id
                || ((string) $row->slug === (string) $article->slug && (string) $row->locale === (string) $article->locale));
        $review = EditorialReviewAudit::latestState('article', $article);
        $missingMetadata = $this->missingMetadata($article);

        return [
            'title' => (string) $article->title,
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'content_track' => (string) ($latestImport?->content_track ?? data_get($article->cover_image_variants, 'editorial_package_v1.content_track', 'unknown')),
            'article_status' => (string) $article->status,
            'validation_status' => (string) ($latestImport?->status ?? 'untracked'),
            'claim_status' => (string) data_get($latestImport, 'claim_result_json.status', 'untracked'),
            'media_status' => (string) data_get($latestImport, 'media_json.status', filled($article->cover_image_url) && filled($article->cover_image_alt) ? 'complete' : 'missing'),
            'references_status' => (string) data_get($latestImport, 'references_json.status', 'untracked'),
            'graph_status' => (string) data_get($latestImport, 'graph_json.status', 'untracked'),
            'review_status' => (string) ($review['label'] ?? __('ops.custom_pages.common.filters.ready')),
            'next_action' => $this->nextAction($article, $latestImport, $missingMetadata, $review),
            'updated_at' => optional($article->updated_at)?->toDateTimeString() ?? '',
        ];
    }

    /**
     * @param  list<string>  $missingMetadata
     * @param  array<string,mixed>|null  $review
     */
    private function nextAction(Article $article, ?ArticleEditorialPackageImport $latestImport, array $missingMetadata, ?array $review): string
    {
        if ($latestImport instanceof ArticleEditorialPackageImport && (string) $latestImport->status === ArticleEditorialPackageImport::STATUS_BLOCKED) {
            return __('ops.custom_pages.article_publishing_ops.next_actions.fix_claims');
        }

        if ($missingMetadata !== []) {
            return __('ops.custom_pages.article_publishing_ops.next_actions.complete_metadata');
        }

        if ((string) data_get($latestImport, 'media_json.status', 'complete') === 'missing') {
            return __('ops.custom_pages.article_publishing_ops.next_actions.complete_media');
        }

        if ((string) data_get($latestImport, 'references_json.status', 'complete') === 'missing') {
            return __('ops.custom_pages.article_publishing_ops.next_actions.complete_references');
        }

        if ((string) data_get($latestImport, 'graph_json.status', 'complete') === 'missing') {
            return __('ops.custom_pages.article_publishing_ops.next_actions.complete_graph');
        }

        if (($review['state'] ?? null) === EditorialReviewAudit::STATE_APPROVED && (string) $article->status !== 'published') {
            return __('ops.custom_pages.article_publishing_ops.next_actions.ready_to_publish');
        }

        if (($review['state'] ?? null) === EditorialReviewAudit::STATE_IN_REVIEW) {
            return __('ops.custom_pages.article_publishing_ops.next_actions.await_review');
        }

        return __('ops.custom_pages.article_publishing_ops.next_actions.submit_review');
    }

    private function importRow(ArticleEditorialPackageImport $row): array
    {
        return [
            'title' => (string) ($row->title ?: $row->slug),
            'slug' => (string) $row->slug,
            'locale' => (string) $row->locale,
            'content_track' => (string) ($row->content_track ?: 'unknown'),
            'status' => (string) $row->status,
            'claim_status' => (string) data_get($row->claim_result_json, 'status', 'unknown'),
            'references_count' => (string) $row->references_count,
            'body_hash' => substr((string) $row->body_hash, 0, 12),
            'created_at' => optional($row->created_at)?->toDateTimeString() ?? '',
        ];
    }

    private function releaseRow(AuditLog $row): array
    {
        return [
            'title' => (string) data_get($row->meta_json, 'title', $row->target_type ?? 'content'),
            'target' => trim((string) (($row->target_type ?? 'unknown').' #'.($row->target_id ?? ''))),
            'action' => (string) $row->action,
            'result' => (string) ($row->result ?: 'failed'),
            'created_at' => optional($row->created_at)?->toDateTimeString() ?? '',
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function reviewDueRows(Collection $articles): array
    {
        return $articles
            ->where('status', 'published')
            ->filter(function (Article $article): bool {
                if (! $article->published_at instanceof \DateTimeInterface) {
                    return false;
                }

                return in_array((int) $article->published_at->diffInDays(Carbon::now()), [7, 14, 28], true);
            })
            ->take(8)
            ->map(fn (Article $article): array => [
                'title' => (string) $article->title,
                'slug' => (string) $article->slug,
                'locale' => (string) $article->locale,
                'published_at' => optional($article->published_at)?->toDateString() ?? '',
                'age_days' => (string) (int) $article->published_at?->diffInDays(Carbon::now()),
            ])
            ->values()
            ->all();
    }
}
