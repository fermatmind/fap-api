<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Support\Logging\SensitiveDiagnosticRedactor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AttemptChainAuditService
{
    /**
     * @return array{
     *     ok:bool,
     *     timestamp:string,
     *     selection:array{attempt_fingerprint:?string,window_hours:int,limit:int,pending_timeout_minutes:int},
     *     inspected_count:int,
     *     summary:array{finding_total:int,critical_total:int,warning_total:int,by_issue_code:array<string,int>},
     *     inspections:list<array<string,mixed>>
     * }
     */
    public function audit(?string $attemptId, int $windowHours, int $limit, int $pendingTimeoutMinutes): array
    {
        $attemptId = $this->normalizeString($attemptId);
        $windowHours = max(1, $windowHours);
        $limit = max(1, $limit);
        $pendingTimeoutMinutes = max(1, $pendingTimeoutMinutes);

        $inspections = $attemptId !== null
            ? [$this->inspectAttemptId($attemptId, $pendingTimeoutMinutes)]
            : $this->inspectRecentWindow($windowHours, $limit, $pendingTimeoutMinutes);

        return [
            'ok' => true,
            'timestamp' => now()->toISOString(),
            'selection' => [
                'attempt_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($attemptId),
                'window_hours' => $windowHours,
                'limit' => $limit,
                'pending_timeout_minutes' => $pendingTimeoutMinutes,
            ],
            'inspected_count' => count($inspections),
            'summary' => $this->summarize($inspections),
            'inspections' => $inspections,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function inspectRecentWindow(int $windowHours, int $limit, int $pendingTimeoutMinutes): array
    {
        $windowStart = now()->subHours($windowHours);
        $inspections = [];

        if (Schema::hasTable('attempts')) {
            $attemptIds = DB::table('attempts')
                ->where(function ($query) use ($windowStart): void {
                    $query->where('created_at', '>=', $windowStart)
                        ->orWhere('updated_at', '>=', $windowStart)
                        ->orWhere('submitted_at', '>=', $windowStart);
                })
                ->orderByRaw('COALESCE(submitted_at, updated_at, created_at) DESC')
                ->limit($limit)
                ->pluck('id')
                ->filter(static fn ($value): bool => is_string($value) && trim($value) !== '')
                ->map(static fn (string $value): string => trim($value))
                ->values()
                ->all();

            foreach ($attemptIds as $attemptId) {
                $inspections[] = $this->inspectAttemptId($attemptId, $pendingTimeoutMinutes);
            }
        }

        return array_merge(
            $inspections,
            $this->inspectOrphanSubmissions($windowStart, $limit),
            $this->inspectOrphanResults($windowStart, $limit),
            $this->inspectOrphanProjections($windowStart, $limit),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function inspectAttemptId(string $attemptId, int $pendingTimeoutMinutes): array
    {
        $attempt = $this->findAttempt($attemptId);
        $submission = $this->findLatestSubmission($attemptId);
        $result = $this->findResult($attemptId);
        $projection = $this->findProjection($attemptId);
        $inviteDiagnostic = $this->inviteUnlockDiagnosticPayload($attempt, $projection);

        $findings = [];

        if ($attempt === null && $submission === null && $result === null && $projection === null) {
            $findings[] = $this->finding(
                'attempt_chain_absent',
                'critical',
                'attempt/result/submission/projection are all missing for this attempt id.'
            );
        }

        if ($attempt === null && $submission !== null) {
            $findings[] = $this->finding(
                'orphan_submission_without_attempt',
                'critical',
                'attempt_submission exists but the parent attempt row is missing.'
            );
        }

        if ($attempt === null && $result !== null) {
            $findings[] = $this->finding(
                'orphan_result_without_attempt',
                'critical',
                'result exists but the parent attempt row is missing.'
            );
        }

        if ($attempt === null && $projection !== null) {
            $findings[] = $this->finding(
                'orphan_projection_without_attempt',
                'critical',
                'unified_access_projection exists but the parent attempt row is missing.'
            );
        }

        if ($attempt !== null && $submission !== null) {
            $attemptOrgId = (int) ($attempt->org_id ?? 0);
            $submissionOrgId = (int) ($submission->org_id ?? 0);
            if ($submissionOrgId !== $attemptOrgId) {
                $findings[] = $this->finding(
                    'submission_org_mismatch',
                    'critical',
                    sprintf('attempt org_id=%d but latest submission org_id=%d.', $attemptOrgId, $submissionOrgId)
                );
            }
        }

        if ($attempt !== null && $result !== null) {
            $attemptOrgId = (int) ($attempt->org_id ?? 0);
            $resultOrgId = (int) ($result->org_id ?? 0);
            if ($resultOrgId !== $attemptOrgId) {
                $findings[] = $this->finding(
                    'result_org_mismatch',
                    'critical',
                    sprintf('attempt org_id=%d but result org_id=%d.', $attemptOrgId, $resultOrgId)
                );
            }
        }

        $submittedAt = $this->parseTimestamp($attempt?->submitted_at ?? null);
        $submissionState = strtolower((string) ($submission->state ?? ''));

        if ($attempt !== null && $submittedAt instanceof Carbon && $submission === null && $result === null) {
            $findings[] = $this->finding(
                'submission_missing_for_submitted_attempt',
                'critical',
                'attempt was submitted but there is no submission record and no result row.'
            );
        }

        if ($submission !== null && in_array($submissionState, ['pending', 'running'], true)) {
            $updatedAt = $this->parseTimestamp($submission->updated_at ?? $submission->created_at ?? null);
            if ($updatedAt instanceof Carbon) {
                $ageMinutes = $updatedAt->diffInMinutes(now());
                if ($ageMinutes >= $pendingTimeoutMinutes) {
                    $findings[] = $this->finding(
                        'submission_stuck_pending',
                        'warning',
                        sprintf('latest submission has been %s for %d minutes.', $submissionState, $ageMinutes)
                    );
                }
            }
        }

        if ($submission !== null && $submissionState === 'succeeded' && $result === null) {
            $findings[] = $this->finding(
                'result_missing_after_submission_success',
                'critical',
                'latest submission succeeded but no result row exists.'
            );
        }

        if ($result !== null && $projection === null) {
            $findings[] = $this->finding(
                'projection_missing_after_result',
                'warning',
                'result exists but unified_access_projection is missing.'
            );
        }

        return [
            'attempt_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($attemptId),
            'source' => 'attempt',
            'attempt' => $this->attemptPayload($attempt),
            'submission' => $this->submissionPayload($submission),
            'result' => $this->resultPayload($result),
            'projection' => $this->projectionPayload($projection),
            'invite_unlock_diagnostic_v1' => $inviteDiagnostic,
            'findings' => $findings,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function inspectOrphanSubmissions(Carbon $windowStart, int $limit): array
    {
        if (! Schema::hasTable('attempt_submissions') || ! Schema::hasTable('attempts')) {
            return [];
        }

        $rows = DB::table('attempt_submissions')
            ->leftJoin('attempts', 'attempts.id', '=', 'attempt_submissions.attempt_id')
            ->whereNull('attempts.id')
            ->where(function ($query) use ($windowStart): void {
                $query->where('attempt_submissions.created_at', '>=', $windowStart)
                    ->orWhere('attempt_submissions.updated_at', '>=', $windowStart);
            })
            ->select('attempt_submissions.*')
            ->orderByDesc('attempt_submissions.updated_at')
            ->limit($limit)
            ->get();

        $inspections = [];
        foreach ($rows as $row) {
            $attemptId = trim((string) ($row->attempt_id ?? ''));
            $inspections[] = [
                'attempt_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($attemptId),
                'source' => 'orphan_submission',
                'attempt' => $this->attemptPayload(null),
                'submission' => $this->submissionPayload($row),
                'result' => $this->resultPayload($this->findResult($attemptId)),
                'projection' => $this->projectionPayload($this->findProjection($attemptId)),
                'invite_unlock_diagnostic_v1' => null,
                'findings' => [
                    $this->finding(
                        'orphan_submission_without_attempt',
                        'critical',
                        'attempt_submission exists in the recent window but the parent attempt row is missing.'
                    ),
                ],
            ];
        }

        return $inspections;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function inspectOrphanResults(Carbon $windowStart, int $limit): array
    {
        if (! Schema::hasTable('results') || ! Schema::hasTable('attempts')) {
            return [];
        }

        $rows = DB::table('results')
            ->leftJoin('attempts', 'attempts.id', '=', 'results.attempt_id')
            ->whereNull('attempts.id')
            ->where(function ($query) use ($windowStart): void {
                $query->where('results.created_at', '>=', $windowStart)
                    ->orWhere('results.updated_at', '>=', $windowStart)
                    ->orWhere('results.computed_at', '>=', $windowStart);
            })
            ->select('results.*')
            ->orderByDesc('results.updated_at')
            ->limit($limit)
            ->get();

        $inspections = [];
        foreach ($rows as $row) {
            $attemptId = trim((string) ($row->attempt_id ?? ''));
            $inspections[] = [
                'attempt_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($attemptId),
                'source' => 'orphan_result',
                'attempt' => $this->attemptPayload(null),
                'submission' => $this->submissionPayload($this->findLatestSubmission($attemptId)),
                'result' => $this->resultPayload($row),
                'projection' => $this->projectionPayload($this->findProjection($attemptId)),
                'invite_unlock_diagnostic_v1' => null,
                'findings' => [
                    $this->finding(
                        'orphan_result_without_attempt',
                        'critical',
                        'result exists in the recent window but the parent attempt row is missing.'
                    ),
                ],
            ];
        }

        return $inspections;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function inspectOrphanProjections(Carbon $windowStart, int $limit): array
    {
        if (! Schema::hasTable('unified_access_projections') || ! Schema::hasTable('attempts')) {
            return [];
        }

        $rows = DB::table('unified_access_projections')
            ->leftJoin('attempts', 'attempts.id', '=', 'unified_access_projections.attempt_id')
            ->whereNull('attempts.id')
            ->where(function ($query) use ($windowStart): void {
                $query->where('unified_access_projections.created_at', '>=', $windowStart)
                    ->orWhere('unified_access_projections.updated_at', '>=', $windowStart)
                    ->orWhere('unified_access_projections.refreshed_at', '>=', $windowStart);
            })
            ->select('unified_access_projections.*')
            ->orderByDesc('unified_access_projections.updated_at')
            ->limit($limit)
            ->get();

        $inspections = [];
        foreach ($rows as $row) {
            $attemptId = trim((string) ($row->attempt_id ?? ''));
            $inspections[] = [
                'attempt_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($attemptId),
                'source' => 'orphan_projection',
                'attempt' => $this->attemptPayload(null),
                'submission' => $this->submissionPayload($this->findLatestSubmission($attemptId)),
                'result' => $this->resultPayload($this->findResult($attemptId)),
                'projection' => $this->projectionPayload($row),
                'invite_unlock_diagnostic_v1' => null,
                'findings' => [
                    $this->finding(
                        'orphan_projection_without_attempt',
                        'critical',
                        'unified_access_projection exists in the recent window but the parent attempt row is missing.'
                    ),
                ],
            ];
        }

        return $inspections;
    }

    /**
     * @param  list<array<string,mixed>>  $inspections
     * @return array{finding_total:int,critical_total:int,warning_total:int,by_issue_code:array<string,int>}
     */
    private function summarize(array $inspections): array
    {
        $byIssueCode = [];
        $criticalTotal = 0;
        $warningTotal = 0;

        foreach ($inspections as $inspection) {
            $findings = is_array($inspection['findings'] ?? null) ? $inspection['findings'] : [];
            foreach ($findings as $finding) {
                if (! is_array($finding)) {
                    continue;
                }

                $issueCode = trim((string) ($finding['issue_code'] ?? ''));
                if ($issueCode !== '') {
                    $byIssueCode[$issueCode] = ($byIssueCode[$issueCode] ?? 0) + 1;
                }

                $severity = strtolower(trim((string) ($finding['severity'] ?? 'warning')));
                if ($severity === 'critical') {
                    $criticalTotal++;
                } else {
                    $warningTotal++;
                }
            }
        }

        ksort($byIssueCode);

        return [
            'finding_total' => $criticalTotal + $warningTotal,
            'critical_total' => $criticalTotal,
            'warning_total' => $warningTotal,
            'by_issue_code' => $byIssueCode,
        ];
    }

    /**
     * @return array{issue_code:string,severity:string,message:string}
     */
    private function finding(string $issueCode, string $severity, string $message): array
    {
        return [
            'issue_code' => $issueCode,
            'severity' => $severity,
            'message' => $message,
        ];
    }

    private function findAttempt(string $attemptId): ?object
    {
        if (! Schema::hasTable('attempts')) {
            return null;
        }

        return DB::table('attempts')
            ->where('id', trim($attemptId))
            ->first();
    }

    private function findLatestSubmission(string $attemptId): ?object
    {
        if (! Schema::hasTable('attempt_submissions')) {
            return null;
        }

        return DB::table('attempt_submissions')
            ->where('attempt_id', trim($attemptId))
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->first();
    }

    private function findResult(string $attemptId): ?object
    {
        if (! Schema::hasTable('results')) {
            return null;
        }

        return DB::table('results')
            ->where('attempt_id', trim($attemptId))
            ->orderByDesc('computed_at')
            ->orderByDesc('updated_at')
            ->first();
    }

    private function findProjection(string $attemptId): ?object
    {
        if (! Schema::hasTable('unified_access_projections')) {
            return null;
        }

        return DB::table('unified_access_projections')
            ->where('attempt_id', trim($attemptId))
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    private function attemptPayload(?object $row): array
    {
        return [
            'present' => $row !== null,
            'org_id' => $this->nullableInt($row?->org_id ?? null),
            'anon_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($this->normalizeString($row?->anon_id ?? null)),
            'user_id' => $this->normalizeString($row?->user_id ?? null),
            'scale_code' => $this->normalizeString($row?->scale_code ?? null),
            'submitted_at' => $this->formatTimestamp($row?->submitted_at ?? null),
            'created_at' => $this->formatTimestamp($row?->created_at ?? null),
            'updated_at' => $this->formatTimestamp($row?->updated_at ?? null),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function submissionPayload(?object $row): array
    {
        return [
            'present' => $row !== null,
            'submission_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($this->normalizeString($row?->id ?? null)),
            'org_id' => $this->nullableInt($row?->org_id ?? null),
            'state' => $this->normalizeString($row?->state ?? null),
            'mode' => $this->normalizeString($row?->mode ?? null),
            'actor_user_id' => $this->normalizeString($row?->actor_user_id ?? null),
            'actor_anon_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($this->normalizeString($row?->actor_anon_id ?? null)),
            'error_code' => $this->normalizeString($row?->error_code ?? null),
            'updated_at' => $this->formatTimestamp($row?->updated_at ?? null),
            'created_at' => $this->formatTimestamp($row?->created_at ?? null),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function resultPayload(?object $row): array
    {
        return [
            'present' => $row !== null,
            'id' => $this->normalizeString($row?->id ?? null),
            'org_id' => $this->nullableInt($row?->org_id ?? null),
            'scale_code' => $this->normalizeString($row?->scale_code ?? null),
            'type_code' => $this->normalizeString($row?->type_code ?? null),
            'computed_at' => $this->formatTimestamp($row?->computed_at ?? null),
            'created_at' => $this->formatTimestamp($row?->created_at ?? null),
            'updated_at' => $this->formatTimestamp($row?->updated_at ?? null),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function projectionPayload(?object $row): array
    {
        return [
            'present' => $row !== null,
            'access_state' => $this->normalizeString($row?->access_state ?? null),
            'report_state' => $this->normalizeString($row?->report_state ?? null),
            'pdf_state' => $this->normalizeString($row?->pdf_state ?? null),
            'reason_code' => $this->normalizeString($row?->reason_code ?? null),
            'produced_at' => $this->formatTimestamp($row?->produced_at ?? null),
            'refreshed_at' => $this->formatTimestamp($row?->refreshed_at ?? null),
            'created_at' => $this->formatTimestamp($row?->created_at ?? null),
            'updated_at' => $this->formatTimestamp($row?->updated_at ?? null),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function inviteUnlockDiagnosticPayload(?object $attempt, ?object $projection): ?array
    {
        if ($attempt === null || ! Schema::hasTable('attempt_invite_unlocks')) {
            return null;
        }

        $attemptId = $this->normalizeString($attempt->id ?? null);
        $orgId = $this->nullableInt($attempt->org_id ?? null);
        if ($attemptId === null || $orgId === null) {
            return null;
        }

        $invite = DB::table('attempt_invite_unlocks')
            ->where('target_org_id', $orgId)
            ->where('target_attempt_id', $attemptId)
            ->first();

        $payloadJson = null;
        if (is_string($projection?->payload_json ?? null)) {
            $decoded = json_decode((string) $projection->payload_json, true);
            $payloadJson = is_array($decoded) ? $decoded : null;
        } elseif (is_array($projection?->payload_json ?? null)) {
            $payloadJson = $projection->payload_json;
        }

        $unlockStage = strtolower(trim((string) data_get($payloadJson, 'unlock_stage', 'locked')));
        if (! in_array($unlockStage, ['locked', 'partial', 'full'], true)) {
            $unlockStage = 'locked';
        }
        $unlockSource = strtolower(trim((string) data_get($payloadJson, 'unlock_source', 'none')));
        if (! in_array($unlockSource, ['none', 'invite', 'payment', 'mixed'], true)) {
            $unlockSource = 'none';
        }

        if (! $invite) {
            return [
                'has_invite' => false,
                'invite_fingerprint' => null,
                'invite_code_fingerprint' => null,
                'invite_status' => null,
                'completed_invitees' => 0,
                'required_invitees' => 2,
                'projection_unlock_stage' => $unlockStage,
                'projection_unlock_source' => $unlockSource,
                'grants' => [],
                'counted_completions' => [],
                'rejected_completions' => [],
                'rejections_by_status' => [],
            ];
        }

        $inviteId = $this->normalizeString($invite->id ?? null);
        $countedRows = collect();
        $rejectedRows = collect();
        $rejectionsByStatus = [];
        if ($inviteId !== null && Schema::hasTable('attempt_invite_unlock_completions')) {
            $countedRows = DB::table('attempt_invite_unlock_completions')
                ->where('invite_id', $inviteId)
                ->where('counted', true)
                ->orderBy('created_at')
                ->get([
                    'id',
                    'invitee_attempt_id',
                    'invitee_identity_key',
                    'qualified_reason',
                    'qualification_status',
                    'created_at',
                ]);

            $rejectedRows = DB::table('attempt_invite_unlock_completions')
                ->where('invite_id', $inviteId)
                ->where('counted', false)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get([
                    'id',
                    'invitee_attempt_id',
                    'invitee_identity_key',
                    'qualified_reason',
                    'qualification_status',
                    'created_at',
                ]);

            $grouped = DB::table('attempt_invite_unlock_completions')
                ->where('invite_id', $inviteId)
                ->where('counted', false)
                ->selectRaw('qualification_status, COUNT(*) as total')
                ->groupBy('qualification_status')
                ->get();
            foreach ($grouped as $group) {
                $status = $this->normalizeString($group->qualification_status ?? null);
                if ($status === null) {
                    continue;
                }
                $rejectionsByStatus[$status] = (int) ($group->total ?? 0);
            }
        }

        $grants = [];
        if (Schema::hasTable('benefit_grants')) {
            $grantRows = DB::table('benefit_grants')
                ->where('org_id', $orgId)
                ->where('attempt_id', $attemptId)
                ->where('status', 'active')
                ->where('benefit_type', 'report_unlock')
                ->orderByDesc('created_at')
                ->get(['benefit_code', 'order_no', 'meta_json', 'created_at']);
            foreach ($grantRows as $grant) {
                $meta = null;
                if (is_string($grant->meta_json ?? null)) {
                    $decoded = json_decode((string) $grant->meta_json, true);
                    $meta = is_array($decoded) ? $decoded : null;
                } elseif (is_array($grant->meta_json ?? null)) {
                    $meta = $grant->meta_json;
                }

                $grants[] = [
                    'benefit_code' => $this->normalizeString($grant->benefit_code ?? null),
                    'order_no' => $this->normalizeString($grant->order_no ?? null),
                    'granted_via' => $this->normalizeString(data_get($meta, 'granted_via')),
                    'invite_unlock_stage' => $this->normalizeString(data_get($meta, 'invite_unlock_stage')),
                    'created_at' => $this->formatTimestamp($grant->created_at ?? null),
                ];
            }
        }

        return [
            'has_invite' => true,
            'invite_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($inviteId),
            'invite_code_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($this->normalizeString($invite->invite_code ?? null)),
            'invite_status' => $this->normalizeString($invite->status ?? null),
            'completed_invitees' => max(0, (int) ($invite->completed_invitees ?? 0)),
            'required_invitees' => max(1, (int) ($invite->required_invitees ?? 2)),
            'projection_unlock_stage' => $unlockStage,
            'projection_unlock_source' => $unlockSource,
            'grants' => $grants,
            'counted_completions' => $countedRows->map(function (object $row): array {
                return [
                    'completion_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($this->normalizeString($row->id ?? null)),
                    'invitee_attempt_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($this->normalizeString($row->invitee_attempt_id ?? null)),
                    'invitee_identity_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($this->normalizeString($row->invitee_identity_key ?? null)),
                    'qualified_reason' => $this->normalizeString($row->qualified_reason ?? null),
                    'qualification_status' => $this->normalizeString($row->qualification_status ?? null),
                    'created_at' => $this->formatTimestamp($row->created_at ?? null),
                ];
            })->values()->all(),
            'rejected_completions' => $rejectedRows->map(function (object $row): array {
                return [
                    'completion_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($this->normalizeString($row->id ?? null)),
                    'invitee_attempt_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($this->normalizeString($row->invitee_attempt_id ?? null)),
                    'invitee_identity_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($this->normalizeString($row->invitee_identity_key ?? null)),
                    'qualified_reason' => $this->normalizeString($row->qualified_reason ?? null),
                    'qualification_status' => $this->normalizeString($row->qualification_status ?? null),
                    'created_at' => $this->formatTimestamp($row->created_at ?? null),
                ];
            })->values()->all(),
            'rejections_by_status' => $rejectionsByStatus,
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value));
        }

        $normalized = $this->normalizeString($value);
        if ($normalized === null) {
            return null;
        }

        try {
            return Carbon::parse($normalized);
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatTimestamp(mixed $value): ?string
    {
        return $this->parseTimestamp($value)?->toISOString();
    }
}
