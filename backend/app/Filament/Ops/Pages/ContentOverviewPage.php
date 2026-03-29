<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerJobResource;
use App\Filament\Ops\Resources\ContentPackReleaseResource;
use App\Filament\Ops\Resources\ContentPackVersionResource;
use App\Filament\Ops\Support\ContentAccess;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleTag;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\ContentPackRelease;
use App\Models\ContentPackVersion;
use App\Support\OrgContext;
use Filament\Pages\Page;

class ContentOverviewPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Content Workspace';

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
        $guideCount = CareerGuide::query()->count();
        $jobCount = CareerJob::query()->count();
        $versionCount = ContentPackVersion::query()->count();
        $releaseCount = ContentPackRelease::query()->count();

        $this->summaryFields = [
            [
                'label' => 'Editorial objects',
                'value' => (string) ($articleCount + $guideCount + $jobCount),
                'hint' => 'Articles, career guides, and career jobs currently managed inside the Ops CMS layer.',
            ],
            [
                'label' => 'Published articles',
                'value' => (string) $publishedArticleCount,
                'hint' => 'Org-aware article count that is already in a published state.',
            ],
            [
                'label' => 'Taxonomy records',
                'value' => (string) ($categoryCount + $tagCount),
                'hint' => 'Article categories and tags that shape editorial organization.',
            ],
            [
                'label' => 'Career objects',
                'value' => (string) ($guideCount + $jobCount),
                'hint' => 'Global career content managed independently of org-specific article content.',
            ],
            [
                'label' => 'Content versions',
                'value' => (string) $versionCount,
                'hint' => 'Version rows that feed the content release surface and rollout review.',
            ],
            [
                'label' => 'Release records',
                'value' => (string) $releaseCount,
                'hint' => 'Historical release queue records visible from the release workspace.',
            ],
        ];

        $this->recentItems = array_values(array_filter([
            $this->latestItem('Latest article', Article::query()->whereIn('org_id', $tenantOrgIds)->latest('updated_at')->first(), 'title', ArticleResource::getUrl()),
            $this->latestItem('Latest career guide', CareerGuide::query()->latest('updated_at')->first(), 'title', CareerGuideResource::getUrl()),
            $this->latestItem('Latest career job', CareerJob::query()->latest('updated_at')->first(), 'title', CareerJobResource::getUrl()),
            $this->latestItem('Latest version', ContentPackVersion::query()->latest('updated_at')->first(), 'content_package_version', ContentPackVersionResource::getUrl()),
            $this->latestItem('Latest release', ContentPackRelease::query()->latest('updated_at')->first(), 'status', ContentPackReleaseResource::getUrl()),
        ]));
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content_workspace');
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
