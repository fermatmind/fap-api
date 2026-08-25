<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Resources\ArticleCategoryResource;
use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\ArticleTagResource;
use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerJobResource;
use App\Filament\Ops\Resources\InterpretationGuideResource;
use App\Filament\Ops\Resources\SupportArticleResource;
use App\Filament\Ops\Support\ContentAccess;
use App\Services\Ops\SeoContentScopeViewModel;
use Filament\Pages\Page;

class ContentWorkspacePage extends Page
{
    protected static bool $shouldRegisterNavigation = false;

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
    public array $optionalContentCards = [];

    /** @var list<array<string, mixed>> */
    public array $snapshotFields = [];

    /** @var list<array<string, mixed>> */
    public array $permissionFields = [];

    public function mount(): void
    {
        $metrics = app(SeoContentScopeViewModel::class)->workspaceMetrics();

        $this->snapshotFields = [
            [
                'label' => __('ops.custom_pages.editorial_operations.fields.article_drafts'),
                'value' => (string) $metrics['articles']['draft'],
                'hint' => __('ops.custom_pages.editorial_operations.fields.article_drafts_hint'),
            ],
            [
                'label' => __('ops.custom_pages.editorial_operations.fields.published_articles'),
                'value' => (string) $metrics['articles']['published'],
                'hint' => __('ops.custom_pages.editorial_operations.fields.published_articles_hint'),
            ],
            [
                'label' => __('ops.custom_pages.editorial_operations.fields.career_drafts'),
                'value' => (string) ($metrics['career_guides']['draft'] + $metrics['career_jobs']['draft']),
                'hint' => __('ops.custom_pages.editorial_operations.fields.career_drafts_hint'),
            ],
            [
                'label' => __('ops.custom_pages.editorial_operations.fields.career_published'),
                'value' => (string) ($metrics['career_guides']['published'] + $metrics['career_jobs']['published']),
                'hint' => __('ops.custom_pages.editorial_operations.fields.career_published_hint'),
            ],
            [
                'label' => __('ops.custom_pages.editorial_operations.fields.release_ready'),
                'value' => (string) $metrics['draft_handoff'],
                'hint' => __('ops.custom_pages.editorial_operations.fields.release_ready_hint'),
            ],
        ];

        $this->editorialCards = [
            $this->workspaceCard(
                __('ops.custom_pages.content_workspace.cards.articles'),
                __('ops.custom_pages.content_workspace.cards.articles_desc'),
                $metrics['articles'],
                ArticleResource::getUrl(),
                ArticleResource::getUrl('create')
            ),
            $this->workspaceCard(
                __('ops.custom_pages.content_workspace.cards.career_guides'),
                __('ops.custom_pages.content_workspace.cards.career_guides_desc'),
                $metrics['career_guides'],
                CareerGuideResource::getUrl(),
                CareerGuideResource::getUrl('create')
            ),
            $this->workspaceCard(
                __('ops.custom_pages.content_workspace.cards.career_jobs'),
                __('ops.custom_pages.content_workspace.cards.career_jobs_desc'),
                $metrics['career_jobs'],
                CareerJobResource::getUrl(),
                CareerJobResource::getUrl('create')
            ),
        ];

        $this->dataCards = [
            $this->workspaceCard(
                __('ops.custom_pages.content_workspace.cards.categories'),
                __('ops.custom_pages.content_workspace.cards.categories_desc'),
                ['total' => $metrics['categories'], 'draft' => 0, 'published' => 0],
                ArticleCategoryResource::getUrl(),
                ArticleCategoryResource::getUrl('create')
            ),
            $this->workspaceCard(
                __('ops.custom_pages.content_workspace.cards.tags'),
                __('ops.custom_pages.content_workspace.cards.tags_desc'),
                ['total' => $metrics['tags'], 'draft' => 0, 'published' => 0],
                ArticleTagResource::getUrl(),
                ArticleTagResource::getUrl('create')
            ),
        ];

        $this->optionalContentCards = [
            $this->optionalContentCard(
                __('ops.custom_pages.content_workspace.cards.interpretation_guides'),
                __('ops.custom_pages.content_workspace.cards.interpretation_guides_desc'),
                $metrics['interpretation_guides'],
                InterpretationGuideResource::getUrl(),
                InterpretationGuideResource::getUrl('create'),
                InterpretationGuideResource::canCreate(),
            ),
            $this->optionalContentCard(
                __('ops.custom_pages.content_workspace.cards.support_articles'),
                __('ops.custom_pages.content_workspace.cards.support_articles_desc'),
                $metrics['support_articles'],
                SupportArticleResource::getUrl(),
                SupportArticleResource::getUrl('create'),
                SupportArticleResource::canCreate(),
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
        return __('ops.group.content');
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
     * @param  array{total:int,draft:int,published:int}  $counts
     * @return array<string, mixed>
     */
    private function workspaceCard(string $title, string $description, array $counts, string $indexUrl, string $createUrl): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'count' => $counts['total'],
            'draft_count' => $counts['draft'],
            'published_count' => $counts['published'],
            'meta' => __('ops.custom_pages.content_workspace.cards.record_count', ['count' => $counts['total']]),
            'status_meta' => __('ops.custom_pages.content_workspace.cards.status_count', [
                'draft' => $counts['draft'],
                'published' => $counts['published'],
            ]),
            'index_url' => $indexUrl,
            'create_url' => $createUrl,
            'can_write' => ContentAccess::canWrite(),
        ];
    }

    /** @return array<string, mixed> */
    private function optionalContentCard(
        string $title,
        string $description,
        int $count,
        string $indexUrl,
        string $createUrl,
        bool $canCreate,
    ): array {
        return [
            'title' => $title,
            'description' => $description,
            'count' => $count,
            'meta' => $count === 0
                ? __('ops.custom_pages.content_workspace.cards.not_enabled')
                : __('ops.custom_pages.content_workspace.cards.record_count', ['count' => $count]),
            'index_url' => $indexUrl,
            'create_url' => $createUrl,
            'can_create' => $canCreate,
        ];
    }
}
