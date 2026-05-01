<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Throwable;

final class BigFiveOpsActionService
{
    /**
     * @var list<string>
     */
    private const BIG5_ACTIONS = [
        'big5_pack_publish',
        'big5_pack_rollback',
    ];

    public function __construct(
        private readonly BigFiveOpsQueryService $queryService,
        private readonly BigFiveOpsCommandRunner $commandRunner,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function latest(int $orgId, array $input): array
    {
        $region = $this->normalizeRegion((string) ($input['region'] ?? 'CN_MAINLAND'));
        $locale = $this->normalizeLocale((string) ($input['locale'] ?? 'zh-CN'));
        $action = $this->normalizeReleaseAction((string) ($input['action'] ?? ''));

        $row = $this->queryService->findLatestRelease($region, $locale, $action);
        if (! is_object($row)) {
            return [
                'status' => 404,
                'payload' => [
                    'ok' => false,
                    'error_code' => 'RELEASE_NOT_FOUND',
                    'message' => 'release not found.',
                ],
            ];
        }

        return [
            'status' => 200,
            'payload' => [
                'ok' => true,
                'org_id' => $orgId,
                'item' => $this->mapReleaseRow($row),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function latestAudits(int $orgId, array $input): array
    {
        $region = $this->normalizeRegion((string) ($input['region'] ?? 'CN_MAINLAND'));
        $locale = $this->normalizeLocale((string) ($input['locale'] ?? 'zh-CN'));
        $releaseAction = $this->normalizeReleaseAction((string) ($input['release_action'] ?? ''));
        if ($releaseAction === '') {
            $releaseAction = $this->normalizeReleaseAction((string) ($input['action'] ?? ''));
        }
        $result = $this->normalizeAuditResult((string) ($input['result'] ?? ''));
        $limit = $this->normalizeLimit($input['limit'] ?? 20);

        $row = $this->queryService->findLatestRelease($region, $locale, $releaseAction);
        if (! is_object($row)) {
            return [
                'status' => 404,
                'payload' => [
                    'ok' => false,
                    'error_code' => 'RELEASE_NOT_FOUND',
                    'message' => 'release not found.',
                ],
            ];
        }

        $releaseId = (string) ($row->id ?? '');
        $audits = $this->queryService->listLatestReleaseAudits($orgId, $releaseId, $result, $limit);
        $items = [];
        foreach ($audits as $audit) {
            $items[] = $this->mapAuditRow($audit);
        }

        return [
            'status' => 200,
            'payload' => [
                'ok' => true,
                'org_id' => $orgId,
                'item' => $this->mapReleaseRow($row),
                'count' => count($items),
                'audits' => $items,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function releases(int $orgId, array $input): array
    {
        $limit = $this->normalizeLimit($input['limit'] ?? 20);
        $region = $this->normalizeRegion((string) ($input['region'] ?? 'CN_MAINLAND'));
        $locale = $this->normalizeLocale((string) ($input['locale'] ?? 'zh-CN'));
        $action = $this->normalizeReleaseAction((string) ($input['action'] ?? ''));

        $rows = $this->queryService->listReleases($region, $locale, $action, $limit);
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->mapReleaseRow($row);
        }

        return [
            'status' => 200,
            'payload' => [
                'ok' => true,
                'org_id' => $orgId,
                'count' => count($items),
                'items' => $items,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function audits(int $orgId, array $input): array
    {
        $limit = $this->normalizeLimit($input['limit'] ?? 20);
        $action = trim((string) ($input['action'] ?? ''));
        if (! in_array($action, self::BIG5_ACTIONS, true)) {
            $action = '';
        }
        $result = $this->normalizeAuditResult((string) ($input['result'] ?? ''));
        $releaseId = trim((string) ($input['release_id'] ?? ''));

        $rows = $this->queryService->listAudits($orgId, $action, $result, $releaseId, $limit);
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->mapAuditRow($row);
        }

        return [
            'status' => 200,
            'payload' => [
                'ok' => true,
                'org_id' => $orgId,
                'count' => count($items),
                'items' => $items,
            ],
        ];
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function audit(int $orgId, string $auditId): array
    {
        $auditId = trim($auditId);
        if ($auditId === '') {
            return [
                'status' => 404,
                'payload' => [
                    'ok' => false,
                    'error_code' => 'AUDIT_NOT_FOUND',
                    'message' => 'audit not found.',
                ],
            ];
        }

        $row = $this->queryService->findAuditById($orgId, $auditId);
        if (! is_object($row)) {
            return [
                'status' => 404,
                'payload' => [
                    'ok' => false,
                    'error_code' => 'AUDIT_NOT_FOUND',
                    'message' => 'audit not found.',
                ],
            ];
        }

        $release = null;
        $targetType = (string) ($row->target_type ?? '');
        $targetId = (string) ($row->target_id ?? '');
        if ($targetType === 'content_pack_release' && $targetId !== '') {
            $releaseRow = $this->queryService->findBig5ReleaseById($targetId);
            if (is_object($releaseRow)) {
                $release = $this->mapReleaseRow($releaseRow);
            }
        }

        return [
            'status' => 200,
            'payload' => [
                'ok' => true,
                'org_id' => $orgId,
                'item' => $this->mapAuditRow($row),
                'release' => $release,
            ],
        ];
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function release(int $orgId, string $releaseId): array
    {
        $releaseId = trim($releaseId);
        if ($releaseId === '') {
            return [
                'status' => 404,
                'payload' => [
                    'ok' => false,
                    'error_code' => 'RELEASE_NOT_FOUND',
                    'message' => 'release not found.',
                ],
            ];
        }

        $row = $this->queryService->findBig5ReleaseById($releaseId);
        if (! is_object($row)) {
            return [
                'status' => 404,
                'payload' => [
                    'ok' => false,
                    'error_code' => 'RELEASE_NOT_FOUND',
                    'message' => 'release not found.',
                ],
            ];
        }

        $audits = $this->queryService->listReleaseAudits($orgId, $releaseId, 20);
        $auditItems = [];
        foreach ($audits as $audit) {
            $auditItems[] = $this->mapAuditRow($audit);
        }

        return [
            'status' => 200,
            'payload' => [
                'ok' => true,
                'org_id' => $orgId,
                'item' => $this->mapReleaseRow($row),
                'audits' => $auditItems,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $context
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function publish(int $orgId, array $input, array $context): array
    {
        $region = $this->normalizeRegion((string) ($input['region'] ?? 'CN_MAINLAND'));
        $locale = $this->normalizeLocale((string) ($input['locale'] ?? 'zh-CN'));
        $dirAliasInput = (string) ($input['dir_alias'] ?? 'v1');
        if (! $this->isSafeDirAlias($dirAliasInput)) {
            return $this->validationError('dir_alias');
        }
        $dirAlias = $this->normalizeDirAlias($dirAliasInput);
        $pack = trim((string) ($input['pack'] ?? 'BIG5_OCEAN'));
        $packVersion = trim((string) ($input['pack_version'] ?? 'v1'));
        $probe = $this->toBool($input['probe'] ?? false);
        $skipDrift = $this->toBool($input['skip_drift'] ?? true);
        $baseUrl = trim((string) ($input['base_url'] ?? ''));

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
            '--created_by' => $this->resolveActor($context['fm_user_id'] ?? null, $orgId),
        ];
        if ($baseUrl !== '') {
            $args['--base_url'] = $baseUrl;
        }
        foreach (['drift_from', 'drift_to', 'drift_group_id', 'drift_threshold_mean', 'drift_threshold_sd'] as $key) {
            $val = trim((string) ($input[$key] ?? ''));
            if ($val !== '') {
                $args['--'.str_replace('_', '-', $key)] = $val;
            }
        }

        $command = $this->commandRunner->run('packs:publish', $args);
        $exitCode = (int) $command['exit_code'];
        $commandOutput = (string) $command['output'];
        $releaseId = $this->extractCommandValue($commandOutput, 'release_id');
        $release = null;
        if ($releaseId !== '') {
            $release = $this->queryService->findReleaseById($releaseId);
        }
        if (! is_object($release)) {
            $release = $this->queryService->findBig5ReleaseByActionAndLocale(
                'publish',
                $region,
                $locale,
                $dirAlias,
                $startedAt
            );
        }
        if (! is_object($release)) {
            return [
                'status' => 422,
                'payload' => [
                    'ok' => false,
                    'error_code' => 'PUBLISH_RELEASE_NOT_FOUND',
                    'message' => $commandOutput === '' ? 'publish release not found.' : $commandOutput,
                    'org_id' => $orgId,
                    'action' => 'publish',
                    'status' => 'failed',
                    'exit_code' => $exitCode,
                ],
            ];
        }

        $mapped = $this->mapReleaseRow($release);
        $status = (string) ($mapped['status'] ?? 'failed');
        $ok = $exitCode === 0 && $status === 'success';

        return [
            'status' => $ok ? 200 : 422,
            'payload' => [
                'ok' => $ok,
                'org_id' => $orgId,
                'action' => 'publish',
                'status' => $status,
                'exit_code' => $exitCode,
                'message' => $commandOutput === '' ? ((string) ($mapped['message'] ?? '')) : $commandOutput,
                'release' => $mapped,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function rollback(int $orgId, array $input): array
    {
        $region = $this->normalizeRegion((string) ($input['region'] ?? 'CN_MAINLAND'));
        $locale = $this->normalizeLocale((string) ($input['locale'] ?? 'zh-CN'));
        $dirAliasInput = (string) ($input['dir_alias'] ?? 'v1');
        if (! $this->isSafeDirAlias($dirAliasInput)) {
            return $this->validationError('dir_alias');
        }
        $dirAlias = $this->normalizeDirAlias($dirAliasInput);
        $probe = $this->toBool($input['probe'] ?? false);
        $baseUrl = trim((string) ($input['base_url'] ?? ''));
        $toReleaseId = trim((string) ($input['to_release_id'] ?? ''));

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

        $command = $this->commandRunner->run('packs:rollback', $args);
        $exitCode = (int) $command['exit_code'];
        $commandOutput = (string) $command['output'];
        $releaseId = $this->extractCommandValue($commandOutput, 'release_id');
        $release = null;
        if ($releaseId !== '') {
            $release = $this->queryService->findReleaseById($releaseId);
        }
        if (! is_object($release)) {
            $release = $this->queryService->findBig5ReleaseByActionAndLocale(
                'rollback',
                $region,
                $locale,
                $dirAlias,
                $startedAt
            );
        }
        if (! is_object($release)) {
            return [
                'status' => 422,
                'payload' => [
                    'ok' => false,
                    'error_code' => 'ROLLBACK_RELEASE_NOT_FOUND',
                    'message' => $commandOutput === '' ? 'rollback release not found.' : $commandOutput,
                    'org_id' => $orgId,
                    'action' => 'rollback',
                    'status' => 'failed',
                    'exit_code' => $exitCode,
                ],
            ];
        }

        $mapped = $this->mapReleaseRow($release);
        $status = (string) ($mapped['status'] ?? 'failed');
        $ok = $exitCode === 0 && $status === 'success';

        return [
            'status' => $ok ? 200 : 422,
            'payload' => [
                'ok' => $ok,
                'org_id' => $orgId,
                'action' => 'rollback',
                'status' => $status,
                'exit_code' => $exitCode,
                'message' => $commandOutput === '' ? ((string) ($mapped['message'] ?? '')) : $commandOutput,
                'release' => $mapped,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $context
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function rebuildNorms(int $orgId, array $input, array $context): array
    {
        $locale = $this->normalizeLocale((string) ($input['locale'] ?? 'zh-CN'));
        $region = $this->normalizeRegion((string) ($input['region'] ?? ($locale === 'zh-CN' ? 'CN_MAINLAND' : 'GLOBAL')));
        $group = trim((string) ($input['group'] ?? 'prod_all_18-60'));
        if ($group === '') {
            $group = 'prod_all_18-60';
        }
        $groupId = $this->resolveGroupId($locale, $group);
        $gender = strtoupper(trim((string) ($input['gender'] ?? 'ALL')));
        if ($gender === '') {
            $gender = 'ALL';
        }
        $ageMin = max(1, (int) ($input['age_min'] ?? 18));
        $ageMax = max($ageMin, (int) ($input['age_max'] ?? 60));
        $windowDays = max(1, (int) ($input['window_days'] ?? 365));
        $minSamples = max(1, (int) ($input['min_samples'] ?? 1000));
        $onlyQuality = trim((string) ($input['only_quality'] ?? 'AB'));
        if ($onlyQuality === '') {
            $onlyQuality = 'AB';
        }
        $normsVersion = trim((string) ($input['norms_version'] ?? ''));
        $activate = $this->toBool($input['activate'] ?? true);
        $dryRun = $this->toBool($input['dry_run'] ?? false);

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

        $command = $this->commandRunner->run('norms:big5:rebuild', $args);
        $exitCode = (int) $command['exit_code'];
        $commandOutput = (string) $command['output'];
        $status = $exitCode === 0 ? 'success' : 'failed';
        $reason = $status === 'success' ? null : 'REBUILD_FAILED';

        $versionRow = null;
        if ($status === 'success' && ! $dryRun) {
            $versionRow = $this->queryService->findLatestNormVersion($region, $locale, $groupId, $normsVersion);
        }

        $this->recordOpsAudit(
            'big5_norms_rebuild',
            'norms_group',
            $groupId,
            $status,
            $reason,
            [
                'org_id' => $orgId,
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
            ],
            $context
        );

        return [
            'status' => $status === 'success' ? 200 : 422,
            'payload' => [
                'ok' => $status === 'success',
                'org_id' => $orgId,
                'action' => 'norms_rebuild',
                'status' => $status,
                'exit_code' => $exitCode,
                'message' => $commandOutput,
                'item' => is_object($versionRow) ? $this->mapNormVersionRow($versionRow) : null,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $context
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function driftCheckNorms(int $orgId, array $input, array $context): array
    {
        $from = trim((string) ($input['from'] ?? ''));
        $to = trim((string) ($input['to'] ?? ''));
        if ($from === '' || $to === '') {
            return [
                'status' => 422,
                'payload' => [
                    'ok' => false,
                    'error_code' => 'INVALID_ARGUMENT',
                    'message' => 'from and to are required.',
                ],
            ];
        }

        $groupId = trim((string) ($input['group_id'] ?? ''));
        $thresholdMean = trim((string) ($input['threshold_mean'] ?? '0.35'));
        $thresholdSd = trim((string) ($input['threshold_sd'] ?? '0.35'));

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

        $command = $this->commandRunner->run('norms:big5:drift-check', $args);
        $exitCode = (int) $command['exit_code'];
        $commandOutput = (string) $command['output'];
        $status = $exitCode === 0 ? 'success' : 'failed';
        $reason = $status === 'success' ? null : 'DRIFT_CHECK_FAILED';

        $this->recordOpsAudit(
            'big5_norms_drift_check',
            'norms_group',
            $groupId === '' ? 'all' : $groupId,
            $status,
            $reason,
            [
                'org_id' => $orgId,
                'scale_code' => 'BIG5_OCEAN',
                'from' => $from,
                'to' => $to,
                'group_id' => $groupId,
                'threshold_mean' => (float) ($thresholdMean === '' ? '0.35' : $thresholdMean),
                'threshold_sd' => (float) ($thresholdSd === '' ? '0.35' : $thresholdSd),
                'exit_code' => $exitCode,
                'output' => $commandOutput,
            ],
            $context
        );

        return [
            'status' => $status === 'success' ? 200 : 422,
            'payload' => [
                'ok' => $status === 'success',
                'org_id' => $orgId,
                'action' => 'norms_drift_check',
                'status' => $status,
                'exit_code' => $exitCode,
                'message' => $commandOutput,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $context
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function activateNorms(int $orgId, array $input, array $context): array
    {
        $groupId = trim((string) ($input['group_id'] ?? ''));
        $normsVersion = trim((string) ($input['norms_version'] ?? ''));
        $region = $this->normalizeRegion((string) ($input['region'] ?? 'CN_MAINLAND'));
        $locale = $this->normalizeLocale((string) ($input['locale'] ?? 'zh-CN'));

        if ($groupId === '' || $normsVersion === '') {
            return [
                'status' => 422,
                'payload' => [
                    'ok' => false,
                    'error_code' => 'INVALID_ARGUMENT',
                    'message' => 'group_id and norms_version are required.',
                ],
            ];
        }

        try {
            $updated = $this->queryService->activateNormVersion($groupId, $normsVersion, $region, $locale);
        } catch (Throwable $e) {
            $this->recordOpsAudit(
                'big5_norms_activate',
                'norms_group',
                $groupId,
                'failed',
                'ACTIVATE_FAILED',
                [
                    'org_id' => $orgId,
                    'scale_code' => 'BIG5_OCEAN',
                    'group_id' => $groupId,
                    'norms_version' => $normsVersion,
                    'region' => $region,
                    'locale' => $locale,
                    'error_message' => $e->getMessage(),
                ],
                $context
            );

            return [
                'status' => 500,
                'payload' => [
                    'ok' => false,
                    'error_code' => 'ACTIVATE_FAILED',
                    'message' => $e->getMessage(),
                ],
            ];
        }

        if (! is_object($updated)) {
            $this->recordOpsAudit(
                'big5_norms_activate',
                'norms_group',
                $groupId,
                'failed',
                'NORM_VERSION_NOT_FOUND',
                [
                    'org_id' => $orgId,
                    'scale_code' => 'BIG5_OCEAN',
                    'group_id' => $groupId,
                    'norms_version' => $normsVersion,
                    'region' => $region,
                    'locale' => $locale,
                ],
                $context
            );

            return [
                'status' => 404,
                'payload' => [
                    'ok' => false,
                    'error_code' => 'NORM_VERSION_NOT_FOUND',
                    'message' => 'norm version not found.',
                ],
            ];
        }

        $this->recordOpsAudit(
            'big5_norms_activate',
            'norms_group',
            $groupId,
            'success',
            null,
            [
                'org_id' => $orgId,
                'scale_code' => 'BIG5_OCEAN',
                'group_id' => $groupId,
                'norms_version' => $normsVersion,
                'region' => $region,
                'locale' => $locale,
                'norm_version_id' => (string) ($updated->id ?? ''),
            ],
            $context
        );

        return [
            'status' => 200,
            'payload' => [
                'ok' => true,
                'org_id' => $orgId,
                'action' => 'norms_activate',
                'status' => 'success',
                'item' => $this->mapNormVersionRow($updated),
            ],
        ];
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

    private function isSafeDirAlias(string $dirAlias): bool
    {
        $normalized = trim($dirAlias);
        if ($normalized === '') {
            return true;
        }

        return preg_match('/\A(?!\.\.)[A-Za-z0-9_-]+\z/', $normalized) === 1;
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function validationError(string $field): array
    {
        return [
            'status' => 422,
            'payload' => [
                'error_code' => 'VALIDATION_FAILED',
                'message' => 'The given data was invalid.',
                'errors' => [
                    $field => ['The '.$field.' field format is invalid.'],
                ],
            ],
        ];
    }

    private function normalizeReleaseAction(string $action): string
    {
        $normalized = strtolower(trim($action));
        if (! in_array($normalized, ['publish', 'rollback'], true)) {
            return '';
        }

        return $normalized;
    }

    private function normalizeAuditResult(string $result): string
    {
        $normalized = strtolower(trim($result));
        if (! in_array($normalized, ['success', 'failed'], true)) {
            return '';
        }

        return $normalized;
    }

    private function normalizeLimit(mixed $value): int
    {
        $limit = (int) $value;
        if ($limit < 1) {
            return 1;
        }
        if ($limit > 100) {
            return 100;
        }

        return $limit;
    }

    private function resolveActor(mixed $fmUserId, int $orgId): string
    {
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
            return (int) $value === 1;
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
     * @param  array<string,mixed>  $meta
     * @param  array<string,mixed>  $context
     */
    private function recordOpsAudit(
        string $action,
        string $targetType,
        string $targetId,
        string $result,
        ?string $reason,
        array $meta,
        array $context
    ): void {
        $this->queryService->insertAudit(
            (int) ($meta['org_id'] ?? 0),
            $action,
            $targetType,
            $targetId,
            $result,
            $reason,
            $meta,
            (string) ($context['ip'] ?? ''),
            (string) ($context['user_agent'] ?? ''),
            (string) ($context['request_id'] ?? '')
        );
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
