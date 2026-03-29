<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;

final class EditorialReviewAudit
{
    public const STATE_READY = 'ready';

    public const STATE_APPROVED = 'approved';

    public const STATE_CHANGES_REQUESTED = 'changes_requested';

    public const STATE_REJECTED = 'rejected';

    public const STATE_NEEDS_ATTENTION = 'needs_attention';

    public static function mark(string $decision, string $type, object $record): void
    {
        $action = match ($decision) {
            self::STATE_APPROVED => 'editorial_review_approved',
            self::STATE_CHANGES_REQUESTED => 'editorial_review_changes_requested',
            self::STATE_REJECTED => 'editorial_review_rejected',
            default => throw new \InvalidArgumentException('Unsupported review decision.'),
        };

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
            [
                'title' => trim((string) data_get($record, 'title', '')),
                'locale' => trim((string) data_get($record, 'locale', '')),
                'decision' => $decision,
                'actor_email' => is_object($actor) ? trim((string) data_get($actor, 'email', '')) : '',
            ],
            reason: 'cms_editorial_review',
            result: 'success',
        );
    }

    /**
     * @return array{state:string,label:string,reviewed_at:string}|null
     */
    public static function latestState(string $type, object $record): ?array
    {
        $row = AuditLog::query()
            ->whereIn('action', [
                'editorial_review_approved',
                'editorial_review_changes_requested',
                'editorial_review_rejected',
            ])
            ->where('target_type', self::targetType($type))
            ->where('target_id', (string) data_get($record, 'id'))
            ->latest('created_at')
            ->first();

        if (! $row instanceof AuditLog) {
            return null;
        }

        $updatedAt = data_get($record, 'updated_at');
        if ($updatedAt instanceof \DateTimeInterface && $row->created_at instanceof \DateTimeInterface && $row->created_at < $updatedAt) {
            return null;
        }

        $state = match ($row->action) {
            'editorial_review_approved' => self::STATE_APPROVED,
            'editorial_review_changes_requested' => self::STATE_CHANGES_REQUESTED,
            'editorial_review_rejected' => self::STATE_REJECTED,
            default => self::STATE_READY,
        };

        return [
            'state' => $state,
            'label' => self::label($state),
            'reviewed_at' => optional($row->created_at)?->toDateTimeString() ?? 'Unknown',
        ];
    }

    public static function label(string $state): string
    {
        return match ($state) {
            self::STATE_APPROVED => 'Approved',
            self::STATE_CHANGES_REQUESTED => 'Changes requested',
            self::STATE_REJECTED => 'Rejected',
            self::STATE_NEEDS_ATTENTION => 'Needs attention',
            default => 'Ready',
        };
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
}
