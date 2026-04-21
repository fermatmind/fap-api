<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\CareerGuide;
use App\Models\CareerGuideSeoMeta;
use App\Models\CareerJob;
use App\Models\CareerJobSeoMeta;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

final class ContentLifecycleService
{
    public const STATE_ACTIVE = 'active';

    public const STATE_DOWNRANKED = 'downranked';

    public const STATE_ARCHIVED = 'archived';

    public const STATE_SOFT_DELETED = 'soft_deleted';

    public const ACTION_ARCHIVE = 'archive';

    public const ACTION_SOFT_DELETE = 'soft_delete';

    public const ACTION_DOWN_RANK = 'down_rank';

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  list<string>  $targets
     * @param  array<int, int>  $currentOrgIds
     * @return array{processed_count:int,items:list<array<string,mixed>>}
     */
    public function applyBulk(string $action, array $targets, array $currentOrgIds): array
    {
        $processed = [];

        foreach ($targets as $target) {
            $resolved = $this->resolveTarget($target, $currentOrgIds);
            if ($resolved === null) {
                continue;
            }

            [$type, $record] = $resolved;
            $processed[] = $this->applyAction($action, $type, $record);
        }

        return [
            'processed_count' => count($processed),
            'items' => $processed,
        ];
    }

    /**
     * @param  array<int, int>  $currentOrgIds
     * @return array{processed_count:int,items:list<array<string,mixed>>}
     */
    public function applyToStaleDrafts(string $type, string $action, array $currentOrgIds, Carbon $staleThreshold): array
    {
        $records = match ($type) {
            'article' => Article::query()
                ->whereIn('org_id', $currentOrgIds)
                ->where('status', 'draft')
                ->where(function ($query): void {
                    $query->where('lifecycle_state', self::STATE_ACTIVE)
                        ->orWhereNull('lifecycle_state');
                })
                ->where('updated_at', '<', $staleThreshold)
                ->get(),
            'guide' => CareerGuide::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('status', CareerGuide::STATUS_DRAFT)
                ->where(function ($query): void {
                    $query->where('lifecycle_state', self::STATE_ACTIVE)
                        ->orWhereNull('lifecycle_state');
                })
                ->where('updated_at', '<', $staleThreshold)
                ->get(),
            'job' => CareerJob::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('status', CareerJob::STATUS_DRAFT)
                ->where(function ($query): void {
                    $query->where('lifecycle_state', self::STATE_ACTIVE)
                        ->orWhereNull('lifecycle_state');
                })
                ->where('updated_at', '<', $staleThreshold)
                ->get(),
            default => throw new AuthorizationException('Unsupported stale lifecycle type.'),
        };

        $processed = [];
        foreach ($records as $record) {
            $processed[] = $this->applyAction($action, $type, $record);
        }

        return [
            'processed_count' => count($processed),
            'items' => $processed,
        ];
    }

    /**
     * @return array{0:string,1:Model}|null
     */
    private function resolveTarget(string $target, array $currentOrgIds): ?array
    {
        [$type, $id] = array_pad(explode(':', $target, 2), 2, null);
        $type = trim((string) $type);
        $recordId = (int) $id;

        if ($recordId <= 0) {
            return null;
        }

        $record = match ($type) {
            'article' => Article::query()->whereIn('org_id', $currentOrgIds)->find($recordId),
            'guide' => CareerGuide::query()->withoutGlobalScopes()->where('org_id', 0)->find($recordId),
            'job' => CareerJob::query()->withoutGlobalScopes()->where('org_id', 0)->find($recordId),
            default => null,
        };

        return $record instanceof Model ? [$type, $record] : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function applyAction(string $action, string $type, Model $record): array
    {
        $actorAdminId = $this->actorAdminId();
        $now = now();

        match ($action) {
            self::ACTION_ARCHIVE => $this->archiveRecord($record, $actorAdminId, $now),
            self::ACTION_SOFT_DELETE => $this->softDeleteRecord($record, $actorAdminId, $now),
            self::ACTION_DOWN_RANK => $this->downRankRecord($record, $actorAdminId, $now),
            default => throw new AuthorizationException('Unsupported lifecycle action.'),
        };

        $fresh = $record->fresh();
        if (! $fresh instanceof Model) {
            throw new AuthorizationException('Unable to reload lifecycle target.');
        }

        $this->syncSeoMeta($type, $fresh, $action);
        $this->logLifecycleAudit($action, $type, $fresh);

        return [
            'target' => $type.':'.(string) $fresh->getKey(),
            'title' => trim((string) data_get($fresh, 'title', '')),
            'lifecycle_state' => trim((string) data_get($fresh, 'lifecycle_state', self::STATE_ACTIVE)),
            'status' => trim((string) data_get($fresh, 'status', '')),
        ];
    }

    private function archiveRecord(Model $record, int $actorAdminId, Carbon $now): void
    {
        $record->forceFill([
            'lifecycle_state' => self::STATE_ARCHIVED,
            'lifecycle_changed_at' => $now,
            'lifecycle_changed_by_admin_user_id' => $actorAdminId,
            'lifecycle_note' => 'archived_from_ops',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => false,
            'published_at' => null,
            'scheduled_at' => null,
        ])->save();
    }

    private function softDeleteRecord(Model $record, int $actorAdminId, Carbon $now): void
    {
        $record->forceFill([
            'lifecycle_state' => self::STATE_SOFT_DELETED,
            'lifecycle_changed_at' => $now,
            'lifecycle_changed_by_admin_user_id' => $actorAdminId,
            'lifecycle_note' => 'soft_deleted_from_ops',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => false,
            'published_at' => null,
            'scheduled_at' => null,
        ])->save();
    }

    private function downRankRecord(Model $record, int $actorAdminId, Carbon $now): void
    {
        $updates = [
            'lifecycle_state' => self::STATE_DOWNRANKED,
            'lifecycle_changed_at' => $now,
            'lifecycle_changed_by_admin_user_id' => $actorAdminId,
            'lifecycle_note' => 'down_ranked_from_ops',
            'is_indexable' => false,
        ];

        if (array_key_exists('sort_order', $record->getAttributes())) {
            $updates['sort_order'] = max(1000, ((int) data_get($record, 'sort_order', 0)) + 1000);
        }

        $record->forceFill($updates)->save();
    }

    private function syncSeoMeta(string $type, Model $record, string $action): void
    {
        $robots = $action === self::ACTION_DOWN_RANK ? 'noindex,follow' : 'noindex,nofollow';

        match ($type) {
            'article' => ArticleSeoMeta::query()
                ->withoutGlobalScopes()
                ->where('org_id', (int) data_get($record, 'org_id'))
                ->where('article_id', (int) data_get($record, 'id'))
                ->update([
                    'is_indexable' => false,
                    'robots' => $robots,
                ]),
            'guide' => CareerGuideSeoMeta::query()
                ->withoutGlobalScopes()
                ->where('career_guide_id', (int) data_get($record, 'id'))
                ->update([
                    'robots' => $robots,
                ]),
            'job' => CareerJobSeoMeta::query()
                ->withoutGlobalScopes()
                ->where('job_id', (int) data_get($record, 'id'))
                ->update([
                    'robots' => $robots,
                ]),
            default => null,
        };
    }

    private function logLifecycleAudit(string $action, string $type, Model $record): void
    {
        $targetType = match ($type) {
            'article' => 'article',
            'guide' => 'career_guide',
            'job' => 'career_job',
            default => 'content',
        };

        $this->auditLogger->log(
            app(\Illuminate\Http\Request::class),
            'content_lifecycle_'.$action,
            $targetType,
            (string) data_get($record, 'id'),
            [
                'title' => trim((string) data_get($record, 'title', '')),
                'lifecycle_state' => trim((string) data_get($record, 'lifecycle_state', self::STATE_ACTIVE)),
                'status' => trim((string) data_get($record, 'status', '')),
                'visibility' => data_get($record, 'is_public') ? 'public' : 'private',
                'indexability' => data_get($record, 'is_indexable') ? 'indexable' : 'noindex',
            ],
            reason: 'cms_content_lifecycle',
            result: 'success',
        );
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
