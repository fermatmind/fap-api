<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class BigFiveOpsQueryService
{
    /**
     * @var list<string>
     */
    private const BIG5_ACTIONS = [
        'big5_pack_publish',
        'big5_pack_rollback',
    ];

    public function findLatestRelease(string $region, string $locale, string $action = ''): ?object
    {
        $query = DB::table('content_pack_releases')
            ->where('region', $region)
            ->where('locale', $locale)
            ->where(function ($q): void {
                $q->where('to_pack_id', 'BIG5_OCEAN')
                    ->orWhere('from_pack_id', 'BIG5_OCEAN');
            });

        if ($action !== '') {
            $query->where('action', $action);
        }

        $row = $query
            ->orderByDesc('created_at')
            ->orderByDesc('updated_at')
            ->first();

        return is_object($row) ? $row : null;
    }

    /**
     * @return list<object>
     */
    public function listReleases(string $region, string $locale, string $action, int $limit): array
    {
        $query = DB::table('content_pack_releases')
            ->where('region', $region)
            ->where('locale', $locale)
            ->where(function ($q): void {
                $q->where('to_pack_id', 'BIG5_OCEAN')
                    ->orWhere('from_pack_id', 'BIG5_OCEAN');
            });

        if ($action !== '') {
            $query->where('action', $action);
        }

        $rows = $query
            ->orderByDesc('created_at')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return $rows->all();
    }

    /**
     * @return list<object>
     */
    public function listLatestReleaseAudits(int $orgId, string $releaseId, string $result, int $limit): array
    {
        $query = DB::table('audit_logs')
            ->where('org_id', max(0, $orgId))
            ->whereIn('action', self::BIG5_ACTIONS)
            ->where('target_id', $releaseId);

        if ($result !== '') {
            $query->where('result', $result);
        }

        $rows = $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $rows->all();
    }

    /**
     * @return list<object>
     */
    public function listAudits(int $orgId, string $action, string $result, string $releaseId, int $limit): array
    {
        $query = DB::table('audit_logs')
            ->where('org_id', max(0, $orgId))
            ->whereIn('action', self::BIG5_ACTIONS);

        if ($action !== '') {
            $query->where('action', $action);
        }
        if ($result !== '') {
            $query->where('result', $result);
        }
        if ($releaseId !== '') {
            $query->where('target_id', $releaseId);
        }

        $rows = $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $rows->all();
    }

    public function findAuditById(int $orgId, string $auditId): ?object
    {
        $row = DB::table('audit_logs')
            ->where('org_id', max(0, $orgId))
            ->where('id', $auditId)
            ->whereIn('action', self::BIG5_ACTIONS)
            ->first();

        return is_object($row) ? $row : null;
    }

    public function findReleaseById(string $releaseId): ?object
    {
        $row = DB::table('content_pack_releases')
            ->where('id', $releaseId)
            ->first();

        return is_object($row) ? $row : null;
    }

    public function findBig5ReleaseById(string $releaseId): ?object
    {
        $row = DB::table('content_pack_releases')
            ->where('id', $releaseId)
            ->where(function ($q): void {
                $q->where('to_pack_id', 'BIG5_OCEAN')
                    ->orWhere('from_pack_id', 'BIG5_OCEAN');
            })
            ->first();

        return is_object($row) ? $row : null;
    }

    public function findBig5ReleaseByActionAndLocale(
        string $action,
        string $region,
        string $locale,
        string $dirAlias,
        CarbonInterface $startedAt
    ): ?object {
        $row = DB::table('content_pack_releases')
            ->where('action', $action)
            ->where('region', $region)
            ->where('locale', $locale)
            ->where('dir_alias', $dirAlias)
            ->where(function ($q): void {
                $q->where('to_pack_id', 'BIG5_OCEAN')
                    ->orWhere('from_pack_id', 'BIG5_OCEAN');
            })
            ->where('created_at', '>=', $startedAt->copy()->subSeconds(2))
            ->orderByDesc('created_at')
            ->orderByDesc('updated_at')
            ->first();

        return is_object($row) ? $row : null;
    }

    /**
     * @return list<object>
     */
    public function listReleaseAudits(int $orgId, string $releaseId, int $limit = 20): array
    {
        $rows = DB::table('audit_logs')
            ->where('org_id', max(0, $orgId))
            ->whereIn('action', self::BIG5_ACTIONS)
            ->where('target_id', $releaseId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $rows->all();
    }

    public function findLatestNormVersion(
        string $region,
        string $locale,
        string $groupId,
        string $normsVersion = ''
    ): ?object {
        $query = DB::table('scale_norms_versions')
            ->where('scale_code', 'BIG5_OCEAN')
            ->where('region', $region)
            ->where('locale', $locale)
            ->where('group_id', $groupId);

        if ($normsVersion !== '') {
            $query->where('version', $normsVersion);
        }

        $row = $query->orderByDesc('created_at')->first();

        return is_object($row) ? $row : null;
    }

    public function activateNormVersion(
        string $groupId,
        string $normsVersion,
        string $region,
        string $locale
    ): ?object {
        $updated = DB::transaction(function () use ($groupId, $normsVersion, $region, $locale): ?object {
            $target = DB::table('scale_norms_versions')
                ->where('scale_code', 'BIG5_OCEAN')
                ->where('group_id', $groupId)
                ->where('version', $normsVersion)
                ->where('region', $region)
                ->where('locale', $locale)
                ->first();
            if (! is_object($target)) {
                return null;
            }

            DB::table('scale_norms_versions')
                ->where('scale_code', 'BIG5_OCEAN')
                ->where('group_id', $groupId)
                ->update([
                    'is_active' => 0,
                    'updated_at' => now(),
                ]);

            DB::table('scale_norms_versions')
                ->where('id', (string) $target->id)
                ->update([
                    'is_active' => 1,
                    'updated_at' => now(),
                ]);

            $row = DB::table('scale_norms_versions')
                ->where('id', (string) $target->id)
                ->first();

            return is_object($row) ? $row : null;
        });

        return is_object($updated) ? $updated : null;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    public function insertAudit(
        int $orgId,
        string $action,
        string $targetType,
        string $targetId,
        string $result,
        ?string $reason,
        array $meta,
        string $ip,
        string $userAgent,
        string $requestId
    ): void {
        DB::table('audit_logs')->insert([
            'org_id' => max(0, $orgId),
            'actor_admin_id' => null,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => substr($ip, 0, 64),
            'user_agent' => substr($userAgent, 0, 255),
            'request_id' => substr($requestId, 0, 128),
            'reason' => $reason,
            'result' => $result,
            'created_at' => now(),
        ]);
    }
}
