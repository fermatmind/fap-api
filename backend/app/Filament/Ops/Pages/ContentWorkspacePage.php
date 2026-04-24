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

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = null;

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
                __('ops.custom_pages.content_workspace.cards.articles'),
                __('ops.custom_pages.content_workspace.cards.articles_desc'),
                Article::query()->whereIn('org_id', $currentOrgIds)->count(),
                ArticleResource::getUrl(),
                ArticleResource::getUrl('create')
            ),
            $this->workspaceCard(
                __('ops.custom_pages.content_workspace.cards.career_guides'),
                __('ops.custom_pages.content_workspace.cards.career_guides_desc'),
                CareerGuide::query()->where('org_id', 0)->count(),
                CareerGuideResource::getUrl(),
                CareerGuideResource::getUrl('create')
            ),
            $this->workspaceCard(
                __('ops.custom_pages.content_workspace.cards.career_jobs'),
                __('ops.custom_pages.content_workspace.cards.career_jobs_desc'),
                CareerJob::query()->where('org_id', 0)->count(),
                CareerJobResource::getUrl(),
                CareerJobResource::getUrl('create')
            ),
        ];

        $this->dataCards = [
            $this->workspaceCard(
                __('ops.custom_pages.content_workspace.cards.categories'),
                __('ops.custom_pages.content_workspace.cards.categories_desc'),
                ArticleCategory::query()->whereIn('org_id', $currentOrgIds)->count(),
                ArticleCategoryResource::getUrl(),
                ArticleCategoryResource::getUrl('create')
            ),
            $this->workspaceCard(
                __('ops.custom_pages.content_workspace.cards.tags'),
                __('ops.custom_pages.content_workspace.cards.tags_desc'),
                ArticleTag::query()->whereIn('org_id', $currentOrgIds)->count(),
                ArticleTagResource::getUrl(),
                ArticleTagResource::getUrl('create')
            ),
        ];

        $this->permissionFields = [
            [
                'label' => __('ops.custom_pages.content_workspace.permissions.content_read'),
                'value' => ContentAccess::canRead() ? __('ops.custom_pages.common.values.enabled') : __('ops.custom_pages.common.values.missing'),
                'kind' => 'pill',
                'state' => ContentAccess::canRead() ? 'success' : 'failed',
                'hint' => __('ops.custom_pages.content_workspace.permissions.content_read_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_workspace.permissions.content_write'),
                'value' => ContentAccess::canWrite() ? __('ops.custom_pages.common.values.enabled') : __('ops.custom_pages.common.values.missing'),
                'kind' => 'pill',
                'state' => ContentAccess::canWrite() ? 'success' : 'warning',
                'hint' => __('ops.custom_pages.content_workspace.permissions.content_write_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_workspace.permissions.content_release'),
                'value' => ContentAccess::canRelease() ? __('ops.custom_pages.common.values.enabled') : __('ops.custom_pages.common.values.missing'),
                'kind' => 'pill',
                'state' => ContentAccess::canRelease() ? 'success' : 'gray',
                'hint' => __('ops.custom_pages.content_workspace.permissions.content_release_hint'),
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

    public function getTitle(): string
    {
        return __('ops.custom_pages.content_workspace.title');
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
            'meta' => __('ops.custom_pages.content_workspace.cards.record_count', ['count' => $count]),
            'index_url' => $indexUrl,
            'create_url' => $createUrl,
            'can_write' => ContentAccess::canWrite(),
        ];
    }
}
