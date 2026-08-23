<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleResource\Pages;

use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use App\Models\Article;
use App\Support\SchemaBaseline;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListArticles extends ListRecords
{
    use HasSharedListEmptyState;

    private const SAVED_VIEWS = [
        'all',
        'draft',
        'review',
        'pending_publish',
        'seo_gap',
        'translate_expired',
    ];

    protected static string $resource = ArticleResource::class;

    #[Url(as: 'savedView')]
    public string $savedView = 'all';

    public function mount(): void
    {
        parent::mount();

        if (! in_array($this->savedView, self::SAVED_VIEWS, true)) {
            $this->savedView = 'all';
        }
    }

    public function getTitle(): string|Htmlable
    {
        return __('ops.resources.articles.plural');
    }

    public function getSubheading(): ?string
    {
        return __('ops.resources.articles.list_subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('ops.resources.articles.actions.create'))
                ->icon('heroicon-o-plus'),
        ];
    }

    /**
     * Render the native Filament header together with authoritative saved views.
     */
    public function getHeader(): ?View
    {
        return view('filament.ops.articles.partials.saved-views', [
            'actions' => $this->getCachedHeaderActions(),
            'breadcrumbs' => filament()->hasBreadcrumbs() ? $this->getBreadcrumbs() : [],
            'heading' => $this->getHeading(),
            'subheading' => $this->getSubheading(),
            'activeSavedView' => $this->savedView,
            'savedViews' => $this->savedViews(),
        ]);
    }

    public function applySavedView(string $savedView): void
    {
        $this->savedView = in_array($savedView, self::SAVED_VIEWS, true) ? $savedView : 'all';

        // Saved views replace prior table filters. The locale filter may then
        // reapply its configured default when Filament rebuilds the table.
        $this->tableFilters = [];
        $this->resetTable();
    }

    protected function getTableQuery(): ?Builder
    {
        $query = parent::getTableQuery();

        if (! $query instanceof Builder) {
            return $query;
        }

        return match ($this->savedView) {
            'draft' => $query->where('status', 'draft'),
            'review' => $query->where('translation_status', Article::TRANSLATION_STATUS_HUMAN_REVIEW),
            'pending_publish' => $query->where('status', 'scheduled'),
            'seo_gap' => $this->applySeoGap($query),
            'translate_expired' => $query->where('translation_status', Article::TRANSLATION_STATUS_STALE),
            default => $query,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function savedViews(): array
    {
        if (! SchemaBaseline::hasTable('articles')) {
            return [];
        }

        // Reuse the ArticleResource authoritative query scope (public content,
        // org_id = 0) instead of a bare withoutGlobalScopes() full-table scan.
        // All saved-view counts therefore share the same real org boundary.
        $base = ArticleResource::getEloquentQuery();

        $allCount = (clone $base)->count();
        $myDraftCount = (clone $base)->where('status', 'draft')->count();
        $reviewCount = (clone $base)->where('translation_status', Article::TRANSLATION_STATUS_HUMAN_REVIEW)
            ->count();
        $pendingPublishCount = (clone $base)->where('status', 'scheduled')
            ->count();
        $translateExpiredCount = (clone $base)->where('translation_status', Article::TRANSLATION_STATUS_STALE)
            ->count();

        // SEO gap: published/public articles whose SEO meta is missing key fields.
        $seoGapCount = 0;
        if (SchemaBaseline::hasTable('article_seo_meta')) {
            $seoGapCount = $this->applySeoGap(clone $base)->count();
        }

        return [
            [
                'id' => 'all',
                'label' => __('ops.resources.articles.saved_views.all'),
                'count' => $allCount,
                'tone' => 'neutral',
            ],
            [
                'id' => 'draft',
                'label' => __('ops.resources.articles.saved_views.draft'),
                'count' => $myDraftCount,
                'tone' => 'gray',
            ],
            [
                'id' => 'review',
                'label' => __('ops.resources.articles.saved_views.review'),
                'count' => $reviewCount,
                'tone' => 'warning',
            ],
            [
                'id' => 'pending_publish',
                'label' => __('ops.resources.articles.saved_views.pending_publish'),
                'count' => $pendingPublishCount,
                'tone' => 'info',
            ],
            [
                'id' => 'seo_gap',
                'label' => __('ops.resources.articles.saved_views.seo_gap'),
                'count' => $seoGapCount,
                'tone' => 'danger',
            ],
            [
                'id' => 'translate_expired',
                'label' => __('ops.resources.articles.saved_views.translate_expired'),
                'count' => $translateExpiredCount,
                'tone' => 'warning',
            ],
        ];
    }

    private function applySeoGap(Builder $query): Builder
    {
        if (! SchemaBaseline::hasTable('article_seo_meta')) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('status', 'published')
            ->where('is_public', true)
            ->whereDoesntHave('seoMeta', function (Builder $query): void {
                $query
                    ->whereNotNull('seo_title')
                    ->whereRaw("TRIM(seo_title) <> ''")
                    ->whereNotNull('seo_description')
                    ->whereRaw("TRIM(seo_description) <> ''");
            });
    }
}
