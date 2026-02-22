<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BigFiveOpsController extends Controller
{
    private const BIG5_ACTIONS = [
        'big5_pack_publish',
        'big5_pack_rollback',
    ];

    public function latest(Request $request, int $org_id): JsonResponse
    {
        $region = trim((string) $request->query('region', 'CN_MAINLAND'));
        if ($region === '') {
            $region = 'CN_MAINLAND';
        }
        $locale = trim((string) $request->query('locale', 'zh-CN'));
        if ($locale === '') {
            $locale = 'zh-CN';
        }
        $action = strtolower(trim((string) $request->query('action', '')));
        if (! in_array($action, ['publish', 'rollback'], true)) {
            $action = '';
        }

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

        if (! $row) {
            return response()->json([
                'ok' => false,
                'error_code' => 'RELEASE_NOT_FOUND',
                'message' => 'release not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'org_id' => $org_id,
            'item' => $this->mapReleaseRow($row),
        ]);
    }

    public function latestAudits(Request $request, int $org_id): JsonResponse
    {
        $region = trim((string) $request->query('region', 'CN_MAINLAND'));
        if ($region === '') {
            $region = 'CN_MAINLAND';
        }
        $locale = trim((string) $request->query('locale', 'zh-CN'));
        if ($locale === '') {
            $locale = 'zh-CN';
        }
        $releaseAction = strtolower(trim((string) $request->query('release_action', '')));
        $legacyAction = strtolower(trim((string) $request->query('action', '')));
        if ($releaseAction === '' && in_array($legacyAction, ['publish', 'rollback'], true)) {
            $releaseAction = $legacyAction;
        }
        if (! in_array($releaseAction, ['publish', 'rollback'], true)) {
            $releaseAction = '';
        }
        $result = strtolower(trim((string) $request->query('result', '')));
        if (! in_array($result, ['success', 'failed'], true)) {
            $result = '';
        }
        $limit = (int) $request->query('limit', 20);
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $query = DB::table('content_pack_releases')
            ->where('region', $region)
            ->where('locale', $locale)
            ->where(function ($q): void {
                $q->where('to_pack_id', 'BIG5_OCEAN')
                    ->orWhere('from_pack_id', 'BIG5_OCEAN');
            });

        if ($releaseAction !== '') {
            $query->where('action', $releaseAction);
        }

        $row = $query
            ->orderByDesc('created_at')
            ->orderByDesc('updated_at')
            ->first();

        if (! $row) {
            return response()->json([
                'ok' => false,
                'error_code' => 'RELEASE_NOT_FOUND',
                'message' => 'release not found.',
            ], 404);
        }

        $audits = DB::table('audit_logs')
            ->whereIn('action', self::BIG5_ACTIONS)
            ->where('target_id', (string) ($row->id ?? ''))
            ->when($result !== '', function ($q) use ($result): void {
                $q->where('result', $result);
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $auditItems = [];
        foreach ($audits as $audit) {
            $auditItems[] = $this->mapAuditRow($audit);
        }

        return response()->json([
            'ok' => true,
            'org_id' => $org_id,
            'item' => $this->mapReleaseRow($row),
            'count' => count($auditItems),
            'audits' => $auditItems,
        ]);
    }

    public function releases(Request $request, int $org_id): JsonResponse
    {
        $limit = (int) $request->query('limit', 20);
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $region = trim((string) $request->query('region', 'CN_MAINLAND'));
        if ($region === '') {
            $region = 'CN_MAINLAND';
        }
        $locale = trim((string) $request->query('locale', 'zh-CN'));
        if ($locale === '') {
            $locale = 'zh-CN';
        }

        $action = strtolower(trim((string) $request->query('action', '')));
        if (! in_array($action, ['publish', 'rollback'], true)) {
            $action = '';
        }

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

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->mapReleaseRow($row);
        }

        return response()->json([
            'ok' => true,
            'org_id' => $org_id,
            'count' => count($items),
            'items' => $items,
        ]);
    }

    public function audits(Request $request, int $org_id): JsonResponse
    {
        $limit = (int) $request->query('limit', 20);
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $action = trim((string) $request->query('action', ''));
        if (! in_array($action, ['big5_pack_publish', 'big5_pack_rollback'], true)) {
            $action = '';
        }
        $result = strtolower(trim((string) $request->query('result', '')));
        if (! in_array($result, ['success', 'failed'], true)) {
            $result = '';
        }
        $releaseId = trim((string) $request->query('release_id', ''));

        $query = DB::table('audit_logs')
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

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->mapAuditRow($row);
        }

        return response()->json([
            'ok' => true,
            'org_id' => $org_id,
            'count' => count($items),
            'items' => $items,
        ]);
    }

    public function audit(Request $request, int $org_id, string $audit_id): JsonResponse
    {
        $audit_id = trim($audit_id);
        if ($audit_id === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'AUDIT_NOT_FOUND',
                'message' => 'audit not found.',
            ], 404);
        }

        $row = DB::table('audit_logs')
            ->where('id', $audit_id)
            ->whereIn('action', self::BIG5_ACTIONS)
            ->first();

        if (! $row) {
            return response()->json([
                'ok' => false,
                'error_code' => 'AUDIT_NOT_FOUND',
                'message' => 'audit not found.',
            ], 404);
        }

        $release = null;
        $targetType = (string) ($row->target_type ?? '');
        $targetId = (string) ($row->target_id ?? '');
        if ($targetType === 'content_pack_release' && $targetId !== '') {
            $releaseRow = DB::table('content_pack_releases')
                ->where('id', $targetId)
                ->where(function ($q): void {
                    $q->where('to_pack_id', 'BIG5_OCEAN')
                        ->orWhere('from_pack_id', 'BIG5_OCEAN');
                })
                ->first();
            if ($releaseRow) {
                $release = $this->mapReleaseRow($releaseRow);
            }
        }

        return response()->json([
            'ok' => true,
            'org_id' => $org_id,
            'item' => $this->mapAuditRow($row),
            'release' => $release,
        ]);
    }

    public function release(Request $request, int $org_id, string $release_id): JsonResponse
    {
        $release_id = trim($release_id);
        if ($release_id === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'RELEASE_NOT_FOUND',
                'message' => 'release not found.',
            ], 404);
        }

        $row = DB::table('content_pack_releases')
            ->where('id', $release_id)
            ->where(function ($q): void {
                $q->where('to_pack_id', 'BIG5_OCEAN')
                    ->orWhere('from_pack_id', 'BIG5_OCEAN');
            })
            ->first();

        if (! $row) {
            return response()->json([
                'ok' => false,
                'error_code' => 'RELEASE_NOT_FOUND',
                'message' => 'release not found.',
            ], 404);
        }

        $audits = DB::table('audit_logs')
            ->whereIn('action', self::BIG5_ACTIONS)
            ->where('target_id', $release_id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $auditItems = [];
        foreach ($audits as $audit) {
            $auditItems[] = $this->mapAuditRow($audit);
        }

        return response()->json([
            'ok' => true,
            'org_id' => $org_id,
            'item' => $this->mapReleaseRow($row),
            'audits' => $auditItems,
        ]);
    }

    public function publish(Request $request, int $org_id): JsonResponse
    {
        $region = $this->normalizeRegion((string) $request->input('region', 'CN_MAINLAND'));
        $locale = $this->normalizeLocale((string) $request->input('locale', 'zh-CN'));
        $dirAlias = $this->normalizeDirAlias((string) $request->input('dir_alias', 'v1'));
        $pack = trim((string) $request->input('pack', 'BIG5_OCEAN'));
        $packVersion = trim((string) $request->input('pack_version', 'v1'));
        $probe = $this->toBool($request->input('probe', false));
        $skipDrift = $this->toBool($request->input('skip_drift', true));
        $baseUrl = trim((string) $request->input('base_url', ''));

        $startedAt = now();
        $args = [
            '--scale' => 'BIG5_OCEAN',
            '--pack' => $pack === '' ? 'BIG5_OCEAN' : $pack,
            '--pack-version' => $packVersion === '' ? 'v1' : $packVersion,
            '--region' => $region,
            '--locale' => $locale,
            '--dir_alias' => $dirAlias,
            '--probe' => $probe ? '1' : '0',
            '--skip_drift' => $skipDrift ? '1' : '0',
            '--created_by' => $this->resolveActor($request, $org_id),
        ];
        if ($baseUrl !== '') {
            $args['--base_url'] = $baseUrl;
        }
        foreach (['drift_from', 'drift_to', 'drift_group_id', 'drift_threshold_mean', 'drift_threshold_sd'] as $key) {
            $val = trim((string) $request->input($key, ''));
            if ($val !== '') {
                $args['--'.str_replace('_', '-', $key)] = $val;
            }
        }

        $exitCode = Artisan::call('packs:publish', $args);
        $commandOutput = trim(Artisan::output());
        $releaseId = $this->extractCommandValue($commandOutput, 'release_id');
        $release = null;
        if ($releaseId !== '') {
            $release = DB::table('content_pack_releases')
                ->where('id', $releaseId)
                ->first();
        }
        if (! $release) {
            $release = $this->latestRelease('publish', $region, $locale, $dirAlias, $startedAt);
        }
        if (! $release) {
            return response()->json([
                'ok' => false,
                'error_code' => 'PUBLISH_RELEASE_NOT_FOUND',
                'message' => $commandOutput === '' ? 'publish release not found.' : $commandOutput,
                'org_id' => $org_id,
                'action' => 'publish',
                'status' => 'failed',
                'exit_code' => $exitCode,
            ], 422);
        }

        $mapped = $this->mapReleaseRow($release);
        $status = (string) ($mapped['status'] ?? 'failed');
        $ok = $exitCode === 0 && $status === 'success';

        return response()->json([
            'ok' => $ok,
            'org_id' => $org_id,
            'action' => 'publish',
            'status' => $status,
            'exit_code' => $exitCode,
            'message' => $commandOutput === '' ? ((string) ($mapped['message'] ?? '')) : $commandOutput,
            'release' => $mapped,
        ], $ok ? 200 : 422);
    }

    public function rollback(Request $request, int $org_id): JsonResponse
    {
        $region = $this->normalizeRegion((string) $request->input('region', 'CN_MAINLAND'));
        $locale = $this->normalizeLocale((string) $request->input('locale', 'zh-CN'));
        $dirAlias = $this->normalizeDirAlias((string) $request->input('dir_alias', 'v1'));
        $probe = $this->toBool($request->input('probe', false));
        $baseUrl = trim((string) $request->input('base_url', ''));
        $toReleaseId = trim((string) $request->input('to_release_id', ''));

        $startedAt = now();
        $args = [
            '--scale' => 'BIG5_OCEAN',
            '--region' => $region,
            '--locale' => $locale,
            '--dir_alias' => $dirAlias,
            '--probe' => $probe ? '1' : '0',
        ];
        if ($baseUrl !== '') {
            $args['--base_url'] = $baseUrl;
        }
        if ($toReleaseId !== '') {
            $args['--to_release_id'] = $toReleaseId;
        }

        $exitCode = Artisan::call('packs:rollback', $args);
        $commandOutput = trim(Artisan::output());
        $releaseId = $this->extractCommandValue($commandOutput, 'release_id');
        $release = null;
        if ($releaseId !== '') {
            $release = DB::table('content_pack_releases')
                ->where('id', $releaseId)
                ->first();
        }
        if (! $release) {
            $release = $this->latestRelease('rollback', $region, $locale, $dirAlias, $startedAt);
        }
        if (! $release) {
            return response()->json([
                'ok' => false,
                'error_code' => 'ROLLBACK_RELEASE_NOT_FOUND',
                'message' => $commandOutput === '' ? 'rollback release not found.' : $commandOutput,
                'org_id' => $org_id,
                'action' => 'rollback',
                'status' => 'failed',
                'exit_code' => $exitCode,
            ], 422);
        }

        $mapped = $this->mapReleaseRow($release);
        $status = (string) ($mapped['status'] ?? 'failed');
        $ok = $exitCode === 0 && $status === 'success';

        return response()->json([
            'ok' => $ok,
            'org_id' => $org_id,
            'action' => 'rollback',
            'status' => $status,
            'exit_code' => $exitCode,
            'message' => $commandOutput === '' ? ((string) ($mapped['message'] ?? '')) : $commandOutput,
            'release' => $mapped,
        ], $ok ? 200 : 422);
    }

    public function rebuildNorms(Request $request, int $org_id): JsonResponse
    {
        $locale = $this->normalizeLocale((string) $request->input('locale', 'zh-CN'));
        $region = $this->normalizeRegion((string) $request->input('region', $locale === 'zh-CN' ? 'CN_MAINLAND' : 'GLOBAL'));
        $group = trim((string) $request->input('group', 'prod_all_18-60'));
        if ($group === '') {
            $group = 'prod_all_18-60';
        }
        $groupId = $this->resolveGroupId($locale, $group);
        $gender = strtoupper(trim((string) $request->input('gender', 'ALL')));
        if ($gender === '') {
            $gender = 'ALL';
        }
        $ageMin = max(1, (int) $request->input('age_min', 18));
        $ageMax = max($ageMin, (int) $request->input('age_max', 60));
        $windowDays = max(1, (int) $request->input('window_days', 365));
        $minSamples = max(1, (int) $request->input('min_samples', 1000));
        $onlyQuality = trim((string) $request->input('only_quality', 'AB'));
        if ($onlyQuality === '') {
            $onlyQuality = 'AB';
        }
        $normsVersion = trim((string) $request->input('norms_version', ''));
        $activate = $this->toBool($request->input('activate', true));
        $dryRun = $this->toBool($request->input('dry_run', false));

        $args = [
            '--locale' => $locale,
            '--region' => $region,
            '--group' => $group,
            '--gender' => $gender,
            '--age_min' => (string) $ageMin,
            '--age_max' => (string) $ageMax,
            '--window_days' => (string) $windowDays,
            '--min_samples' => (string) $minSamples,
            '--only_quality' => $onlyQuality,
            '--activate' => $activate ? '1' : '0',
            '--dry-run' => $dryRun ? '1' : '0',
        ];
        if ($normsVersion !== '') {
            $args['--norms_version'] = $normsVersion;
        }

        $exitCode = Artisan::call('norms:big5:rebuild', $args);
        $commandOutput = trim(Artisan::output());
        $status = $exitCode === 0 ? 'success' : 'failed';
        $reason = $status === 'success' ? null : 'REBUILD_FAILED';

        $versionRow = null;
        if ($status === 'success' && ! $dryRun) {
            $query = DB::table('scale_norms_versions')
                ->where('scale_code', 'BIG5_OCEAN')
                ->where('region', $region)
                ->where('locale', $locale)
                ->where('group_id', $groupId);
            if ($normsVersion !== '') {
                $query->where('version', $normsVersion);
            }
            $versionRow = $query->orderByDesc('created_at')->first();
        }

        $this->recordOpsAudit(
            $request,
            'big5_norms_rebuild',
            'norms_group',
            $groupId,
            $status,
            $reason,
            [
                'org_id' => $org_id,
                'scale_code' => 'BIG5_OCEAN',
                'locale' => $locale,
                'region' => $region,
                'group_id' => $groupId,
                'gender' => $gender,
                'age_min' => $ageMin,
                'age_max' => $ageMax,
                'window_days' => $windowDays,
                'min_samples' => $minSamples,
                'only_quality' => $onlyQuality,
                'norms_version' => $normsVersion,
                'activate' => $activate,
                'dry_run' => $dryRun,
                'exit_code' => $exitCode,
                'output' => $commandOutput,
            ]
        );

        return response()->json([
            'ok' => $status === 'success',
            'org_id' => $org_id,
            'action' => 'norms_rebuild',
            'status' => $status,
            'exit_code' => $exitCode,
            'message' => $commandOutput,
            'item' => $versionRow ? $this->mapNormVersionRow($versionRow) : null,
        ], $status === 'success' ? 200 : 422);
    }

    public function driftCheckNorms(Request $request, int $org_id): JsonResponse
    {
        $from = trim((string) $request->input('from', ''));
        $to = trim((string) $request->input('to', ''));
        if ($from === '' || $to === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'INVALID_ARGUMENT',
                'message' => 'from and to are required.',
            ], 422);
        }

        $groupId = trim((string) $request->input('group_id', ''));
        $thresholdMean = trim((string) $request->input('threshold_mean', '0.35'));
        $thresholdSd = trim((string) $request->input('threshold_sd', '0.35'));

        $args = [
            '--scale' => 'BIG5_OCEAN',
            '--from' => $from,
            '--to' => $to,
            '--threshold_mean' => $thresholdMean === '' ? '0.35' : $thresholdMean,
            '--threshold_sd' => $thresholdSd === '' ? '0.35' : $thresholdSd,
        ];
        if ($groupId !== '') {
            $args['--group_id'] = $groupId;
        }

        $exitCode = Artisan::call('norms:big5:drift-check', $args);
        $commandOutput = trim(Artisan::output());
        $status = $exitCode === 0 ? 'success' : 'failed';
        $reason = $status === 'success' ? null : 'DRIFT_CHECK_FAILED';

        $this->recordOpsAudit(
            $request,
            'big5_norms_drift_check',
            'norms_group',
            $groupId === '' ? 'all' : $groupId,
            $status,
            $reason,
            [
                'org_id' => $org_id,
                'scale_code' => 'BIG5_OCEAN',
                'from' => $from,
                'to' => $to,
                'group_id' => $groupId,
                'threshold_mean' => (float) ($thresholdMean === '' ? 0.35 : $thresholdMean),
                'threshold_sd' => (float) ($thresholdSd === '' ? 0.35 : $thresholdSd),
                'exit_code' => $exitCode,
                'output' => $commandOutput,
            ]
        );

        return response()->json([
            'ok' => $status === 'success',
            'org_id' => $org_id,
            'action' => 'norms_drift_check',
            'status' => $status,
            'exit_code' => $exitCode,
            'message' => $commandOutput,
        ], $status === 'success' ? 200 : 422);
    }

    public function activateNorms(Request $request, int $org_id): JsonResponse
    {
        $groupId = trim((string) $request->input('group_id', ''));
        $normsVersion = trim((string) $request->input('norms_version', ''));
        $region = $this->normalizeRegion((string) $request->input('region', 'CN_MAINLAND'));
        $locale = $this->normalizeLocale((string) $request->input('locale', 'zh-CN'));

        if ($groupId === '' || $normsVersion === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'INVALID_ARGUMENT',
                'message' => 'group_id and norms_version are required.',
            ], 422);
        }

        try {
            $updated = DB::transaction(function () use ($groupId, $normsVersion, $region, $locale): ?object {
                $target = DB::table('scale_norms_versions')
                    ->where('scale_code', 'BIG5_OCEAN')
                    ->where('group_id', $groupId)
                    ->where('version', $normsVersion)
                    ->where('region', $region)
                    ->where('locale', $locale)
                    ->first();
                if (! $target) {
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

                return DB::table('scale_norms_versions')
                    ->where('id', (string) $target->id)
                    ->first();
            });
        } catch (Throwable $e) {
            $this->recordOpsAudit(
                $request,
                'big5_norms_activate',
                'norms_group',
                $groupId,
                'failed',
                'ACTIVATE_FAILED',
                [
                    'org_id' => $org_id,
                    'scale_code' => 'BIG5_OCEAN',
                    'group_id' => $groupId,
                    'norms_version' => $normsVersion,
                    'region' => $region,
                    'locale' => $locale,
                    'error_message' => $e->getMessage(),
                ]
            );

            return response()->json([
                'ok' => false,
                'error_code' => 'ACTIVATE_FAILED',
                'message' => $e->getMessage(),
            ], 500);
        }

        if (! $updated) {
            $this->recordOpsAudit(
                $request,
                'big5_norms_activate',
                'norms_group',
                $groupId,
                'failed',
                'NORM_VERSION_NOT_FOUND',
                [
                    'org_id' => $org_id,
                    'scale_code' => 'BIG5_OCEAN',
                    'group_id' => $groupId,
                    'norms_version' => $normsVersion,
                    'region' => $region,
                    'locale' => $locale,
                ]
            );

            return response()->json([
                'ok' => false,
                'error_code' => 'NORM_VERSION_NOT_FOUND',
                'message' => 'norm version not found.',
            ], 404);
        }

        $this->recordOpsAudit(
            $request,
            'big5_norms_activate',
            'norms_group',
            $groupId,
            'success',
            null,
            [
                'org_id' => $org_id,
                'scale_code' => 'BIG5_OCEAN',
                'group_id' => $groupId,
                'norms_version' => $normsVersion,
                'region' => $region,
                'locale' => $locale,
                'norm_version_id' => (string) ($updated->id ?? ''),
            ]
        );

        return response()->json([
            'ok' => true,
            'org_id' => $org_id,
            'action' => 'norms_activate',
            'status' => 'success',
            'item' => $this->mapNormVersionRow($updated),
        ]);
    }

    private function normalizeRegion(string $region): string
    {
        $normalized = strtoupper(trim($region));
        if ($normalized === '') {
            return 'CN_MAINLAND';
        }

        return $normalized;
    }

    private function normalizeLocale(string $locale): string
    {
        $normalized = trim($locale);
        if ($normalized === '') {
            return 'zh-CN';
        }

        return $normalized;
    }

    private function normalizeDirAlias(string $dirAlias): string
    {
        $normalized = trim($dirAlias);
        if ($normalized === '') {
            return 'v1';
        }

        return $normalized;
    }

    private function resolveActor(Request $request, int $orgId): string
    {
        $fmUserId = $request->attributes->get('fm_user_id');
        $userId = is_numeric($fmUserId) ? (string) (int) $fmUserId : trim((string) $fmUserId);
        if ($userId === '') {
            $userId = '0';
        }

        return "ops_user:{$userId}@org:{$orgId}";
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function resolveGroupId(string $locale, string $group): string
    {
        if (str_starts_with($group, $locale.'_')) {
            return $group;
        }

        return $locale.'_'.$group;
    }

    private function latestRelease(string $action, string $region, string $locale, string $dirAlias, \Carbon\CarbonInterface $startedAt): ?object
    {
        return DB::table('content_pack_releases')
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
    }

    private function extractCommandValue(string $output, string $key): string
    {
        $needle = $key.'=';
        foreach (preg_split('/\\r\\n|\\r|\\n/', $output) as $line) {
            $line = trim((string) $line);
            if ($line === '' || ! str_starts_with($line, $needle)) {
                continue;
            }

            return trim(substr($line, strlen($needle)));
        }

        return '';
    }

    /**
     * @return array<string,mixed>
     */
    private function mapNormVersionRow(object $row): array
    {
        return [
            'id' => (string) ($row->id ?? ''),
            'scale_code' => (string) ($row->scale_code ?? ''),
            'region' => (string) ($row->region ?? ''),
            'locale' => (string) ($row->locale ?? ''),
            'group_id' => (string) ($row->group_id ?? ''),
            'norms_version' => (string) ($row->version ?? ''),
            'status' => (string) ($row->status ?? ''),
            'is_active' => (bool) ($row->is_active ?? false),
            'source_id' => (string) ($row->source_id ?? ''),
            'source_type' => (string) ($row->source_type ?? ''),
            'published_at' => (string) ($row->published_at ?? ''),
            'updated_at' => (string) ($row->updated_at ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function recordOpsAudit(
        Request $request,
        string $action,
        string $targetType,
        string $targetId,
        string $result,
        ?string $reason,
        array $meta
    ): void {
        DB::table('audit_logs')->insert([
            'actor_admin_id' => null,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => substr((string) $request->ip(), 0, 64),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'request_id' => substr((string) $request->headers->get('X-Request-Id', ''), 0, 128),
            'reason' => $reason,
            'result' => $result,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function mapReleaseRow(object $row): array
    {
        return [
            'release_id' => (string) ($row->id ?? ''),
            'action' => (string) ($row->action ?? ''),
            'status' => (string) ($row->status ?? ''),
            'message' => (string) ($row->message ?? ''),
            'dir_alias' => (string) ($row->dir_alias ?? ''),
            'region' => (string) ($row->region ?? ''),
            'locale' => (string) ($row->locale ?? ''),
            'from_pack_id' => (string) ($row->from_pack_id ?? ''),
            'to_pack_id' => (string) ($row->to_pack_id ?? ''),
            'from_version_id' => (string) ($row->from_version_id ?? ''),
            'to_version_id' => (string) ($row->to_version_id ?? ''),
            'created_by' => (string) ($row->created_by ?? ''),
            'created_at' => (string) ($row->created_at ?? ''),
            'updated_at' => (string) ($row->updated_at ?? ''),
            'evidence' => [
                'manifest_hash' => (string) ($row->manifest_hash ?? ''),
                'compiled_hash' => (string) ($row->compiled_hash ?? ''),
                'content_hash' => (string) ($row->content_hash ?? ''),
                'norms_version' => (string) ($row->norms_version ?? ''),
                'git_sha' => (string) ($row->git_sha ?? ''),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function mapAuditRow(object $row): array
    {
        return [
            'id' => (int) ($row->id ?? 0),
            'action' => (string) ($row->action ?? ''),
            'result' => (string) ($row->result ?? ''),
            'reason' => (string) ($row->reason ?? ''),
            'target_type' => (string) ($row->target_type ?? ''),
            'target_id' => (string) ($row->target_id ?? ''),
            'request_id' => (string) ($row->request_id ?? ''),
            'created_at' => (string) ($row->created_at ?? ''),
            'meta' => $this->decodeJson((string) ($row->meta_json ?? '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
