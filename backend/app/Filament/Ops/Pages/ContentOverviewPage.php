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
        $tenantOrgIds = $this->tenantOrgIds();

        $articleCount = Article::query()->whereIn('org_id', $tenantOrgIds)->count();
        $publishedArticleCount = Article::query()
            ->whereIn('org_id', $tenantOrgIds)
            ->where('status', 'published')
            ->count();
        $categoryCount = ArticleCategory::query()->whereIn('org_id', $tenantOrgIds)->count();
        $tagCount = ArticleTag::query()->whereIn('org_id', $tenantOrgIds)->count();
        $guideCount = CareerGuide::query()->where('org_id', 0)->count();
        $jobCount = CareerJob::query()->where('org_id', 0)->count();
        $draftEditorialCount = Article::query()
            ->whereIn('org_id', $tenantOrgIds)
            ->where('status', 'draft')
            ->count()
            + CareerGuide::query()->where('org_id', 0)->where('status', CareerGuide::STATUS_DRAFT)->count()
            + CareerJob::query()->where('org_id', 0)->where('status', CareerJob::STATUS_DRAFT)->count();
        $publishedEditorialCount = $publishedArticleCount
            + CareerGuide::query()->where('org_id', 0)->where('status', CareerGuide::STATUS_PUBLISHED)->count()
            + CareerJob::query()->where('org_id', 0)->where('status', CareerJob::STATUS_PUBLISHED)->count();

        $this->summaryFields = [
            [
                'label' => 'Current org editorial',
                'value' => (string) $articleCount,
                'hint' => 'Article records bound to the currently selected Ops organization.',
            ],
            [
                'label' => 'Current org taxonomy',
                'value' => (string) ($categoryCount + $tagCount),
                'hint' => 'Categories and tags available to the currently selected organization.',
            ],
            [
                'label' => 'Global career content',
                'value' => (string) ($guideCount + $jobCount),
                'hint' => 'Career guides and jobs remain global content objects with org_id=0 authoring semantics.',
            ],
            [
                'label' => 'Draft release queue',
                'value' => (string) $draftEditorialCount,
                'hint' => 'Draft editorial records visible to the lightweight content release workspace.',
            ],
            [
                'label' => 'Published editorial',
                'value' => (string) $publishedEditorialCount,
                'hint' => 'Published article, guide, and job records managed through the CMS workspace.',
            ],
        ];

        $this->recentItems = array_values(array_filter([
            $this->latestItem('Latest article', Article::query()->whereIn('org_id', $tenantOrgIds)->latest('updated_at')->first(), 'title', ArticleResource::getUrl()),
            $this->latestItem('Latest category', ArticleCategory::query()->whereIn('org_id', $tenantOrgIds)->latest('updated_at')->first(), 'name', ArticleCategoryResource::getUrl()),
            $this->latestItem('Latest tag', ArticleTag::query()->whereIn('org_id', $tenantOrgIds)->latest('updated_at')->first(), 'name', ArticleTagResource::getUrl()),
            $this->latestItem('Latest career guide', CareerGuide::query()->where('org_id', 0)->latest('updated_at')->first(), 'title', CareerGuideResource::getUrl()),
            $this->latestItem('Latest career job', CareerJob::query()->where('org_id', 0)->latest('updated_at')->first(), 'title', CareerJobResource::getUrl()),
        ]));
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
    private function tenantOrgIds(): array
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());

        return $orgId > 0 ? [0, $orgId] : [0];
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
            'title' => $title !== '' ? $title : 'Untitled',
            'meta' => $updatedAt !== '' ? 'Updated '.$updatedAt : 'Recently updated',
            'url' => $indexUrl,
        ];
    }
}
