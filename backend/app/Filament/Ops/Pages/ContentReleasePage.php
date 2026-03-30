<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerJobResource;
use App\Filament\Ops\Resources\DataPageResource;
use App\Filament\Ops\Resources\MethodPageResource;
use App\Filament\Ops\Resources\PersonalityProfileResource;
use App\Filament\Ops\Resources\TopicProfileResource;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\EditorialReviewAudit;
use App\Filament\Ops\Support\EditorialReviewChecklist;
use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\DataPage;
use App\Models\MethodPage;
use App\Models\PersonalityProfile;
use App\Models\TopicProfile;
use App\Services\Audit\AuditLogger;
use App\Services\Ops\SeoQualityAuditService;
use App\Support\OrgContext;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

class ContentReleasePage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $navigationGroup = 'Content Release';

    protected static ?string $navigationLabel = 'Content Release';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'content-release';

    protected static string $view = 'filament.ops.pages.content-release';

    /** @var list<array<string, mixed>> */
    public array $releaseFields = [];

    /** @var list<array<string, mixed>> */
    public array $releaseItems = [];

    /** @var list<array<string, mixed>> */
    public array $surfaceCards = [];

    public string $typeFilter = 'all';

    public string $statusFilter = 'draft';

    public function mount(): void
    {
        $this->refreshWorkspace();
    }

    public function updatedTypeFilter(): void
    {
        $this->refreshWorkspace();
    }

    public function updatedStatusFilter(): void
    {
        $this->refreshWorkspace();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content_release');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.content_release');
    }

    public static function canAccess(): bool
    {
        return ContentAccess::canRelease();
    }

    public function releaseItem(string $type, int $id): void
    {
        if (! ContentAccess::canRelease()) {
            throw new AuthorizationException('You do not have permission to release content.');
        }

        $record = match ($type) {
            'article' => Article::query()->whereIn('org_id', $this->currentOrgIds())->findOrFail($id),
            'guide' => CareerGuide::query()->withoutGlobalScopes()->where('org_id', 0)->findOrFail($id),
            'job' => CareerJob::query()->withoutGlobalScopes()->where('org_id', 0)->findOrFail($id),
            'method' => MethodPage::query()->withoutGlobalScopes()->where('org_id', 0)->findOrFail($id),
            'data' => DataPage::query()->withoutGlobalScopes()->where('org_id', 0)->findOrFail($id),
            'personality' => PersonalityProfile::query()->withoutGlobalScopes()->where('org_id', 0)->findOrFail($id),
            'topic' => TopicProfile::query()->withoutGlobalScopes()->where('org_id', 0)->findOrFail($id),
            default => throw new AuthorizationException('Unsupported content type.'),
        };

        if ($this->reviewState($type, $record) !== EditorialReviewAudit::STATE_APPROVED) {
            throw new AuthorizationException('This record must be approved in editorial review before it can be published.');
        }

        match ($type) {
            'article' => ArticleResource::releaseRecord($record, 'release_workspace'),
            'guide' => CareerGuideResource::releaseRecord($record, 'release_workspace'),
            'job' => CareerJobResource::releaseRecord($record, 'release_workspace'),
            'method' => MethodPageResource::releaseRecord($record, 'release_workspace'),
            'data' => DataPageResource::releaseRecord($record, 'release_workspace'),
            'personality' => PersonalityProfileResource::releaseRecord($record, 'release_workspace'),
            'topic' => TopicProfileResource::releaseRecord($record, 'release_workspace'),
        };

        $this->refreshWorkspace();
    }

    public function runCitationQa(int $id): void
    {
        if (! ContentAccess::canRelease()) {
            throw new AuthorizationException('You do not have permission to run citation QA.');
        }

        $record = DataPage::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->findOrFail($id);

        $actorAdminId = (int) (data_get(auth((string) config('admin.guard', 'admin'))->user(), 'id') ?? 0);
        $audit = app(SeoQualityAuditService::class)->runCitationQa($record, $actorAdminId > 0 ? $actorAdminId : null);

        app(AuditLogger::class)->log(
            request(),
            'content_release_citation_qa',
            'data_page',
            (string) $record->getKey(),
            [
                'title' => trim((string) $record->title),
                'locale' => trim((string) $record->locale),
                'audit_id' => (int) $audit->getKey(),
                'audit_status' => (string) $audit->status,
                'audited_at' => optional($audit->audited_at)?->toISOString(),
                'summary' => $audit->summary_json,
            ],
            reason: 'cms_release_workspace',
            result: $audit->status === 'passed' ? 'success' : 'failed',
        );

        $this->refreshWorkspace();

        $notification = Notification::make()
            ->title('Citation QA completed')
            ->body((string) data_get($audit->summary_json, 'summary', 'Citation QA recorded.'));

        if ($audit->status === 'passed') {
            $notification->success()->send();
        } else {
            $notification->warning()->send();
        }
    }

    private function refreshWorkspace(): void
    {
        $currentOrgIds = $this->currentOrgIds();
        $citationQaService = app(SeoQualityAuditService::class);

        $articles = Article::query()
            ->whereIn('org_id', $currentOrgIds)
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->latest('updated_at')
            ->get()
            ->map(fn (Article $record): array => $this->releaseRow(
                type: 'article',
                typeLabel: 'Article',
                id: (int) $record->id,
                title: $record->title,
                status: (string) $record->status,
                reviewState: $this->reviewState('article', $record),
                locale: (string) $record->locale,
                visibility: $record->is_public ? 'Public' : 'Private',
                updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
                editUrl: ArticleResource::getUrl('edit', ['record' => $record]),
                indexUrl: ArticleResource::getUrl(),
            ));

        $guides = CareerGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->latest('updated_at')
            ->get()
            ->map(fn (CareerGuide $record): array => $this->releaseRow(
                type: 'guide',
                typeLabel: 'Career Guide',
                id: (int) $record->id,
                title: $record->title,
                status: (string) $record->status,
                reviewState: $this->reviewState('guide', $record),
                locale: (string) $record->locale,
                visibility: $record->is_public ? 'Public' : 'Private',
                updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
                editUrl: CareerGuideResource::getUrl('edit', ['record' => $record]),
                indexUrl: CareerGuideResource::getUrl(),
            ));

        $jobs = CareerJob::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->latest('updated_at')
            ->get()
            ->map(fn (CareerJob $record): array => $this->releaseRow(
                type: 'job',
                typeLabel: 'Career Job',
                id: (int) $record->id,
                title: $record->title,
                status: (string) $record->status,
                reviewState: $this->reviewState('job', $record),
                locale: (string) $record->locale,
                visibility: $record->is_public ? 'Public' : 'Private',
                updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
                editUrl: CareerJobResource::getUrl('edit', ['record' => $record]),
                indexUrl: CareerJobResource::getUrl(),
            ));

        $methods = MethodPage::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->latest('updated_at')
            ->get()
            ->map(fn (MethodPage $record): array => $this->releaseRow(
                type: 'method',
                typeLabel: 'Method',
                id: (int) $record->id,
                title: $record->title,
                status: (string) $record->status,
                reviewState: $this->reviewState('method', $record),
                locale: (string) $record->locale,
                visibility: $record->is_public ? 'Public' : 'Private',
                updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
                editUrl: MethodPageResource::getUrl('edit', ['record' => $record]),
                indexUrl: MethodPageResource::getUrl(),
            ));

        $dataPages = DataPage::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->latest('updated_at')
            ->get()
            ->map(fn (DataPage $record): array => $this->releaseRow(
                type: 'data',
                typeLabel: 'Data',
                id: (int) $record->id,
                title: $record->title,
                status: (string) $record->status,
                reviewState: $this->reviewState('data', $record),
                locale: (string) $record->locale,
                visibility: $record->is_public ? 'Public' : 'Private',
                updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
                editUrl: DataPageResource::getUrl('edit', ['record' => $record]),
                indexUrl: DataPageResource::getUrl(),
                citationQa: $citationQaService->citationQaState($record),
            ));

        $personalities = PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->latest('updated_at')
            ->get()
            ->map(fn (PersonalityProfile $record): array => $this->releaseRow(
                type: 'personality',
                typeLabel: 'Personality',
                id: (int) $record->id,
                title: $record->title,
                status: (string) $record->status,
                reviewState: $this->reviewState('personality', $record),
                locale: (string) $record->locale,
                visibility: $record->is_public ? 'Public' : 'Private',
                updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
                editUrl: PersonalityProfileResource::getUrl('edit', ['record' => $record]),
                indexUrl: PersonalityProfileResource::getUrl(),
            ));

        $topics = TopicProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->latest('updated_at')
            ->get()
            ->map(fn (TopicProfile $record): array => $this->releaseRow(
                type: 'topic',
                typeLabel: 'Topic',
                id: (int) $record->id,
                title: $record->title,
                status: (string) $record->status,
                reviewState: $this->reviewState('topic', $record),
                locale: (string) $record->locale,
                visibility: $record->is_public ? 'Public' : 'Private',
                updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
                editUrl: TopicProfileResource::getUrl('edit', ['record' => $record]),
                indexUrl: TopicProfileResource::getUrl(),
            ));

        $allItems = collect()
            ->concat($articles)
            ->concat($guides)
            ->concat($jobs)
            ->concat($methods)
            ->concat($dataPages)
            ->concat($personalities)
            ->concat($topics);

        $filteredItems = $this->typeFilter === 'all'
            ? $allItems
            : $allItems->where('type', $this->typeFilter);

        $this->releaseItems = $filteredItems
            ->sortByDesc('updated_at_sort')
            ->values()
            ->all();

        $draftCount = $allItems->where('status', 'draft')->count();
        $approvedCount = $allItems->where('review_state', EditorialReviewAudit::STATE_APPROVED)->count();
        $publishedCount = $allItems->where('status', 'published')->count();
        $dataCitationReadyCount = $dataPages->where('citation_qa.passed', true)->count();
        $dataCitationMissingCount = $dataPages->where('citation_qa.passed', false)->count();

        $this->releaseFields = [
            [
                'label' => 'Draft queue',
                'value' => (string) $draftCount,
                'hint' => 'Draft content waiting inside the lightweight review-and-release surface.',
            ],
            [
                'label' => 'Approved for publish',
                'value' => (string) $approvedCount,
                'hint' => 'Draft records with a current approval decision and no newer edits after review.',
            ],
            [
                'label' => 'Published content',
                'value' => (string) $publishedCount,
                'hint' => 'Published records already cleared by a release-capable operator.',
            ],
            [
                'label' => 'Data citation QA passed',
                'value' => (string) $dataCitationReadyCount,
                'hint' => 'Data pages that already passed the five-question citation QA gate.',
            ],
            [
                'label' => 'Data citation QA backlog',
                'value' => (string) $dataCitationMissingCount,
                'hint' => 'Data pages still missing a passing citation QA result before release.',
            ],
            [
                'label' => 'Current type filter',
                'value' => $this->typeLabel($this->typeFilter),
                'hint' => 'Filter the release queue by content type without leaving the workspace.',
            ],
            [
                'label' => 'Current status filter',
                'value' => $this->typeLabel($this->statusFilter),
                'hint' => 'Draft stays the default review state. Switch to all or published for audit checks.',
            ],
            [
                'label' => 'Release permission',
                'value' => ContentAccess::canRelease() ? 'Enabled' : 'Missing',
                'kind' => 'pill',
                'state' => ContentAccess::canRelease() ? 'success' : 'failed',
                'hint' => 'Release permission is the approval boundary in this lightweight phase.',
            ],
        ];

        $this->surfaceCards = [
            $this->surfaceCard('Articles', $articles, ArticleResource::getUrl()),
            $this->surfaceCard('Career Guides', $guides, CareerGuideResource::getUrl()),
            $this->surfaceCard('Career Jobs', $jobs, CareerJobResource::getUrl()),
            $this->surfaceCard('Methods', $methods, MethodPageResource::getUrl()),
            $this->surfaceCard('Data', $dataPages, DataPageResource::getUrl()),
            $this->surfaceCard('Personality', $personalities, PersonalityProfileResource::getUrl()),
            $this->surfaceCard('Topics', $topics, TopicProfileResource::getUrl()),
        ];
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
    private function releaseRow(
        string $type,
        string $typeLabel,
        int $id,
        string $title,
        string $status,
        string $reviewState,
        string $locale,
        string $visibility,
        string $updatedAt,
        string $editUrl,
        string $indexUrl,
        ?array $citationQa = null,
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'type_label' => $typeLabel,
            'title' => $title,
            'status' => $status,
            'review_state' => $reviewState,
            'locale' => $locale,
            'visibility' => $visibility,
            'updated_at' => $updatedAt,
            'updated_at_sort' => strtotime($updatedAt) ?: 0,
            'edit_url' => $editUrl,
            'index_url' => $indexUrl,
            'citation_qa' => $citationQa,
            'citation_qa_summary' => (string) ($citationQa['summary'] ?? ''),
            'citation_qa_audited_at' => (string) ($citationQa['audited_at'] ?? ''),
            'releaseable' => $status === 'draft'
                && $reviewState === EditorialReviewAudit::STATE_APPROVED
                && ($type !== 'data' || (bool) ($citationQa['passed'] ?? false)),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function surfaceCard(string $title, Collection $records, string $indexUrl): array
    {
        return [
            'title' => $title,
            'meta' => $records->count().' matching',
            'description' => 'Open the corresponding resource list for full editing, audit, and release follow-up.',
            'index_url' => $indexUrl,
        ];
    }

    private function typeLabel(string $value): string
    {
        return match ($value) {
            'all' => 'All',
            'article' => 'Article',
            'guide' => 'Career Guide',
            'job' => 'Career Job',
            'method' => 'Method',
            'data' => 'Data',
            'personality' => 'Personality',
            'topic' => 'Topic',
            'draft' => 'Draft',
            'published' => 'Published',
            default => ucfirst($value),
        };
    }

    private function reviewState(string $type, object $record): string
    {
        if ((string) data_get($record, 'status') === 'published') {
            return EditorialReviewAudit::STATE_APPROVED;
        }

        $checklistReady = EditorialReviewChecklist::missing($type, $record) === [];
        $decision = EditorialReviewAudit::latestState($type, $record);

        if (is_array($decision) && ($decision['state'] ?? null) !== null) {
            $state = (string) $decision['state'];

            if ($state === EditorialReviewAudit::STATE_READY && ! $checklistReady) {
                return EditorialReviewAudit::STATE_NEEDS_ATTENTION;
            }

            return $state;
        }

        return $checklistReady
            ? EditorialReviewAudit::STATE_READY
            : EditorialReviewAudit::STATE_NEEDS_ATTENTION;
    }
}
