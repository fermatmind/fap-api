<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Models\AdminUser;
use App\Models\EditorialReview;
use App\Models\PersonalityProfile;
use App\Models\TopicProfile;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\ContentGovernanceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class EditorialReviewAudit
{
    public const STATE_READY = 'ready';

    public const STATE_IN_REVIEW = 'in_review';

    public const STATE_APPROVED = 'approved';

    public const STATE_CHANGES_REQUESTED = 'changes_requested';

    public const STATE_REJECTED = 'rejected';

    public const STATE_NEEDS_ATTENTION = 'needs_attention';

    public static function assignOwner(int $ownerAdminId, string $type, object $record): EditorialReview
    {
        if (! ContentAccess::canAssignOwner()) {
            throw new AuthorizationException('You do not have permission to assign an owner.');
        }

        $owner = self::resolveOwnerCandidate($ownerAdminId);
        $workflow = self::workflowFor($type, $record);

        if ((int) $workflow->owner_admin_user_id === $ownerAdminId) {
            return $workflow;
        }

        $workflow->owner_admin_user_id = $ownerAdminId;
        self::resetWorkflowState($workflow);
        $workflow->last_transition_at = now();
        $workflow->save();

        self::log(
            action: 'editorial_review_owner_assigned',
            type: $type,
            record: $record,
            meta: [
                'owner_admin_user_id' => $ownerAdminId,
                'owner_label' => trim($owner->name !== '' ? $owner->name : $owner->email),
            ],
        );

        return $workflow;
    }

    public static function assignReviewer(int $reviewerAdminId, string $type, object $record): EditorialReview
    {
        if (! ContentAccess::canAssignReviewer()) {
            throw new AuthorizationException('You do not have permission to assign a reviewer.');
        }

        $reviewer = self::resolveReviewerCandidate($reviewerAdminId);
        $workflow = self::workflowFor($type, $record);

        if ((int) $workflow->reviewer_admin_user_id === $reviewerAdminId) {
            return $workflow;
        }

        $workflow->reviewer_admin_user_id = $reviewerAdminId;
        self::resetWorkflowState($workflow);
        $workflow->last_transition_at = now();
        $workflow->save();

        self::syncGovernanceSnapshot($record, [
            'reviewer_admin_user_id' => $reviewerAdminId,
            'publish_gate_state' => self::STATE_READY,
        ]);

        self::log(
            action: 'editorial_review_reviewer_assigned',
            type: $type,
            record: $record,
            meta: [
                'reviewer_admin_user_id' => $reviewerAdminId,
                'reviewer_label' => trim($reviewer->name !== '' ? $reviewer->name : $reviewer->email),
            ],
        );

        return $workflow;
    }

    public static function submit(string $type, object $record): EditorialReview
    {
        $workflow = self::workflowFor($type, $record);
        self::assertCanSubmit($workflow, $type, $record);

        $workflow->workflow_state = self::STATE_IN_REVIEW;
        $workflow->submitted_by_admin_user_id = self::actorAdminId();
        $workflow->submitted_at = now();
        $workflow->reviewed_by_admin_user_id = null;
        $workflow->reviewed_at = null;
        $workflow->last_transition_at = now();
        $workflow->save();

        self::syncGovernanceSnapshot($record, [
            'publish_gate_state' => self::STATE_IN_REVIEW,
        ]);

        self::log(
            action: 'editorial_review_submitted',
            type: $type,
            record: $record,
            meta: [
                'workflow_state' => self::STATE_IN_REVIEW,
            ],
        );

        return $workflow;
    }

    public static function mark(string $decision, string $type, object $record): void
    {
        $workflow = self::workflowFor($type, $record);
        self::assertCanMark($workflow, $decision, $type, $record);

        $action = match ($decision) {
            self::STATE_APPROVED => 'editorial_review_approved',
            self::STATE_CHANGES_REQUESTED => 'editorial_review_changes_requested',
            self::STATE_REJECTED => 'editorial_review_rejected',
            default => throw new \InvalidArgumentException('Unsupported review decision.'),
        };

        $workflow->workflow_state = $decision;
        $workflow->reviewed_by_admin_user_id = self::actorAdminId();
        $workflow->reviewed_at = now();
        $workflow->last_transition_at = now();
        $workflow->save();

        self::syncGovernanceSnapshot($record, [
            'reviewer_admin_user_id' => (int) ($workflow->reviewer_admin_user_id ?: null),
            'publish_gate_state' => $decision,
        ]);

        self::log(
            action: $action,
            type: $type,
            record: $record,
            meta: [
                'decision' => $decision,
                'workflow_state' => $decision,
            ],
        );
    }

    /**
     * @return array{state:string,label:string,reviewed_at:string,owner_admin_user_id:int|null,owner_label:string,reviewer_admin_user_id:int|null,reviewer_label:string}|null
     */
    public static function latestState(string $type, object $record): ?array
    {
        $workflow = self::query()
            ->with(['owner', 'reviewer'])
            ->where('content_type', self::targetType($type))
            ->where('content_id', (int) data_get($record, 'id'))
            ->first();

        if (! $workflow instanceof EditorialReview) {
            return null;
        }

        $updatedAt = data_get($record, 'updated_at');
        if (
            $updatedAt instanceof \DateTimeInterface
            && $workflow->last_transition_at instanceof \DateTimeInterface
            && $workflow->last_transition_at < $updatedAt
        ) {
            return [
                'state' => self::STATE_READY,
                'label' => self::label(self::STATE_READY),
                'reviewed_at' => optional($workflow->reviewed_at)?->toDateTimeString() ?? 'Unknown',
                'owner_admin_user_id' => $workflow->owner_admin_user_id,
                'owner_label' => trim((string) optional($workflow->owner)->name),
                'reviewer_admin_user_id' => $workflow->reviewer_admin_user_id,
                'reviewer_label' => trim((string) optional($workflow->reviewer)->name),
            ];
        }

        return [
            'state' => (string) $workflow->workflow_state,
            'label' => self::label((string) $workflow->workflow_state),
            'reviewed_at' => optional($workflow->reviewed_at ?? $workflow->submitted_at)?->toDateTimeString() ?? 'Unknown',
            'owner_admin_user_id' => $workflow->owner_admin_user_id,
            'owner_label' => trim((string) optional($workflow->owner)->name),
            'reviewer_admin_user_id' => $workflow->reviewer_admin_user_id,
            'reviewer_label' => trim((string) optional($workflow->reviewer)->name),
        ];
    }

    public static function label(string $state): string
    {
        return match ($state) {
            self::STATE_IN_REVIEW => 'In review',
            self::STATE_APPROVED => 'Approved',
            self::STATE_CHANGES_REQUESTED => 'Changes requested',
            self::STATE_REJECTED => 'Rejected',
            self::STATE_NEEDS_ATTENTION => 'Needs attention',
            default => 'Ready',
        };
    }

    public static function isAssignedReviewer(string $type, object $record, ?int $adminUserId = null): bool
    {
        $snapshot = self::latestState($type, $record);
        $adminUserId ??= self::actorAdminId();

        return $adminUserId > 0
            && (int) ($snapshot['reviewer_admin_user_id'] ?? 0) === $adminUserId;
    }

    public static function isAssignedOwner(string $type, object $record, ?int $adminUserId = null): bool
    {
        $snapshot = self::latestState($type, $record);
        $adminUserId ??= self::actorAdminId();

        return $adminUserId > 0
            && (int) ($snapshot['owner_admin_user_id'] ?? 0) === $adminUserId;
    }

    public static function resetStateAfterEdit(string $type, object $record): void
    {
        $workflow = self::query()
            ->where('content_type', self::targetType($type))
            ->where('content_id', (int) data_get($record, 'id'))
            ->first();

        if (! $workflow instanceof EditorialReview) {
            return;
        }

        self::resetWorkflowState($workflow);
        $workflow->last_transition_at = now();
        $workflow->save();

        self::syncGovernanceSnapshot($record, [
            'publish_gate_state' => self::STATE_READY,
        ]);
    }

    private static function targetType(string $type): string
    {
        return match ($type) {
            'article' => 'article',
            'guide' => 'career_guide',
            'job' => 'career_job',
            'method' => 'method_page',
            'data' => 'data_page',
            'personality' => 'personality_profile',
            'topic' => 'topic_profile',
            default => 'content',
        };
    }

    private static function workflowFor(string $type, object $record): EditorialReview
    {
        /** @var EditorialReview|null $workflow */
        $workflow = self::query()
            ->where('content_type', self::targetType($type))
            ->where('content_id', (int) data_get($record, 'id'))
            ->first();

        if ($workflow instanceof EditorialReview) {
            return $workflow;
        }

        return EditorialReview::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'org_id' => max(0, (int) data_get($record, 'org_id', 0)),
            'content_type' => self::targetType($type),
            'content_id' => (int) data_get($record, 'id'),
            'workflow_state' => self::STATE_READY,
            'last_transition_at' => now(),
        ]);
    }

    private static function query()
    {
        return EditorialReview::withoutGlobalScopes();
    }

    private static function assertCanSubmit(EditorialReview $workflow, string $type, object $record): void
    {
        if ((int) $workflow->owner_admin_user_id <= 0 || (int) $workflow->reviewer_admin_user_id <= 0) {
            throw new AuthorizationException('Assign both an owner and reviewer before submitting for review.');
        }

        if (EditorialReviewChecklist::missing($type, $record) !== []) {
            throw new AuthorizationException('Fix the review checklist gaps before submitting for review.');
        }

        if ($workflow->workflow_state === self::STATE_IN_REVIEW && ! self::hasNewerEdits($workflow, $record)) {
            throw new AuthorizationException('This record is already in review.');
        }

        if ($workflow->workflow_state === self::STATE_APPROVED && ! self::hasNewerEdits($workflow, $record)) {
            throw new AuthorizationException('This record is already approved and has no newer edits to resubmit.');
        }

        if (! ContentAccess::isOwner() && self::actorAdminId() !== (int) $workflow->owner_admin_user_id) {
            throw new AuthorizationException('Only the assigned owner can submit this record for review.');
        }
    }

    private static function assertCanMark(EditorialReview $workflow, string $decision, string $type, object $record): void
    {
        if (self::hasNewerEdits($workflow, $record)) {
            throw new AuthorizationException('This record changed after submission and must be resubmitted before review can continue.');
        }

        if ($workflow->workflow_state !== self::STATE_IN_REVIEW) {
            throw new AuthorizationException('This record is not currently in review.');
        }

        $actorAdminId = self::actorAdminId();
        $isAssignedReviewer = $actorAdminId !== null && $actorAdminId === (int) $workflow->reviewer_admin_user_id;

        if (! ContentAccess::isOwner() && (! ContentAccess::canReview() || ! $isAssignedReviewer)) {
            throw new AuthorizationException('Only the assigned reviewer can decide this record.');
        }

        if ($decision === self::STATE_APPROVED && EditorialReviewChecklist::missing($type, $record) !== []) {
            throw new AuthorizationException('A record with checklist gaps cannot be approved.');
        }
    }

    private static function hasNewerEdits(EditorialReview $workflow, object $record): bool
    {
        $updatedAt = data_get($record, 'updated_at');

        return $updatedAt instanceof \DateTimeInterface
            && $workflow->last_transition_at instanceof \DateTimeInterface
            && $workflow->last_transition_at < $updatedAt;
    }

    private static function resetWorkflowState(EditorialReview $workflow): void
    {
        $workflow->workflow_state = self::STATE_READY;
        $workflow->submitted_by_admin_user_id = null;
        $workflow->reviewed_by_admin_user_id = null;
        $workflow->submitted_at = null;
        $workflow->reviewed_at = null;
    }

    private static function actorAdminId(): ?int
    {
        $guard = (string) config('admin.guard', 'admin');
        $actor = auth($guard)->user();

        return is_object($actor) && is_numeric(data_get($actor, 'id'))
            ? (int) data_get($actor, 'id')
            : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function log(string $action, string $type, object $record, array $meta = []): void
    {
        $guard = (string) config('admin.guard', 'admin');
        $actor = auth($guard)->user();
        $request = app()->bound('request') && request() instanceof Request
            ? request()
            : Request::create('/ops/editorial-review', 'POST');

        app(AuditLogger::class)->log(
            $request,
            $action,
            self::targetType($type),
            (string) data_get($record, 'id'),
            array_merge([
                'title' => trim((string) data_get($record, 'title', '')),
                'locale' => trim((string) data_get($record, 'locale', '')),
                'actor_email' => is_object($actor) ? trim((string) data_get($actor, 'email', '')) : '',
            ], $meta),
            reason: 'cms_editorial_review',
            result: 'success',
        );
    }

    private static function resolveOwnerCandidate(int $adminUserId): AdminUser
    {
        /** @var AdminUser|null $admin */
        $admin = AdminUser::query()->find($adminUserId);

        if (! $admin instanceof AdminUser) {
            throw new AuthorizationException('The selected owner does not exist.');
        }

        if (
            ! $admin->hasPermission(\App\Support\Rbac\PermissionNames::ADMIN_CONTENT_WRITE)
            && ! $admin->hasPermission(\App\Support\Rbac\PermissionNames::ADMIN_CONTENT_PUBLISH)
            && ! $admin->hasPermission(\App\Support\Rbac\PermissionNames::ADMIN_OWNER)
        ) {
            throw new AuthorizationException('The selected owner cannot own editorial records.');
        }

        return $admin;
    }

    private static function resolveReviewerCandidate(int $adminUserId): AdminUser
    {
        /** @var AdminUser|null $admin */
        $admin = AdminUser::query()->find($adminUserId);

        if (! $admin instanceof AdminUser) {
            throw new AuthorizationException('The selected reviewer does not exist.');
        }

        if (
            ! $admin->hasPermission(\App\Support\Rbac\PermissionNames::ADMIN_APPROVAL_REVIEW)
            && ! $admin->hasPermission(\App\Support\Rbac\PermissionNames::ADMIN_CONTENT_RELEASE)
            && ! $admin->hasPermission(\App\Support\Rbac\PermissionNames::ADMIN_CONTENT_PUBLISH)
            && ! $admin->hasPermission(\App\Support\Rbac\PermissionNames::ADMIN_OWNER)
        ) {
            throw new AuthorizationException('The selected reviewer cannot review editorial records.');
        }

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private static function syncGovernanceSnapshot(object $record, array $overrides): void
    {
        if (! $record instanceof PersonalityProfile && ! $record instanceof TopicProfile && ! method_exists($record, 'governance')) {
            return;
        }

        ContentGovernanceService::sync($record, array_merge(
            ContentGovernanceService::stateFromRecord($record),
            $overrides,
        ));
    }
}
