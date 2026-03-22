<?php

declare(strict_types=1);

namespace App\Services\Attempts;

use App\Services\Storage\ArtifactPurgeService;
use App\Services\Storage\ArtifactStore;
use App\Services\Storage\LegalHoldService;
use App\Services\Storage\RetentionPolicyResolver;
use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;

final class AttemptDataLifecycleService
{
    public function __construct(
        private readonly ArtifactStore $artifactStore,
        private readonly ArtifactPurgeService $artifactPurgeService,
        private readonly LegalHoldService $legalHoldService,
        private readonly RetentionPolicyResolver $retentionPolicyResolver,
    ) {}

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function purgeAttempt(string $attemptId, int $orgId, array $context = []): array
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return [
                'ok' => false,
                'error' => 'ATTEMPT_ID_REQUIRED',
            ];
        }

        $attempt = DB::table('attempts')
            ->where('id', $attemptId)
            ->where('org_id', $orgId)
            ->first();
        if ($attempt === null) {
            return [
                'ok' => false,
                'error' => 'ATTEMPT_NOT_FOUND',
            ];
        }

        $result = DB::table('results')
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->first();

        if (! $this->fullArtifactPurgeEnabled()) {
            return $this->purgeAttemptLegacy($attemptId, $orgId, $context, $attempt, $result);
        }

        $this->retentionPolicyResolver->ensureAttemptBinding($attemptId, 'attempt_data_lifecycle');
        $blockedReason = $this->legalHoldService->blockedReasonCodeForAttempt($attemptId);
        if ($blockedReason !== null) {
            $artifactResidualAudit = $this->inspectArtifactResidualAudit($attempt, $result, [
                'remote_state' => 'remote_purge_not_attempted_due_to_hold',
            ]);

            $counts = $this->initialCounts() + [
                'report_artifact_versions_deleted' => 0,
                'report_artifact_slots_deleted' => 0,
                'report_artifact_postures_deleted' => 0,
                'artifact_reconcile_cases_deleted' => 0,
                'unified_access_projections_deleted' => 0,
                'artifact_purge_attempted' => 0,
                'artifact_purge_failed' => 0,
            ];

            $this->recordLifecycleRequest(
                $attemptId,
                $orgId,
                $context,
                $counts,
                $artifactResidualAudit,
                'blocked',
                'blocked',
                [
                    'blocked_reason_code' => $blockedReason,
                ]
            );

            return [
                'ok' => false,
                'error' => 'LEGAL_HOLD_ACTIVE',
                'blocked_reason_code' => $blockedReason,
                'attempt_id' => $attemptId,
                'org_id' => $orgId,
                'counts' => $counts,
                'artifact_residual_audit' => $artifactResidualAudit,
            ];
        }

        $counts = $this->initialCounts() + [
            'report_artifact_versions_deleted' => 0,
            'report_artifact_slots_deleted' => 0,
            'report_artifact_postures_deleted' => 0,
            'artifact_reconcile_cases_deleted' => 0,
            'unified_access_projections_deleted' => 0,
            'artifact_purge_attempted' => 0,
            'artifact_purge_failed' => 0,
        ];
        $artifactDescriptor = $this->artifactPurgeService->describeAttemptArtifacts($attempt, $result);

        DB::transaction(function () use ($attemptId, $orgId, &$counts, $context): void {
            $this->purgeRelationalAttemptData($attemptId, $orgId, $counts, $context);

            if (SchemaBaseline::hasTable('report_artifact_postures')) {
                $counts['report_artifact_postures_deleted'] = DB::table('report_artifact_postures')
                    ->where('attempt_id', $attemptId)
                    ->delete();
            }

            if (SchemaBaseline::hasTable('artifact_reconcile_cases')) {
                $counts['artifact_reconcile_cases_deleted'] = DB::table('artifact_reconcile_cases')
                    ->where('attempt_id', $attemptId)
                    ->delete();
            }

            if (SchemaBaseline::hasTable('report_artifact_versions') && SchemaBaseline::hasTable('report_artifact_slots')) {
                $slotIds = DB::table('report_artifact_slots')
                    ->where('attempt_id', $attemptId)
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();

                if ($slotIds !== []) {
                    $counts['report_artifact_versions_deleted'] = DB::table('report_artifact_versions')
                        ->whereIn('artifact_slot_id', $slotIds)
                        ->delete();
                }

                $counts['report_artifact_slots_deleted'] = DB::table('report_artifact_slots')
                    ->where('attempt_id', $attemptId)
                    ->delete();
            }

            if (SchemaBaseline::hasTable('unified_access_projections')) {
                $counts['unified_access_projections_deleted'] = DB::table('unified_access_projections')
                    ->where('attempt_id', $attemptId)
                    ->delete();
            }
        });

        $counts['artifact_purge_attempted'] = 1;
        $purge = $this->artifactPurgeService->purgeAttemptArtifacts($attempt, $result, $context + [
            'artifact_descriptor' => $artifactDescriptor,
        ]);
        if (($purge['ok'] ?? false) !== true) {
            $counts['artifact_purge_failed'] = 1;
            $artifactResidualAudit = is_array($purge['artifact_residual_audit'] ?? null)
                ? $purge['artifact_residual_audit']
                : $this->inspectArtifactResidualAudit($attempt, $result);
            $this->recordLifecycleRequest(
                $attemptId,
                $orgId,
                $context,
                $counts,
                $artifactResidualAudit,
                'failed',
                'failed',
                [
                    'artifact_purge_error' => $purge['error'] ?? 'ARTIFACT_PURGE_FAILED',
                    'blocked_reason_code' => $purge['blocked_reason_code'] ?? null,
                ]
            );

            return [
                'ok' => false,
                'error' => (string) ($purge['error'] ?? 'ARTIFACT_PURGE_FAILED'),
                'blocked_reason_code' => $purge['blocked_reason_code'] ?? null,
                'attempt_id' => $attemptId,
                'org_id' => $orgId,
                'counts' => $counts,
                'artifact_residual_audit' => $artifactResidualAudit,
            ];
        }

        $artifactResidualAudit = is_array($purge['artifact_residual_audit'] ?? null)
            ? $purge['artifact_residual_audit']
            : $this->inspectArtifactResidualAudit($attempt, $result);
        $counts = array_merge($counts, is_array($purge['counts'] ?? null) ? $purge['counts'] : []);

        $status = (string) ($purge['job_status'] ?? 'executed');
        $requestStatus = $status === 'partial_failure' ? 'failed' : 'done';
        $requestResult = $status === 'partial_failure' ? 'failed' : 'success';

        $this->recordLifecycleRequest(
            $attemptId,
            $orgId,
            $context,
            $counts,
            $artifactResidualAudit,
            $requestStatus,
            $requestResult,
            [
                'artifact_purge' => $purge['purge_result'] ?? null,
            ]
        );

        return [
            'ok' => true,
            'attempt_id' => $attemptId,
            'org_id' => $orgId,
            'counts' => $counts,
            'artifact_residual_audit' => $artifactResidualAudit,
            'artifact_purge' => $purge['purge_result'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  object  $attempt
     * @param  object|null  $result
     * @return array<string,mixed>
     */
    private function purgeAttemptLegacy(string $attemptId, int $orgId, array $context, object $attempt, ?object $result): array
    {
        $artifactResidualAudit = $this->inspectArtifactResidualAudit($attempt, $result);
        $counts = $this->initialCounts();

        DB::transaction(function () use ($attemptId, $orgId, &$counts, $context, $artifactResidualAudit): void {
            $this->purgeRelationalAttemptData($attemptId, $orgId, $counts, $context);

            if (SchemaBaseline::hasTable('data_lifecycle_requests')) {
                DB::table('data_lifecycle_requests')->insert([
                    'org_id' => $orgId,
                    'request_type' => 'attempt_purge',
                    'status' => 'done',
                    'requested_by_admin_user_id' => null,
                    'approved_by_admin_user_id' => null,
                    'subject_ref' => $attemptId,
                    'reason' => (string) ($context['reason'] ?? 'user_request'),
                    'result' => 'success',
                    'payload_json' => json_encode([
                        'attempt_id' => $attemptId,
                        'scale_code' => (string) ($context['scale_code'] ?? ''),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'result_json' => json_encode(array_merge($counts, [
                        'artifact_residual_audit' => $artifactResidualAudit,
                    ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'approved_at' => now(),
                    'executed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return [
            'ok' => true,
            'attempt_id' => $attemptId,
            'org_id' => $orgId,
            'counts' => $counts,
            'artifact_residual_audit' => $artifactResidualAudit,
        ];
    }

    /**
     * @param  array<string,mixed>  $counts
     * @param  array<string,mixed>  $context
     */
    private function purgeRelationalAttemptData(string $attemptId, int $orgId, array &$counts, array $context): void
    {
        if (SchemaBaseline::hasTable('attempt_answer_sets')) {
            $counts['attempt_answer_sets_deleted'] = DB::table('attempt_answer_sets')
                ->where('org_id', $orgId)
                ->where('attempt_id', $attemptId)
                ->delete();
        }

        if (SchemaBaseline::hasTable('attempt_answer_rows')) {
            $counts['attempt_answer_rows_deleted'] = DB::table('attempt_answer_rows')
                ->where('org_id', $orgId)
                ->where('attempt_id', $attemptId)
                ->delete();
        }

        $counts['results_deleted'] = DB::table('results')
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->delete();

        $counts['report_snapshots_deleted'] = DB::table('report_snapshots')
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->delete();

        if (SchemaBaseline::hasTable('shares')) {
            $counts['shares_deleted'] = DB::table('shares')
                ->where('attempt_id', $attemptId)
                ->delete();
        }

        if (SchemaBaseline::hasTable('report_jobs')) {
            $counts['report_jobs_deleted'] = DB::table('report_jobs')
                ->where('attempt_id', $attemptId)
                ->delete();
        }

        if (SchemaBaseline::hasTable('benefit_grants')) {
            $counts['benefit_grants_revoked'] = DB::table('benefit_grants')
                ->where('org_id', $orgId)
                ->where('attempt_id', $attemptId)
                ->where('status', 'active')
                ->update([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        $updates = [
            'answers_hash' => null,
            'answers_storage_path' => null,
            'result_json' => null,
            'type_code' => null,
            'updated_at' => now(),
        ];

        if (SchemaBaseline::hasColumn('attempts', 'answers_json')) {
            $updates['answers_json'] = json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (SchemaBaseline::hasColumn('attempts', 'answers_summary_json')) {
            $updates['answers_summary_json'] = json_encode([
                'stage' => 'purged',
                'reason' => (string) ($context['reason'] ?? 'user_request'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (SchemaBaseline::hasColumn('attempts', 'calculation_snapshot_json')) {
            $updates['calculation_snapshot_json'] = json_encode([
                'purged' => true,
                'purged_at' => now()->toIso8601String(),
                'reason' => (string) ($context['reason'] ?? 'user_request'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (SchemaBaseline::hasColumn('attempts', 'norm_version')) {
            $updates['norm_version'] = null;
        }

        $counts['attempts_redacted'] = DB::table('attempts')
            ->where('id', $attemptId)
            ->where('org_id', $orgId)
            ->update($updates);
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $counts
     * @param  array<string,mixed>  $artifactResidualAudit
     * @param  array<string,mixed>  $extra
     */
    private function recordLifecycleRequest(
        string $attemptId,
        int $orgId,
        array $context,
        array $counts,
        array $artifactResidualAudit,
        string $status,
        string $result,
        array $extra = []
    ): void {
        if (! SchemaBaseline::hasTable('data_lifecycle_requests')) {
            return;
        }

        DB::table('data_lifecycle_requests')->insert([
            'org_id' => $orgId,
            'request_type' => 'attempt_purge',
            'status' => $status,
            'requested_by_admin_user_id' => $this->nullablePositiveInt($context['actor_user_id'] ?? null),
            'approved_by_admin_user_id' => $this->nullablePositiveInt($context['actor_user_id'] ?? null),
            'subject_ref' => $attemptId,
            'reason' => (string) ($context['reason'] ?? 'user_request'),
            'result' => $result,
            'payload_json' => json_encode([
                'attempt_id' => $attemptId,
                'scale_code' => (string) ($context['scale_code'] ?? ''),
                'request_id' => (string) ($context['request_id'] ?? ''),
                'task_id' => (string) ($context['task_id'] ?? ''),
                'reference_id' => (string) ($context['reference_id'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result_json' => json_encode(array_merge($counts, [
                'artifact_residual_audit' => $artifactResidualAudit,
            ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'approved_at' => now(),
            'executed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string,int>
     */
    private function initialCounts(): array
    {
        return [
            'results_deleted' => 0,
            'report_snapshots_deleted' => 0,
            'shares_deleted' => 0,
            'report_jobs_deleted' => 0,
            'attempt_answer_sets_deleted' => 0,
            'attempt_answer_rows_deleted' => 0,
            'benefit_grants_revoked' => 0,
            'attempts_redacted' => 0,
        ];
    }

    /**
     * @param  object  $attempt
     * @param  object|null  $result
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function inspectArtifactResidualAudit(object $attempt, ?object $result, array $extra = []): array
    {
        $attemptId = trim((string) ($attempt->id ?? ''));
        $scaleCode = trim((string) ($attempt->scale_code ?? ''));
        $reportPath = $this->artifactStore->reportCanonicalPath($scaleCode, $attemptId);
        $reportExists = $this->artifactStore->exists($reportPath);

        $manifestHash = $this->resolveManifestHash($attempt, $result);
        $variantsChecked = ['free', 'full'];
        $pdfPaths = [];
        $pdfExists = false;
        foreach ($variantsChecked as $variant) {
            $path = $this->artifactStore->pdfCanonicalPath($scaleCode, $attemptId, $manifestHash, $variant);
            $pdfPaths[$variant] = $path;
            if ($this->artifactStore->exists($path)) {
                $pdfExists = true;
            }
        }

        $state = match (true) {
            $reportExists && $pdfExists => 'residual_report_json_and_pdf_found',
            $reportExists => 'residual_report_json_found',
            $pdfExists => 'residual_pdf_found',
            default => 'no_residual_found',
        };

        return [
            'state' => $state,
            'remote_state' => (string) ($extra['remote_state'] ?? 'remote_state_unknown'),
            'attempt_id' => $attemptId,
            'org_id' => (int) ($attempt->org_id ?? 0),
            'scale_code' => $scaleCode,
            'report' => [
                'path' => $reportPath,
                'exists' => $reportExists,
            ],
            'pdf' => [
                'manifest_hash' => $manifestHash,
                'paths' => $pdfPaths,
                'exists' => $pdfExists,
                'variants_checked' => $variantsChecked,
            ],
            'purge_result' => $extra['purge_result'] ?? null,
        ];
    }

    /**
     * @param  object  $attempt
     * @param  object|null  $result
     */
    private function resolveManifestHash(object $attempt, ?object $result = null): string
    {
        $summary = $this->decodeArray($attempt->answers_summary_json ?? null);
        $meta = is_array($summary['meta'] ?? null) ? $summary['meta'] : [];
        $hash = trim((string) ($meta['pack_release_manifest_hash'] ?? ''));
        if ($hash !== '') {
            return $hash;
        }

        $resultPayload = $this->decodeArray($result?->result_json ?? null);
        $hash = trim((string) (
            data_get($resultPayload, 'version_snapshot.content_manifest_hash')
            ?? data_get($resultPayload, 'normed_json.version_snapshot.content_manifest_hash')
            ?? data_get($resultPayload, 'content_manifest_hash')
            ?? ''
        ));

        return $hash !== '' ? $hash : 'nohash';
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function fullArtifactPurgeEnabled(): bool
    {
        return (bool) config('storage_rollout.dsar_artifact_purge_enabled', false);
    }
}
