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

class ContentWorkspacePage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Content Overview';

    protected static ?string $navigationLabel = 'Content Workspace';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'content-workspace';

    protected static string $view = 'filament.ops.pages.content-workspace';

    /** @var list<array<string, mixed>> */
    public array $editorialCards = [];

    /** @var list<array<string, mixed>> */
    public array $dataCards = [];

    /** @var list<array<string, mixed>> */
    public array $permissionFields = [];

    public function mount(): void
    {
        $currentOrgIds = $this->currentOrgIds();

        $this->editorialCards = [
            $this->workspaceCard(
                'Articles',
                'Org-aware editorial workspace for long-form CMS publishing, SEO metadata, and visibility state.',
                Article::query()->whereIn('org_id', $currentOrgIds)->count(),
                ArticleResource::getUrl(),
                ArticleResource::getUrl('create')
            ),
            $this->workspaceCard(
                'Career Guides',
                'Global guide authoring workspace for structured career education content and related object links.',
                CareerGuide::query()->where('org_id', 0)->count(),
                CareerGuideResource::getUrl(),
                CareerGuideResource::getUrl('create')
            ),
            $this->workspaceCard(
                'Career Jobs',
                'Global job profile workspace for structured role narratives, signals, sections, and SEO metadata.',
                CareerJob::query()->where('org_id', 0)->count(),
                CareerJobResource::getUrl(),
                CareerJobResource::getUrl('create')
            ),
        ];

        $this->dataCards = [
            $this->workspaceCard(
                'Categories',
                'Taxonomy records that shape article organization and filtering inside the editorial workspace.',
                ArticleCategory::query()->whereIn('org_id', $currentOrgIds)->count(),
                ArticleCategoryResource::getUrl(),
                ArticleCategoryResource::getUrl('create')
            ),
            $this->workspaceCard(
                'Tags',
                'Lightweight metadata used to cluster related editorial records and speed up operator discovery.',
                ArticleTag::query()->whereIn('org_id', $currentOrgIds)->count(),
                ArticleTagResource::getUrl(),
                ArticleTagResource::getUrl('create')
            ),
        ];

        $this->permissionFields = [
            [
                'label' => 'Content read',
                'value' => ContentAccess::canRead() ? 'Enabled' : 'Missing',
                'kind' => 'pill',
                'state' => ContentAccess::canRead() ? 'success' : 'failed',
                'hint' => 'Allows browsing workspace pages, list pages, and release summaries.',
            ],
            [
                'label' => 'Content write',
                'value' => ContentAccess::canWrite() ? 'Enabled' : 'Missing',
                'kind' => 'pill',
                'state' => ContentAccess::canWrite() ? 'success' : 'warning',
                'hint' => 'Controls create and edit actions across articles, career guides, jobs, categories, and tags.',
            ],
            [
                'label' => 'Content release',
                'value' => ContentAccess::canRelease() ? 'Enabled' : 'Missing',
                'kind' => 'pill',
                'state' => ContentAccess::canRelease() ? 'success' : 'gray',
                'hint' => 'Required for content release queue access and release execution actions.',
            ],
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content_overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.content_workspace');
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
    private function workspaceCard(string $title, string $description, int $count, string $indexUrl, string $createUrl): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'meta' => $count.' records',
            'index_url' => $indexUrl,
            'create_url' => $createUrl,
            'can_write' => ContentAccess::canWrite(),
        ];
    }
}
