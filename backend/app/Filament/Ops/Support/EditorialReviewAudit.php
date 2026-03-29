<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Models\AdminUser;
use App\Models\EditorialReview;
use App\Services\Audit\AuditLogger;
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
        $workflow = self::workflowFor($type, $record);
        $workflow->owner_admin_user_id = $ownerAdminId;
        $workflow->last_transition_at = now();
        $workflow->save();

        self::log(
            action: 'editorial_review_owner_assigned',
            type: $type,
            record: $record,
            meta: [
                'owner_admin_user_id' => $ownerAdminId,
                'owner_label' => self::adminLabel($ownerAdminId),
            ],
        );

        return $workflow;
    }

    public static function assignReviewer(int $reviewerAdminId, string $type, object $record): EditorialReview
    {
        $workflow = self::workflowFor($type, $record);
        $workflow->reviewer_admin_user_id = $reviewerAdminId;
        $workflow->last_transition_at = now();
        $workflow->save();

        self::log(
            action: 'editorial_review_reviewer_assigned',
            type: $type,
            record: $record,
            meta: [
                'reviewer_admin_user_id' => $reviewerAdminId,
                'reviewer_label' => self::adminLabel($reviewerAdminId),
            ],
        );

        return $workflow;
    }

    public static function submit(string $type, object $record): EditorialReview
    {
        $workflow = self::workflowFor($type, $record);
        $actorAdminId = self::actorAdminId();

        $workflow->workflow_state = self::STATE_IN_REVIEW;
        $workflow->submitted_by_admin_user_id = $actorAdminId;
        $workflow->submitted_at = now();
        $workflow->last_transition_at = now();
        $workflow->save();

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
        $action = match ($decision) {
            self::STATE_APPROVED => 'editorial_review_approved',
            self::STATE_CHANGES_REQUESTED => 'editorial_review_changes_requested',
            self::STATE_REJECTED => 'editorial_review_rejected',
            default => throw new \InvalidArgumentException('Unsupported review decision.'),
        };

        $workflow = self::workflowFor($type, $record);
        $workflow->workflow_state = $decision;
        $workflow->reviewed_by_admin_user_id = self::actorAdminId();
        $workflow->reviewed_at = now();
        $workflow->last_transition_at = now();
        $workflow->save();

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

        $workflow->workflow_state = self::STATE_READY;
        $workflow->save();
    }

    private static function targetType(string $type): string
    {
        return match ($type) {
            'article' => 'article',
            'guide' => 'career_guide',
            'job' => 'career_job',
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

    private static function adminLabel(int $adminUserId): string
    {
        /** @var AdminUser|null $admin */
        $admin = AdminUser::query()->find($adminUserId);

        if (! $admin instanceof AdminUser) {
            return '';
        }

        return trim($admin->name !== '' ? $admin->name : $admin->email);
    }
}
