<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Resources\ArticleCategoryResource;
use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\ArticleTagResource;
use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerJobResource;
use App\Filament\Ops\Support\ContentAccess;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleTag;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Support\OrgContext;
use Filament\Pages\Page;

class ContentOverviewPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Content Overview';

    protected static ?string $navigationLabel = 'Content Overview';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'content-overview';

    protected static string $view = 'filament.ops.pages.content-overview';

    /** @var list<array<string, mixed>> */
    public array $summaryFields = [];

    /** @var list<array<string, string>> */
    public array $recentItems = [];

    public function mount(): void
    {
        $currentOrgIds = $this->currentOrgIds();

        $articleCount = Article::query()->whereIn('org_id', $currentOrgIds)->count();
        $publishedArticleCount = Article::query()
            ->whereIn('org_id', $currentOrgIds)
            ->where('status', 'published')
            ->count();
        $categoryCount = ArticleCategory::query()->whereIn('org_id', $currentOrgIds)->count();
        $tagCount = ArticleTag::query()->whereIn('org_id', $currentOrgIds)->count();
        $guideCount = CareerGuide::query()->where('org_id', 0)->count();
        $jobCount = CareerJob::query()->where('org_id', 0)->count();
        $draftEditorialCount = Article::query()
            ->whereIn('org_id', $currentOrgIds)
            ->where('status', 'draft')
            ->count()
            + CareerGuide::query()->where('org_id', 0)->where('status', CareerGuide::STATUS_DRAFT)->count()
            + CareerJob::query()->where('org_id', 0)->where('status', CareerJob::STATUS_DRAFT)->count();
        $publishedEditorialCount = $publishedArticleCount
            + CareerGuide::query()->where('org_id', 0)->where('status', CareerGuide::STATUS_PUBLISHED)->count()
            + CareerJob::query()->where('org_id', 0)->where('status', CareerJob::STATUS_PUBLISHED)->count();

        $this->summaryFields = [
            [
                'label' => __('ops.custom_pages.content_overview.fields.current_org_editorial'),
                'value' => (string) $articleCount,
                'hint' => __('ops.custom_pages.content_overview.fields.current_org_editorial_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_overview.fields.current_org_taxonomy'),
                'value' => (string) ($categoryCount + $tagCount),
                'hint' => __('ops.custom_pages.content_overview.fields.current_org_taxonomy_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_overview.fields.global_career_content'),
                'value' => (string) ($guideCount + $jobCount),
                'hint' => __('ops.custom_pages.content_overview.fields.global_career_content_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_overview.fields.draft_release_queue'),
                'value' => (string) $draftEditorialCount,
                'hint' => __('ops.custom_pages.content_overview.fields.draft_release_queue_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_overview.fields.published_editorial'),
                'value' => (string) $publishedEditorialCount,
                'hint' => __('ops.custom_pages.content_overview.fields.published_editorial_hint'),
            ],
        ];

        $this->recentItems = array_values(array_filter([
            $this->latestItem(__('ops.custom_pages.content_overview.recent.latest_article'), Article::query()->whereIn('org_id', $currentOrgIds)->latest('updated_at')->first(), 'title', ArticleResource::getUrl()),
            $this->latestItem(__('ops.custom_pages.content_overview.recent.latest_category'), ArticleCategory::query()->whereIn('org_id', $currentOrgIds)->latest('updated_at')->first(), 'name', ArticleCategoryResource::getUrl()),
            $this->latestItem(__('ops.custom_pages.content_overview.recent.latest_tag'), ArticleTag::query()->whereIn('org_id', $currentOrgIds)->latest('updated_at')->first(), 'name', ArticleTagResource::getUrl()),
            $this->latestItem(__('ops.custom_pages.content_overview.recent.latest_career_guide'), CareerGuide::query()->where('org_id', 0)->latest('updated_at')->first(), 'title', CareerGuideResource::getUrl()),
            $this->latestItem(__('ops.custom_pages.content_overview.recent.latest_career_job'), CareerJob::query()->where('org_id', 0)->latest('updated_at')->first(), 'title', CareerJobResource::getUrl()),
        ]));
    }

    public function getTitle(): string
    {
        return __('ops.custom_pages.content_overview.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content_overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.content_overview');
    }

    public static function canAccess(): bool
    {
        return ContentAccess::canRead();
    }

    /**
     * @return array<int, int>
     */
    private function currentOrgIds(): array
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());

        return $orgId > 0 ? [$orgId] : [];
    }

    /**
     * @return array<string, string>|null
     */
    private function latestItem(string $label, ?object $record, string $titleField, string $indexUrl): ?array
    {
        if (! is_object($record)) {
            return null;
        }

        $title = trim((string) data_get($record, $titleField, ''));
        $updatedAt = trim((string) data_get($record, 'updated_at', ''));

        return [
            'label' => $label,
            'title' => $title !== '' ? $title : __('ops.custom_pages.common.values.untitled'),
            'meta' => $updatedAt !== ''
                ? __('ops.custom_pages.content_overview.recent.updated', ['timestamp' => $updatedAt])
                : __('ops.custom_pages.content_overview.recent.recently_updated'),
            'url' => $indexUrl,
        ];
    }
}
