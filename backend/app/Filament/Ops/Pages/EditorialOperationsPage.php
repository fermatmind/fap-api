<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerJobResource;
use App\Filament\Ops\Support\ContentAccess;
use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Support\OrgContext;
use Filament\Pages\Page;

class EditorialOperationsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationGroup = 'Editorial';

    protected static ?string $navigationLabel = 'Editorial Operations';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'editorial-operations';

    protected static string $view = 'filament.ops.pages.editorial-operations';

    /** @var list<array<string, mixed>> */
    public array $snapshotFields = [];

    /** @var list<array<string, mixed>> */
    public array $surfaceCards = [];

    /** @var list<array<string, mixed>> */
    public array $boundaryFields = [];

    public function mount(): void
    {
        $currentOrgIds = $this->currentOrgIds();

        $articleDraftCount = Article::query()
            ->whereIn('org_id', $currentOrgIds)
            ->where('status', 'draft')
            ->count();
        $articlePublishedCount = Article::query()
            ->whereIn('org_id', $currentOrgIds)
            ->where('status', 'published')
            ->count();
        $guideDraftCount = CareerGuide::query()
            ->where('org_id', 0)
            ->where('status', CareerGuide::STATUS_DRAFT)
            ->count();
        $guidePublishedCount = CareerGuide::query()
            ->where('org_id', 0)
            ->where('status', CareerGuide::STATUS_PUBLISHED)
            ->count();
        $jobDraftCount = CareerJob::query()
            ->where('org_id', 0)
            ->where('status', CareerJob::STATUS_DRAFT)
            ->count();
        $jobPublishedCount = CareerJob::query()
            ->where('org_id', 0)
            ->where('status', CareerJob::STATUS_PUBLISHED)
            ->count();

        $this->snapshotFields = [
            [
                'label' => 'Current org article drafts',
                'value' => (string) $articleDraftCount,
                'hint' => 'Org-scoped article records still waiting for editorial completion or publish approval.',
            ],
            [
                'label' => 'Current org published articles',
                'value' => (string) $articlePublishedCount,
                'hint' => 'Article records already published for the selected Ops organization.',
            ],
            [
                'label' => 'Global career drafts',
                'value' => (string) ($guideDraftCount + $jobDraftCount),
                'hint' => 'Career guides and jobs remain global authoring surfaces with org_id=0.',
            ],
            [
                'label' => 'Global career published',
                'value' => (string) ($guidePublishedCount + $jobPublishedCount),
                'hint' => 'Published global career records visible to the current public content contract.',
            ],
            [
                'label' => 'Release-ready inventory',
                'value' => (string) ($articleDraftCount + $guideDraftCount + $jobDraftCount),
                'hint' => 'Draft editorial records that can be handed off into the content release queue.',
            ],
        ];

        $this->surfaceCards = [
            $this->surfaceCard(
                'Articles',
                'Current org editorial surface for long-form article authoring, locale handling, SEO fields, and publish state.',
                'Current org',
                $articleDraftCount,
                $articlePublishedCount,
                ArticleResource::getUrl(),
                ArticleResource::getUrl('create')
            ),
            $this->surfaceCard(
                'Career Guides',
                'Global guide authoring surface for structured career education content, public delivery, and SEO metadata.',
                'Global content',
                $guideDraftCount,
                $guidePublishedCount,
                CareerGuideResource::getUrl(),
                CareerGuideResource::getUrl('create')
            ),
            $this->surfaceCard(
                'Career Jobs',
                'Global job profile surface for narrative content, role signals, and release-ready public delivery state.',
                'Global content',
                $jobDraftCount,
                $jobPublishedCount,
                CareerJobResource::getUrl(),
                CareerJobResource::getUrl('create')
            ),
        ];

        $this->boundaryFields = [
            [
                'label' => 'Write boundary',
                'value' => ContentAccess::canWrite() ? 'Enabled' : 'Missing',
                'kind' => 'pill',
                'state' => ContentAccess::canWrite() ? 'success' : 'warning',
                'hint' => 'Write permission opens create and edit actions across the editorial surfaces.',
            ],
            [
                'label' => 'Release handoff',
                'value' => ContentAccess::canRelease() ? 'Available' : 'Read only',
                'kind' => 'pill',
                'state' => ContentAccess::canRelease() ? 'success' : 'gray',
                'hint' => 'Publishing still happens in the dedicated content release workspace, not inside this operations page.',
            ],
            [
                'label' => 'Org model',
                'value' => 'Articles are org-scoped; career content is global',
                'hint' => 'This page keeps the current production bootstrap boundary explicit instead of hiding the split inside individual resources.',
            ],
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.editorial');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.editorial_operations');
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
     * @return array<string, mixed>
     */
    private function surfaceCard(
        string $title,
        string $description,
        string $scope,
        int $draftCount,
        int $publishedCount,
        string $indexUrl,
        string $createUrl,
    ): array {
        return [
            'title' => $title,
            'description' => $description,
            'scope' => $scope,
            'draft_count' => $draftCount,
            'published_count' => $publishedCount,
            'meta' => $draftCount.' draft | '.$publishedCount.' published',
            'index_url' => $indexUrl,
            'create_url' => $createUrl,
            'can_write' => ContentAccess::canWrite(),
        ];
    }
}
