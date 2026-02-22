<?php

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Analytics\EventRecorder;
use App\Services\Assessment\GenericReportBuilder;
use Illuminate\Support\Facades\DB;

class ReportSnapshotStore
{
    private const SNAPSHOT_VERSION = 'v1';
    private const REPORT_ENGINE_VERSION = 'v1.2';

    public function __construct(
        private ReportComposer $reportComposer,
        private BigFiveReportComposer $bigFiveReportComposer,
        private ClinicalCombo68ReportComposer $clinicalCombo68ReportComposer,
        private GenericReportBuilder $genericReportBuilder,
        private EventRecorder $eventRecorder,
    ) {}

    /**
     * @param array $meta {scale_code?:string, pack_id?:string, dir_version?:string, scoring_spec_version?:string}
     */
    public function seedPendingSnapshot(int $orgId, string $attemptId, ?string $orderNo, array $meta): void
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return;
        }

        $existing = $this->findSnapshotRow($orgId, $attemptId);
        if ($existing && $this->snapshotStatus($existing) === 'ready') {
            return;
        }

        $now = now();
        $resolvedOrderNo = trim((string) ($orderNo ?? ''));
        $scoringSpecVersion = $meta['scoring_spec_version'] ?? null;
        if (is_string($scoringSpecVersion)) {
            $scoringSpecVersion = trim($scoringSpecVersion) !== '' ? trim($scoringSpecVersion) : null;
        } elseif (!is_numeric($scoringSpecVersion)) {
            $scoringSpecVersion = null;
        }

        $row = [
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'order_no' => $resolvedOrderNo !== '' ? $resolvedOrderNo : null,
            'scale_code' => strtoupper(trim((string) ($meta['scale_code'] ?? ''))),
            'pack_id' => trim((string) ($meta['pack_id'] ?? '')),
            'dir_version' => trim((string) ($meta['dir_version'] ?? '')),
            'scoring_spec_version' => $scoringSpecVersion,
            'report_engine_version' => self::REPORT_ENGINE_VERSION,
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'report_json' => '{}',
            'report_free_json' => '{}',
            'report_full_json' => '{}',
            'status' => 'pending',
            'last_error' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('report_snapshots')->insertOrIgnore($row);

        $updates = [
            'report_json' => '{}',
            'report_free_json' => '{}',
            'report_full_json' => '{}',
            'report_engine_version' => self::REPORT_ENGINE_VERSION,
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'scoring_spec_version' => $scoringSpecVersion,
            'status' => 'pending',
            'last_error' => null,
            'updated_at' => $now,
        ];
        if ($resolvedOrderNo !== '') {
            $updates['order_no'] = $resolvedOrderNo;
        }
        if ($row['scale_code'] !== '') {
            $updates['scale_code'] = $row['scale_code'];
        }
        if ($row['pack_id'] !== '') {
            $updates['pack_id'] = $row['pack_id'];
        }
        if ($row['dir_version'] !== '') {
            $updates['dir_version'] = $row['dir_version'];
        }

        $this->snapshotWriteQuery($orgId, $attemptId)->update($updates);
    }

    /**
     * @param array $ctx {org_id:int, attempt_id:string, trigger_source:string, order_no?:string, user_id?:string, anon_id?:string, org_role?:string}
     */
    public function createSnapshotForAttempt(array $ctx): array
    {
        $orgId = (int) ($ctx['org_id'] ?? 0);
        $attemptId = trim((string) ($ctx['attempt_id'] ?? ''));
        $trigger = trim((string) ($ctx['trigger_source'] ?? ''));
        $orderNo = trim((string) ($ctx['order_no'] ?? ''));
        $userId = $this->normalizeActor($ctx['user_id'] ?? null);
        $anonId = $this->normalizeActor($ctx['anon_id'] ?? null);
        $role = $this->normalizeRole($ctx['org_role'] ?? ($ctx['role'] ?? null), $userId, $anonId);

        if ($attemptId === '') {
            return $this->badRequest('ATTEMPT_REQUIRED', 'attempt_id is required.');
        }

        $existing = $this->findSnapshotRow($orgId, $attemptId);
        if ($existing && $this->snapshotStatus($existing) === 'ready') {
            return [
                'ok' => true,
                'snapshot' => $this->normalizeSnapshot($existing),
                'idempotent' => true,
            ];
        }

        $attempt = $this->ownedAttemptQuery($orgId, $attemptId, $userId, $anonId, $role)->first();
        if (!$attempt) {
            return $this->notFound('ATTEMPT_NOT_FOUND', 'attempt not found.');
        }

        $result = Result::where('org_id', $orgId)->where('attempt_id', $attemptId)->first();
        if (!$result) {
            return $this->notFound('RESULT_NOT_FOUND', 'result not found.');
        }

        $scaleCode = strtoupper((string) ($attempt->scale_code ?? ''));
        $packId = (string) ($attempt->pack_id ?? '');
        $dirVersion = (string) ($attempt->dir_version ?? '');
        $scoringSpecVersion = $attempt->scoring_spec_version ?? $result->scoring_spec_version ?? null;

        $modulesFull = ReportAccess::normalizeModules(array_merge(
            [ReportAccess::freeModuleForScale($scaleCode)],
            ReportAccess::allDefaultModulesOffered($scaleCode)
        ));
        $modulesPreview = ReportAccess::allDefaultModulesOffered($scaleCode);

        $reportFull = $this->buildVariantReport(
            $scaleCode,
            $attempt,
            $result,
            ReportAccess::VARIANT_FULL,
            $modulesFull,
            $modulesPreview
        );
        if (!is_array($reportFull)) {
            return $this->serverError('REPORT_FAILED', 'report generation failed.');
        }

        $reportFree = $this->buildVariantReport(
            $scaleCode,
            $attempt,
            $result,
            ReportAccess::VARIANT_FREE,
            ReportAccess::defaultModulesAllowedForLocked($scaleCode),
            $modulesPreview
        );
        if (!is_array($reportFree)) {
            return $this->serverError('REPORT_FAILED', 'report generation failed.');
        }

        $reportFullJson = json_encode($reportFull, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $reportFreeJson = json_encode($reportFree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($reportFullJson === false || $reportFreeJson === false) {
            return $this->serverError('REPORT_ENCODE_FAILED', 'report json encode failed.');
        }

        $now = now();

        $row = [
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'order_no' => $orderNo !== '' ? $orderNo : null,
            'scale_code' => $scaleCode,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'scoring_spec_version' => $scoringSpecVersion,
            'report_engine_version' => self::REPORT_ENGINE_VERSION,
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'report_json' => $reportFullJson,
            'report_free_json' => $reportFreeJson,
            'report_full_json' => $reportFullJson,
            'status' => 'ready',
            'last_error' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('report_snapshots')->insertOrIgnore($row);

        $updateRow = $row;
        unset($updateRow['created_at']);
        $this->snapshotWriteQuery($orgId, $attemptId)->update($updateRow);

        $this->eventRecorder->record('report_snapshot_created', null, [
            'scale_code' => $scaleCode,
            'attempt_id' => $attemptId,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'order_no' => $orderNo !== '' ? $orderNo : null,
            'trigger_source' => $trigger !== '' ? $trigger : null,
        ], [
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
        ]);

        $snapshot = $this->findSnapshotRow($orgId, $attemptId);

        return [
            'ok' => true,
            'snapshot' => $snapshot ? $this->normalizeSnapshot($snapshot) : null,
            'idempotent' => false,
        ];
    }

    private function ownedAttemptQuery(
        int $orgId,
        string $attemptId,
        ?string $userId,
        ?string $anonId,
        string $role
    ): \Illuminate\Database\Eloquent\Builder {
        $query = Attempt::query()
            ->where('id', $attemptId)
            ->where('org_id', $orgId);

        if ($this->isSystemRole($role) || $this->isPrivilegedRole($role)) {
            return $query;
        }

        if ($this->isMemberLikeRole($role)) {
            if ($userId === null) {
                return $query->whereRaw('1=0');
            }

            return $query->where('user_id', $userId);
        }

        if ($userId === null && $anonId === null) {
            return $query->whereRaw('1=0');
        }

        return $query->where(function ($q) use ($userId, $anonId) {
            if ($userId !== null) {
                $q->where('user_id', $userId);
            }
            if ($anonId !== null) {
                if ($userId !== null) {
                    $q->orWhere('anon_id', $anonId);
                } else {
                    $q->where('anon_id', $anonId);
                }
            }
        });
    }

    private function normalizeActor(mixed $raw): ?string
    {
        if (!is_string($raw) && !is_numeric($raw)) {
            return null;
        }

        $value = trim((string) $raw);

        return $value !== '' ? $value : null;
    }

    private function normalizeRole(mixed $raw, ?string $userId, ?string $anonId): string
    {
        if (is_string($raw) || is_numeric($raw)) {
            $candidate = strtolower(trim((string) $raw));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        if ($userId === null && $anonId === null) {
            return 'system';
        }

        return 'public';
    }

    private function isSystemRole(string $role): bool
    {
        return $role === 'system';
    }

    private function isPrivilegedRole(string $role): bool
    {
        return in_array($role, ['owner', 'admin'], true);
    }

    private function isMemberLikeRole(string $role): bool
    {
        return in_array($role, ['member', 'viewer'], true);
    }

    private function buildVariantReport(
        string $scaleCode,
        Attempt $attempt,
        Result $result,
        string $variant,
        array $modulesAllowed = [],
        array $modulesPreview = []
    ): ?array
    {
        if ($scaleCode === 'MBTI') {
            $composed = $this->reportComposer->composeVariant($attempt, $variant, [
                'org_id' => (int) ($attempt->org_id ?? 0),
                'variant' => $variant,
                'report_access_level' => $variant === ReportAccess::VARIANT_FREE
                    ? ReportAccess::REPORT_ACCESS_FREE
                    : ReportAccess::REPORT_ACCESS_FULL,
                'modules_allowed' => $modulesAllowed,
                'modules_preview' => $modulesPreview,
            ], $result);
            if (!($composed['ok'] ?? false)) {
                return null;
            }
            $report = $composed['report'] ?? null;

            return is_array($report) ? $report : null;
        }

        if ($scaleCode === 'BIG5_OCEAN') {
            $composed = $this->bigFiveReportComposer->composeVariant($attempt, $result, $variant, [
                'org_id' => (int) ($attempt->org_id ?? 0),
                'variant' => $variant,
                'report_access_level' => $variant === ReportAccess::VARIANT_FREE
                    ? ReportAccess::REPORT_ACCESS_FREE
                    : ReportAccess::REPORT_ACCESS_FULL,
                'modules_allowed' => $modulesAllowed,
                'modules_preview' => $modulesPreview,
            ]);
            if (!($composed['ok'] ?? false)) {
                return null;
            }
            $report = $composed['report'] ?? null;

            return is_array($report) ? $report : null;
        }

        if ($scaleCode === 'CLINICAL_COMBO_68') {
            $composed = $this->clinicalCombo68ReportComposer->composeVariant($attempt, $result, $variant, [
                'org_id' => (int) ($attempt->org_id ?? 0),
                'variant' => $variant,
                'report_access_level' => $variant === ReportAccess::VARIANT_FREE
                    ? ReportAccess::REPORT_ACCESS_FREE
                    : ReportAccess::REPORT_ACCESS_FULL,
                'modules_allowed' => $modulesAllowed,
                'modules_preview' => $modulesPreview,
            ]);
            if (!($composed['ok'] ?? false)) {
                return null;
            }
            $report = $composed['report'] ?? null;

            return is_array($report) ? $report : null;
        }

        $report = $this->genericReportBuilder->build($attempt, $result);

        return is_array($report) ? $report : null;
    }

    private function normalizeSnapshot(object $row): array
    {
        $payload = [
            'org_id' => (int) ($row->org_id ?? 0),
            'attempt_id' => (string) ($row->attempt_id ?? ''),
            'order_no' => $row->order_no ?? null,
            'scale_code' => (string) ($row->scale_code ?? ''),
            'pack_id' => (string) ($row->pack_id ?? ''),
            'dir_version' => (string) ($row->dir_version ?? ''),
            'scoring_spec_version' => $row->scoring_spec_version ?? null,
            'report_engine_version' => (string) ($row->report_engine_version ?? self::REPORT_ENGINE_VERSION),
            'snapshot_version' => (string) ($row->snapshot_version ?? self::SNAPSHOT_VERSION),
            'status' => $this->snapshotStatus($row),
            'last_error' => $row->last_error ?? null,
            'created_at' => (string) ($row->created_at ?? ''),
            'updated_at' => isset($row->updated_at) && $row->updated_at !== null ? (string) $row->updated_at : null,
        ];

        $reportJson = $this->decodeJsonColumn($row->report_json ?? null);
        $reportFull = $this->decodeJsonColumn($row->report_full_json ?? null);
        $reportFree = $this->decodeJsonColumn($row->report_free_json ?? null);

        if ($reportFull === []) {
            $reportFull = $reportJson;
        }
        if ($reportFree === []) {
            $reportFree = $reportJson;
        }

        $payload['report_json'] = $reportJson;
        $payload['report_full_json'] = $reportFull;
        $payload['report_free_json'] = $reportFree;

        return $payload;
    }

    private function decodeJsonColumn(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function snapshotWriteQuery(int $orgId, string $attemptId): \Illuminate\Database\Query\Builder
    {
        return DB::table('report_snapshots')
            ->where('attempt_id', $attemptId)
            ->where('org_id', $orgId);
    }

    private function findSnapshotRow(int $orgId, string $attemptId): ?object
    {
        $row = DB::table('report_snapshots')
            ->where('attempt_id', $attemptId)
            ->where('org_id', $orgId)
            ->first();

        return $row ?: null;
    }

    private function snapshotStatus(?object $row): string
    {
        $status = strtolower(trim((string) ($row->status ?? 'ready')));

        return in_array($status, ['pending', 'ready', 'failed'], true) ? $status : 'ready';
    }

    private function badRequest(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ];
    }

    private function notFound(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ];
    }

    private function serverError(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
            'status' => 500,
        ];
    }
}
