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
                'label' => __('ops.custom_pages.editorial_operations.fields.article_drafts'),
                'value' => (string) $articleDraftCount,
                'hint' => __('ops.custom_pages.editorial_operations.fields.article_drafts_hint'),
            ],
            [
                'label' => __('ops.custom_pages.editorial_operations.fields.published_articles'),
                'value' => (string) $articlePublishedCount,
                'hint' => __('ops.custom_pages.editorial_operations.fields.published_articles_hint'),
            ],
            [
                'label' => __('ops.custom_pages.editorial_operations.fields.career_drafts'),
                'value' => (string) ($guideDraftCount + $jobDraftCount),
                'hint' => __('ops.custom_pages.editorial_operations.fields.career_drafts_hint'),
            ],
            [
                'label' => __('ops.custom_pages.editorial_operations.fields.career_published'),
                'value' => (string) ($guidePublishedCount + $jobPublishedCount),
                'hint' => __('ops.custom_pages.editorial_operations.fields.career_published_hint'),
            ],
            [
                'label' => __('ops.custom_pages.editorial_operations.fields.release_ready'),
                'value' => (string) ($articleDraftCount + $guideDraftCount + $jobDraftCount),
                'hint' => __('ops.custom_pages.editorial_operations.fields.release_ready_hint'),
            ],
        ];

        $this->surfaceCards = [
            $this->surfaceCard(
                __('ops.custom_pages.editorial_operations.surfaces.articles'),
                __('ops.custom_pages.editorial_operations.surfaces.articles_desc'),
                __('ops.custom_pages.editorial_operations.surfaces.current_org'),
                $articleDraftCount,
                $articlePublishedCount,
                ArticleResource::getUrl(),
                ArticleResource::getUrl('create')
            ),
            $this->surfaceCard(
                __('ops.custom_pages.editorial_operations.surfaces.career_guides'),
                __('ops.custom_pages.editorial_operations.surfaces.career_guides_desc'),
                __('ops.custom_pages.editorial_operations.surfaces.global_content'),
                $guideDraftCount,
                $guidePublishedCount,
                CareerGuideResource::getUrl(),
                CareerGuideResource::getUrl('create')
            ),
            $this->surfaceCard(
                __('ops.custom_pages.editorial_operations.surfaces.career_jobs'),
                __('ops.custom_pages.editorial_operations.surfaces.career_jobs_desc'),
                __('ops.custom_pages.editorial_operations.surfaces.global_content'),
                $jobDraftCount,
                $jobPublishedCount,
                CareerJobResource::getUrl(),
                CareerJobResource::getUrl('create')
            ),
        ];

        $this->boundaryFields = [
            [
                'label' => __('ops.custom_pages.editorial_operations.fields.write_boundary'),
                'value' => ContentAccess::canWrite() ? __('ops.custom_pages.common.values.enabled') : __('ops.custom_pages.common.values.missing'),
                'kind' => 'pill',
                'state' => ContentAccess::canWrite() ? 'success' : 'warning',
                'hint' => __('ops.custom_pages.editorial_operations.fields.write_boundary_hint'),
            ],
            [
                'label' => __('ops.custom_pages.editorial_operations.fields.release_handoff'),
                'value' => ContentAccess::canRelease() ? __('ops.status.ready') : __('ops.custom_pages.common.values.read_only'),
                'kind' => 'pill',
                'state' => ContentAccess::canRelease() ? 'success' : 'gray',
                'hint' => __('ops.custom_pages.editorial_operations.fields.release_handoff_hint'),
            ],
            [
                'label' => __('ops.custom_pages.editorial_operations.fields.org_model'),
                'value' => __('ops.custom_pages.editorial_operations.fields.org_model_value'),
                'hint' => __('ops.custom_pages.editorial_operations.fields.org_model_hint'),
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
            'meta' => __('ops.custom_pages.editorial_operations.surfaces.meta', ['draft' => $draftCount, 'published' => $publishedCount]),
            'index_url' => $indexUrl,
            'create_url' => $createUrl,
            'can_write' => ContentAccess::canWrite(),
        ];
    }
}
