<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerJobResource;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\EditorialReviewAudit;
use App\Filament\Ops\Support\EditorialReviewChecklist;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerJob;
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

        $allItems = collect()
            ->concat($articles)
            ->concat($guides)
            ->concat($jobs);

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
                'label' => __('ops.custom_pages.editorial_review.fields.draft_queue'),
                'value' => (string) $allItems->count(),
                'hint' => __('ops.custom_pages.editorial_review.fields.draft_queue_hint'),
            ],
            [
                'label' => __('ops.custom_pages.editorial_review.fields.ready'),
                'value' => (string) $readyCount,
                'hint' => __('ops.custom_pages.editorial_review.fields.ready_hint'),
            ],
            [
                'label' => __('ops.custom_pages.editorial_review.fields.in_review'),
                'value' => (string) $inReviewCount,
                'hint' => __('ops.custom_pages.editorial_review.fields.in_review_hint'),
            ],
            [
                'label' => __('ops.custom_pages.editorial_review.fields.approved'),
                'value' => (string) $approvedCount,
                'hint' => __('ops.custom_pages.editorial_review.fields.approved_hint'),
            ],
            [
                'label' => __('ops.custom_pages.editorial_review.fields.attention'),
                'value' => (string) $attentionCount,
                'hint' => __('ops.custom_pages.editorial_review.fields.attention_hint'),
            ],
            [
                'label' => __('ops.custom_pages.editorial_review.fields.type_filter'),
                'value' => $this->typeLabel($this->typeFilter),
                'hint' => __('ops.custom_pages.editorial_review.fields.type_filter_hint'),
            ],
            [
                'label' => __('ops.custom_pages.editorial_review.fields.review_filter'),
                'value' => $this->typeLabel($this->reviewStateFilter),
                'hint' => __('ops.custom_pages.editorial_review.fields.review_filter_hint'),
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
            typeLabel: __('ops.custom_pages.common.filters.article'),
            title: (string) $record->title,
            locale: (string) $record->locale,
            reviewState: $reviewState,
            ownerAdminId: (int) ($snapshot['owner_admin_user_id'] ?? 0),
            ownerLabel: (string) ($snapshot['owner_label'] ?? ''),
            reviewerAdminId: (int) ($snapshot['reviewer_admin_user_id'] ?? 0),
            reviewerLabel: (string) ($snapshot['reviewer_label'] ?? ''),
            checklistLabel: $missing === [] ? __('ops.custom_pages.editorial_review.checklist_ready') : __('ops.custom_pages.editorial_review.checklist_missing', ['items' => implode(', ', $missing)]),
            updatedAt: optional($record->updated_at)?->toDateTimeString() ?? __('ops.custom_pages.common.values.unknown'),
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
            typeLabel: __('ops.custom_pages.common.filters.career_guide'),
            title: (string) $record->title,
            locale: (string) $record->locale,
            reviewState: $reviewState,
            ownerAdminId: (int) ($snapshot['owner_admin_user_id'] ?? 0),
            ownerLabel: (string) ($snapshot['owner_label'] ?? ''),
            reviewerAdminId: (int) ($snapshot['reviewer_admin_user_id'] ?? 0),
            reviewerLabel: (string) ($snapshot['reviewer_label'] ?? ''),
            checklistLabel: $missing === [] ? __('ops.custom_pages.editorial_review.checklist_ready') : __('ops.custom_pages.editorial_review.checklist_missing', ['items' => implode(', ', $missing)]),
            updatedAt: optional($record->updated_at)?->toDateTimeString() ?? __('ops.custom_pages.common.values.unknown'),
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
            typeLabel: __('ops.custom_pages.common.filters.career_job'),
            title: (string) $record->title,
            locale: (string) $record->locale,
            reviewState: $reviewState,
            ownerAdminId: (int) ($snapshot['owner_admin_user_id'] ?? 0),
            ownerLabel: (string) ($snapshot['owner_label'] ?? ''),
            reviewerAdminId: (int) ($snapshot['reviewer_admin_user_id'] ?? 0),
            reviewerLabel: (string) ($snapshot['reviewer_label'] ?? ''),
            checklistLabel: $missing === [] ? __('ops.custom_pages.editorial_review.checklist_ready') : __('ops.custom_pages.editorial_review.checklist_missing', ['items' => implode(', ', $missing)]),
            updatedAt: optional($record->updated_at)?->toDateTimeString() ?? __('ops.custom_pages.common.values.unknown'),
            editUrl: CareerJobResource::getUrl('edit', ['record' => $record]),
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
            'title' => $title !== '' ? $title : __('ops.custom_pages.common.values.untitled'),
            'locale' => $locale !== '' ? $locale : __('ops.custom_pages.common.values.unknown'),
            'review_state' => $reviewState,
            'owner_admin_user_id' => $ownerAdminId > 0 ? $ownerAdminId : null,
            'owner_label' => $ownerLabel !== '' ? $ownerLabel : __('ops.custom_pages.common.values.unassigned'),
            'reviewer_admin_user_id' => $reviewerAdminId > 0 ? $reviewerAdminId : null,
            'reviewer_label' => $reviewerLabel !== '' ? $reviewerLabel : __('ops.custom_pages.common.values.unassigned'),
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
            'article' => __('ops.custom_pages.common.filters.article'),
            'guide' => __('ops.custom_pages.common.filters.career_guide'),
            'job' => __('ops.custom_pages.common.filters.career_job'),
            EditorialReviewAudit::STATE_READY => EditorialReviewAudit::label(EditorialReviewAudit::STATE_READY),
            EditorialReviewAudit::STATE_IN_REVIEW => EditorialReviewAudit::label(EditorialReviewAudit::STATE_IN_REVIEW),
            EditorialReviewAudit::STATE_APPROVED => EditorialReviewAudit::label(EditorialReviewAudit::STATE_APPROVED),
            EditorialReviewAudit::STATE_CHANGES_REQUESTED => EditorialReviewAudit::label(EditorialReviewAudit::STATE_CHANGES_REQUESTED),
            EditorialReviewAudit::STATE_REJECTED => EditorialReviewAudit::label(EditorialReviewAudit::STATE_REJECTED),
            EditorialReviewAudit::STATE_NEEDS_ATTENTION => EditorialReviewAudit::label(EditorialReviewAudit::STATE_NEEDS_ATTENTION),
            default => __('ops.custom_pages.common.filters.all'),
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
