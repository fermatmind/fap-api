<?php

declare(strict_types=1);

namespace App\Services\Attempts;

use App\Services\Storage\ArtifactStore;
use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;

final class AttemptDataLifecycleService
{
    public function __construct(
        private readonly ArtifactStore $artifactStore,
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
        $artifactResidualAudit = $this->inspectArtifactResidualAudit($attempt, $result);

        $counts = [
            'results_deleted' => 0,
            'report_snapshots_deleted' => 0,
            'shares_deleted' => 0,
            'report_jobs_deleted' => 0,
            'attempt_answer_sets_deleted' => 0,
            'attempt_answer_rows_deleted' => 0,
            'benefit_grants_revoked' => 0,
            'attempts_redacted' => 0,
        ];

        DB::transaction(function () use ($attemptId, $orgId, &$counts, $context, $artifactResidualAudit): void {
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
     * @param  object  $attempt
     * @param  object|null  $result
     * @return array<string,mixed>
     */
    private function inspectArtifactResidualAudit(object $attempt, ?object $result): array
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
            'remote_state' => 'remote_state_unknown',
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
}
