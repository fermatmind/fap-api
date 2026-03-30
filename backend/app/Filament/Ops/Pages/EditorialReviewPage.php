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
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\DataPage;
use App\Models\MethodPage;
use App\Models\PersonalityProfile;
use App\Models\TopicProfile;
use App\Support\OrgContext;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;

class EditorialReviewPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Content Release';

    protected static ?string $navigationLabel = 'Editorial Review';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'editorial-review';

    protected static string $view = 'filament.ops.pages.editorial-review';

    /** @var list<array<string, mixed>> */
    public array $reviewFields = [];

    /** @var list<array<string, mixed>> */
    public array $reviewItems = [];

    /** @var array<string, string> */
    public array $ownerAssignments = [];

    /** @var array<string, string> */
    public array $reviewerAssignments = [];

    /** @var array<int, string> */
    public array $ownerOptions = [];

    /** @var array<int, string> */
    public array $reviewerOptions = [];

    public string $typeFilter = 'all';

    public string $reviewStateFilter = 'all';

    public function mount(): void
    {
        $this->loadAssignmentOptions();
        $this->refreshReviewSurface();
    }

    public function updatedTypeFilter(): void
    {
        $this->refreshReviewSurface();
    }

    public function updatedReviewStateFilter(): void
    {
        $this->refreshReviewSurface();
    }

    public function assignOwnerItem(string $type, int $id): void
    {
        if (! ContentAccess::canAssignOwner()) {
            throw new AuthorizationException('You do not have permission to assign an owner.');
        }

        $record = $this->resolveRecord($type, $id);
        $selection = trim((string) ($this->ownerAssignments[$this->workflowKey($type, $id)] ?? ''));
        $ownerAdminId = (int) $selection;

        if ($ownerAdminId <= 0) {
            throw new AuthorizationException('Select an owner before saving the assignment.');
        }

        EditorialReviewAudit::assignOwner($ownerAdminId, $type, $record);

        Notification::make()
            ->title('Owner assigned')
            ->success()
            ->send();

        $this->refreshReviewSurface();
    }

    public function assignReviewerItem(string $type, int $id): void
    {
        if (! ContentAccess::canAssignReviewer()) {
            throw new AuthorizationException('You do not have permission to assign a reviewer.');
        }

        $record = $this->resolveRecord($type, $id);
        $selection = trim((string) ($this->reviewerAssignments[$this->workflowKey($type, $id)] ?? ''));
        $reviewerAdminId = (int) $selection;

        if ($reviewerAdminId <= 0) {
            throw new AuthorizationException('Select a reviewer before saving the assignment.');
        }

        EditorialReviewAudit::assignReviewer($reviewerAdminId, $type, $record);

        Notification::make()
            ->title('Reviewer assigned')
            ->success()
            ->send();

        $this->refreshReviewSurface();
    }

    public function submitItem(string $type, int $id): void
    {
        $record = $this->resolveRecord($type, $id);
        $missing = EditorialReviewChecklist::missing($type, $record);
        $snapshot = EditorialReviewAudit::latestState($type, $record);

        if ($missing !== []) {
            throw new AuthorizationException('Fix the checklist gaps before submitting for review.');
        }

        if ((int) ($snapshot['owner_admin_user_id'] ?? 0) <= 0 || (int) ($snapshot['reviewer_admin_user_id'] ?? 0) <= 0) {
            throw new AuthorizationException('Assign both an owner and a reviewer before submitting for review.');
        }

        if (! $this->canSubmitForReview($snapshot)) {
            throw new AuthorizationException('You do not have permission to submit this record for review.');
        }

        EditorialReviewAudit::submit($type, $record);

        Notification::make()
            ->title('Sent to review')
            ->success()
            ->send();

        $this->refreshReviewSurface();
    }

    public function approveItem(string $type, int $id): void
    {
        $record = $this->resolveRecord($type, $id);
        $missing = EditorialReviewChecklist::missing($type, $record);
        $snapshot = EditorialReviewAudit::latestState($type, $record);

        if ($missing !== []) {
            throw new AuthorizationException('You cannot approve a record with review checklist gaps.');
        }

        if (! $this->canDecideReview($snapshot)) {
            throw new AuthorizationException('You are not the assigned reviewer for this record.');
        }

        EditorialReviewAudit::mark(EditorialReviewAudit::STATE_APPROVED, $type, $record);

        Notification::make()
            ->title('Editorial review approved')
            ->success()
            ->send();

        $this->refreshReviewSurface();
    }

    public function requestChangesItem(string $type, int $id): void
    {
        $record = $this->resolveRecord($type, $id);
        $snapshot = EditorialReviewAudit::latestState($type, $record);

        if (! $this->canDecideReview($snapshot)) {
            throw new AuthorizationException('You are not the assigned reviewer for this record.');
        }

        EditorialReviewAudit::mark(EditorialReviewAudit::STATE_CHANGES_REQUESTED, $type, $record);

        Notification::make()
            ->title('Changes requested')
            ->warning()
            ->send();

        $this->refreshReviewSurface();
    }

    public function rejectItem(string $type, int $id): void
    {
        $record = $this->resolveRecord($type, $id);
        $snapshot = EditorialReviewAudit::latestState($type, $record);

        if (! $this->canDecideReview($snapshot)) {
            throw new AuthorizationException('You are not the assigned reviewer for this record.');
        }

        EditorialReviewAudit::mark(EditorialReviewAudit::STATE_REJECTED, $type, $record);

        Notification::make()
            ->title('Editorial review rejected')
            ->danger()
            ->send();

        $this->refreshReviewSurface();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content_release');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.editorial_review');
    }

    public static function canAccess(): bool
    {
        return ContentAccess::canOpenWorkflow();
    }

    private function refreshReviewSurface(): void
    {
        $this->loadAssignmentOptions();

        $currentOrgIds = $this->currentOrgIds();

        $articles = Article::query()
            ->with('seoMeta')
            ->whereIn('org_id', $currentOrgIds)
            ->where('status', 'draft')
            ->latest('updated_at')
            ->get()
            ->map(fn (Article $record): array => $this->reviewRowForArticle($record));

        $guides = CareerGuide::query()
            ->withoutGlobalScopes()
            ->with('seoMeta')
            ->where('org_id', 0)
            ->where('status', CareerGuide::STATUS_DRAFT)
            ->latest('updated_at')
            ->get()
            ->map(fn (CareerGuide $record): array => $this->reviewRowForGuide($record));

        $jobs = CareerJob::query()
            ->withoutGlobalScopes()
            ->with('seoMeta')
            ->where('org_id', 0)
            ->where('status', CareerJob::STATUS_DRAFT)
            ->latest('updated_at')
            ->get()
            ->map(fn (CareerJob $record): array => $this->reviewRowForJob($record));

        $methods = MethodPage::query()
            ->withoutGlobalScopes()
            ->with(['seoMeta', 'governance'])
            ->where('org_id', 0)
            ->where('status', MethodPage::STATUS_DRAFT)
            ->latest('updated_at')
            ->get()
            ->map(fn (MethodPage $record): array => $this->reviewRowForMethod($record));

        $dataPages = DataPage::query()
            ->withoutGlobalScopes()
            ->with(['seoMeta', 'governance'])
            ->where('org_id', 0)
            ->where('status', DataPage::STATUS_DRAFT)
            ->latest('updated_at')
            ->get()
            ->map(fn (DataPage $record): array => $this->reviewRowForData($record));

        $personalities = PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->with(['seoMeta', 'sections', 'governance'])
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->where('status', 'draft')
            ->latest('updated_at')
            ->get()
            ->map(fn (PersonalityProfile $record): array => $this->reviewRowForPersonality($record));

        $topics = TopicProfile::query()
            ->withoutGlobalScopes()
            ->with(['seoMeta', 'sections', 'entries', 'governance'])
            ->where('org_id', 0)
            ->where('status', TopicProfile::STATUS_DRAFT)
            ->latest('updated_at')
            ->get()
            ->map(fn (TopicProfile $record): array => $this->reviewRowForTopic($record));

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

        if ($this->reviewStateFilter !== 'all') {
            $filteredItems = $filteredItems->where('review_state', $this->reviewStateFilter);
        }

        $readyCount = $allItems->where('review_state', EditorialReviewAudit::STATE_READY)->count();
        $inReviewCount = $allItems->where('review_state', EditorialReviewAudit::STATE_IN_REVIEW)->count();
        $approvedCount = $allItems->where('review_state', EditorialReviewAudit::STATE_APPROVED)->count();
        $attentionCount = $allItems->where('review_state', EditorialReviewAudit::STATE_NEEDS_ATTENTION)->count();

        $this->hydrateAssignmentSelections($allItems->all());

        $this->reviewItems = $filteredItems
            ->sortByDesc('updated_at_sort')
            ->values()
            ->all();

        $this->reviewFields = [
            [
                'label' => 'Draft review queue',
                'value' => (string) $allItems->count(),
                'hint' => 'Draft editorial records currently visible to the lightweight review surface.',
            ],
            [
                'label' => 'Ready for release review',
                'value' => (string) $readyCount,
                'hint' => 'Checklist-clean drafts that have not yet been formally submitted into the reviewer queue.',
            ],
            [
                'label' => 'Currently in review',
                'value' => (string) $inReviewCount,
                'hint' => 'Draft records actively assigned and submitted to a reviewer.',
            ],
            [
                'label' => 'Approved for release',
                'value' => (string) $approvedCount,
                'hint' => 'Draft records with a current approval decision and no newer edits after that approval.',
            ],
            [
                'label' => 'Needs attention',
                'value' => (string) $attentionCount,
                'hint' => 'Draft records still missing content or SEO inputs before release review is likely to pass cleanly.',
            ],
            [
                'label' => 'Current type filter',
                'value' => $this->typeLabel($this->typeFilter),
                'hint' => 'Filter the review queue by editorial surface.',
            ],
            [
                'label' => 'Current review filter',
                'value' => $this->typeLabel($this->reviewStateFilter),
                'hint' => 'Keep focus on records that are ready now or isolate drafts that still need editorial cleanup.',
            ],
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
    private function reviewRowForArticle(Article $record): array
    {
        $missing = EditorialReviewChecklist::missing('article', $record);
        $snapshot = EditorialReviewAudit::latestState('article', $record);
        $reviewState = $this->resolveReviewState($snapshot, $missing === []);

        return $this->reviewRow(
            type: 'article',
            id: (int) $record->id,
            typeLabel: 'Article',
            title: (string) $record->title,
            locale: (string) $record->locale,
            reviewState: $reviewState,
            ownerAdminId: (int) ($snapshot['owner_admin_user_id'] ?? 0),
            ownerLabel: (string) ($snapshot['owner_label'] ?? ''),
            reviewerAdminId: (int) ($snapshot['reviewer_admin_user_id'] ?? 0),
            reviewerLabel: (string) ($snapshot['reviewer_label'] ?? ''),
            checklistLabel: $missing === [] ? 'Content + SEO ready' : 'Missing: '.implode(', ', $missing),
            updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
            editUrl: ArticleResource::getUrl('edit', ['record' => $record]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewRowForGuide(CareerGuide $record): array
    {
        $missing = EditorialReviewChecklist::missing('guide', $record);
        $snapshot = EditorialReviewAudit::latestState('guide', $record);
        $reviewState = $this->resolveReviewState($snapshot, $missing === []);

        return $this->reviewRow(
            type: 'guide',
            id: (int) $record->id,
            typeLabel: 'Career Guide',
            title: (string) $record->title,
            locale: (string) $record->locale,
            reviewState: $reviewState,
            ownerAdminId: (int) ($snapshot['owner_admin_user_id'] ?? 0),
            ownerLabel: (string) ($snapshot['owner_label'] ?? ''),
            reviewerAdminId: (int) ($snapshot['reviewer_admin_user_id'] ?? 0),
            reviewerLabel: (string) ($snapshot['reviewer_label'] ?? ''),
            checklistLabel: $missing === [] ? 'Content + SEO ready' : 'Missing: '.implode(', ', $missing),
            updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
            editUrl: CareerGuideResource::getUrl('edit', ['record' => $record]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewRowForJob(CareerJob $record): array
    {
        $missing = EditorialReviewChecklist::missing('job', $record);
        $snapshot = EditorialReviewAudit::latestState('job', $record);
        $reviewState = $this->resolveReviewState($snapshot, $missing === []);

        return $this->reviewRow(
            type: 'job',
            id: (int) $record->id,
            typeLabel: 'Career Job',
            title: (string) $record->title,
            locale: (string) $record->locale,
            reviewState: $reviewState,
            ownerAdminId: (int) ($snapshot['owner_admin_user_id'] ?? 0),
            ownerLabel: (string) ($snapshot['owner_label'] ?? ''),
            reviewerAdminId: (int) ($snapshot['reviewer_admin_user_id'] ?? 0),
            reviewerLabel: (string) ($snapshot['reviewer_label'] ?? ''),
            checklistLabel: $missing === [] ? 'Content + SEO ready' : 'Missing: '.implode(', ', $missing),
            updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
            editUrl: CareerJobResource::getUrl('edit', ['record' => $record]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewRowForMethod(MethodPage $record): array
    {
        $missing = EditorialReviewChecklist::missing('method', $record);
        $snapshot = EditorialReviewAudit::latestState('method', $record);
        $reviewState = $this->resolveReviewState($snapshot, $missing === []);

        return $this->reviewRow(
            type: 'method',
            id: (int) $record->id,
            typeLabel: 'Method',
            title: (string) $record->title,
            locale: (string) $record->locale,
            reviewState: $reviewState,
            ownerAdminId: (int) ($snapshot['owner_admin_user_id'] ?? 0),
            ownerLabel: (string) ($snapshot['owner_label'] ?? ''),
            reviewerAdminId: (int) ($snapshot['reviewer_admin_user_id'] ?? 0),
            reviewerLabel: (string) ($snapshot['reviewer_label'] ?? ''),
            checklistLabel: $missing === [] ? 'Content + SEO ready' : 'Missing: '.implode(', ', $missing),
            updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
            editUrl: MethodPageResource::getUrl('edit', ['record' => $record]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewRowForData(DataPage $record): array
    {
        $missing = EditorialReviewChecklist::missing('data', $record);
        $snapshot = EditorialReviewAudit::latestState('data', $record);
        $reviewState = $this->resolveReviewState($snapshot, $missing === []);

        return $this->reviewRow(
            type: 'data',
            id: (int) $record->id,
            typeLabel: 'Data',
            title: (string) $record->title,
            locale: (string) $record->locale,
            reviewState: $reviewState,
            ownerAdminId: (int) ($snapshot['owner_admin_user_id'] ?? 0),
            ownerLabel: (string) ($snapshot['owner_label'] ?? ''),
            reviewerAdminId: (int) ($snapshot['reviewer_admin_user_id'] ?? 0),
            reviewerLabel: (string) ($snapshot['reviewer_label'] ?? ''),
            checklistLabel: $missing === [] ? 'Content + SEO ready' : 'Missing: '.implode(', ', $missing),
            updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
            editUrl: DataPageResource::getUrl('edit', ['record' => $record]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewRowForPersonality(PersonalityProfile $record): array
    {
        $missing = EditorialReviewChecklist::missing('personality', $record);
        $snapshot = EditorialReviewAudit::latestState('personality', $record);
        $reviewState = $this->resolveReviewState($snapshot, $missing === []);

        return $this->reviewRow(
            type: 'personality',
            id: (int) $record->id,
            typeLabel: 'Personality',
            title: (string) $record->title,
            locale: (string) $record->locale,
            reviewState: $reviewState,
            ownerAdminId: (int) ($snapshot['owner_admin_user_id'] ?? 0),
            ownerLabel: (string) ($snapshot['owner_label'] ?? ''),
            reviewerAdminId: (int) ($snapshot['reviewer_admin_user_id'] ?? 0),
            reviewerLabel: (string) ($snapshot['reviewer_label'] ?? ''),
            checklistLabel: $missing === [] ? 'Content + SEO ready' : 'Missing: '.implode(', ', $missing),
            updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
            editUrl: PersonalityProfileResource::getUrl('edit', ['record' => $record]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewRowForTopic(TopicProfile $record): array
    {
        $missing = EditorialReviewChecklist::missing('topic', $record);
        $snapshot = EditorialReviewAudit::latestState('topic', $record);
        $reviewState = $this->resolveReviewState($snapshot, $missing === []);

        return $this->reviewRow(
            type: 'topic',
            id: (int) $record->id,
            typeLabel: 'Topic',
            title: (string) $record->title,
            locale: (string) $record->locale,
            reviewState: $reviewState,
            ownerAdminId: (int) ($snapshot['owner_admin_user_id'] ?? 0),
            ownerLabel: (string) ($snapshot['owner_label'] ?? ''),
            reviewerAdminId: (int) ($snapshot['reviewer_admin_user_id'] ?? 0),
            reviewerLabel: (string) ($snapshot['reviewer_label'] ?? ''),
            checklistLabel: $missing === [] ? 'Content + SEO ready' : 'Missing: '.implode(', ', $missing),
            updatedAt: optional($record->updated_at)?->toDateTimeString() ?? 'Unknown',
            editUrl: TopicProfileResource::getUrl('edit', ['record' => $record]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewRow(
        string $type,
        int $id,
        string $typeLabel,
        string $title,
        string $locale,
        string $reviewState,
        int $ownerAdminId,
        string $ownerLabel,
        int $reviewerAdminId,
        string $reviewerLabel,
        string $checklistLabel,
        string $updatedAt,
        string $editUrl,
    ): array {
        $workflowKey = $this->workflowKey($type, $id);

        return [
            'type' => $type,
            'id' => $id,
            'workflow_key' => $workflowKey,
            'type_label' => $typeLabel,
            'title' => $title !== '' ? $title : 'Untitled',
            'locale' => $locale !== '' ? $locale : 'Unknown',
            'review_state' => $reviewState,
            'owner_admin_user_id' => $ownerAdminId > 0 ? $ownerAdminId : null,
            'owner_label' => $ownerLabel !== '' ? $ownerLabel : 'Unassigned',
            'reviewer_admin_user_id' => $reviewerAdminId > 0 ? $reviewerAdminId : null,
            'reviewer_label' => $reviewerLabel !== '' ? $reviewerLabel : 'Unassigned',
            'checklist_label' => $checklistLabel,
            'updated_at' => $updatedAt,
            'updated_at_sort' => strtotime($updatedAt) ?: 0,
            'edit_url' => $editUrl,
            'can_assign_owner' => ContentAccess::canAssignOwner(),
            'can_assign_reviewer' => ContentAccess::canAssignReviewer(),
            'can_submit' => ($reviewState === EditorialReviewAudit::STATE_READY || $reviewState === EditorialReviewAudit::STATE_CHANGES_REQUESTED)
                && $ownerAdminId > 0
                && $reviewerAdminId > 0
                && $this->canSubmitForReview([
                    'owner_admin_user_id' => $ownerAdminId,
                    'reviewer_admin_user_id' => $reviewerAdminId,
                ]),
            'can_decide' => $reviewState === EditorialReviewAudit::STATE_IN_REVIEW
                && $this->canDecideReview([
                    'reviewer_admin_user_id' => $reviewerAdminId,
                ]),
        ];
    }

    private function typeLabel(string $value): string
    {
        return match ($value) {
            'article' => 'Article',
            'guide' => 'Career Guide',
            'job' => 'Career Job',
            'method' => 'Method',
            'data' => 'Data',
            'personality' => 'Personality',
            'topic' => 'Topic',
            EditorialReviewAudit::STATE_READY => EditorialReviewAudit::label(EditorialReviewAudit::STATE_READY),
            EditorialReviewAudit::STATE_IN_REVIEW => EditorialReviewAudit::label(EditorialReviewAudit::STATE_IN_REVIEW),
            EditorialReviewAudit::STATE_APPROVED => EditorialReviewAudit::label(EditorialReviewAudit::STATE_APPROVED),
            EditorialReviewAudit::STATE_CHANGES_REQUESTED => EditorialReviewAudit::label(EditorialReviewAudit::STATE_CHANGES_REQUESTED),
            EditorialReviewAudit::STATE_REJECTED => EditorialReviewAudit::label(EditorialReviewAudit::STATE_REJECTED),
            EditorialReviewAudit::STATE_NEEDS_ATTENTION => EditorialReviewAudit::label(EditorialReviewAudit::STATE_NEEDS_ATTENTION),
            default => 'All',
        };
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    private function resolveReviewState(?array $snapshot, bool $checklistReady): string
    {
        if (! $checklistReady) {
            return EditorialReviewAudit::STATE_NEEDS_ATTENTION;
        }

        return (string) ($snapshot['state'] ?? EditorialReviewAudit::STATE_READY);
    }

    private function resolveRecord(string $type, int $id): object
    {
        if (! ContentAccess::canOpenWorkflow()) {
            throw new AuthorizationException('You do not have permission to review content.');
        }

        return match ($type) {
            'article' => Article::query()->whereIn('org_id', $this->currentOrgIds())->findOrFail($id),
            'guide' => CareerGuide::query()->withoutGlobalScopes()->where('org_id', 0)->findOrFail($id),
            'job' => CareerJob::query()->withoutGlobalScopes()->where('org_id', 0)->findOrFail($id),
            'method' => MethodPage::query()->withoutGlobalScopes()->where('org_id', 0)->findOrFail($id),
            'data' => DataPage::query()->withoutGlobalScopes()->where('org_id', 0)->findOrFail($id),
            'personality' => PersonalityProfile::query()->withoutGlobalScopes()->where('org_id', 0)->findOrFail($id),
            'topic' => TopicProfile::query()->withoutGlobalScopes()->where('org_id', 0)->findOrFail($id),
            default => throw new AuthorizationException('Unsupported review type.'),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function hydrateAssignmentSelections(array $items): void
    {
        foreach ($items as $item) {
            $this->ownerAssignments[$item['workflow_key']] = (string) ($item['owner_admin_user_id'] ?? '');
            $this->reviewerAssignments[$item['workflow_key']] = (string) ($item['reviewer_admin_user_id'] ?? '');
        }
    }

    private function loadAssignmentOptions(): void
    {
        $admins = AdminUser::query()
            ->where('is_active', 1)
            ->orderBy('name')
            ->orderBy('email')
            ->get();

        $this->ownerOptions = $admins
            ->filter(fn (AdminUser $admin): bool => $admin->hasPermission(\App\Support\Rbac\PermissionNames::ADMIN_CONTENT_WRITE)
                || $admin->hasPermission(\App\Support\Rbac\PermissionNames::ADMIN_CONTENT_PUBLISH)
                || $admin->hasPermission(\App\Support\Rbac\PermissionNames::ADMIN_OWNER))
            ->mapWithKeys(fn (AdminUser $admin): array => [(int) $admin->id => trim($admin->name !== '' ? $admin->name : $admin->email)])
            ->all();

        $this->reviewerOptions = $admins
            ->filter(fn (AdminUser $admin): bool => $admin->hasPermission(\App\Support\Rbac\PermissionNames::ADMIN_APPROVAL_REVIEW)
                || $admin->hasPermission(\App\Support\Rbac\PermissionNames::ADMIN_CONTENT_RELEASE)
                || $admin->hasPermission(\App\Support\Rbac\PermissionNames::ADMIN_CONTENT_PUBLISH)
                || $admin->hasPermission(\App\Support\Rbac\PermissionNames::ADMIN_OWNER))
            ->mapWithKeys(fn (AdminUser $admin): array => [(int) $admin->id => trim($admin->name !== '' ? $admin->name : $admin->email)])
            ->all();
    }

    private function workflowKey(string $type, int $id): string
    {
        return $type.'_'.$id;
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    private function canSubmitForReview(?array $snapshot): bool
    {
        if (ContentAccess::isOwner()) {
            return true;
        }

        if (! ContentAccess::canOpenWorkflow()) {
            return false;
        }

        $actorId = $this->actorAdminId();

        return $actorId > 0 && $actorId === (int) ($snapshot['owner_admin_user_id'] ?? 0);
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    private function canDecideReview(?array $snapshot): bool
    {
        if (ContentAccess::isOwner()) {
            return true;
        }

        if (! ContentAccess::canReview()) {
            return false;
        }

        $actorId = $this->actorAdminId();

        return $actorId > 0 && $actorId === (int) ($snapshot['reviewer_admin_user_id'] ?? 0);
    }

    private function actorAdminId(): int
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user) && is_numeric(data_get($user, 'id'))
            ? (int) data_get($user, 'id')
            : 0;
    }
}
