<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Jobs\ProcessAttemptSubmissionJob;
use App\Models\Result;
use App\Services\Storage\UnifiedAccessProjectionBackfillService;
use App\Services\Storage\UnifiedAccessProjectionWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class AttemptSubmissionRecoveryService
{
    public function __construct(
        private readonly UnifiedAccessProjectionWriter $projectionWriter,
        private readonly UnifiedAccessProjectionBackfillService $projectionBackfill,
    ) {}

    /**
     * @return array{
     *     ok:bool,
     *     generated_at:string,
     *     scope:array{attempt_id:?string,window_hours:int,limit:int,pending_timeout_minutes:int,repair:bool},
     *     summary:array{finding_total:int,repair_total:int,by_issue_code:array<string,int>,by_repair_code:array<string,int>},
     *     findings:list<array<string,mixed>>,
     *     repairs:list<array<string,mixed>>,
     *     pass:bool
     * }
     */
    public function recover(?string $attemptId, int $windowHours, int $limit, int $pendingTimeoutMinutes, bool $repair): array
    {
        $attemptId = $this->normalizeNullableString($attemptId);
        $windowHours = max(1, $windowHours);
        $limit = max(1, $limit);
        $pendingTimeoutMinutes = max(1, $pendingTimeoutMinutes);

        $findings = [];
        $repairs = [];

        foreach ($this->candidateAttemptIds($attemptId, $windowHours, $limit) as $candidateAttemptId) {
            $submission = $this->latestSubmissionRow($candidateAttemptId);
            $resultExists = Result::query()->where('attempt_id', $candidateAttemptId)->exists();
            $projection = Schema::hasTable('unified_access_projections')
                ? DB::table('unified_access_projections')->where('attempt_id', $candidateAttemptId)->first()
                : null;

            if ($submission === null) {
                if ($resultExists) {
                    $findings[] = $this->finding($candidateAttemptId, 'submission_missing_with_result_present', 'critical', [
                        'result_exists' => true,
                        'projection_exists' => $projection !== null,
                    ]);

                    if ($repair && $projection === null) {
                        $repairResult = $this->refreshProjectionFromArtifacts($candidateAttemptId);
                        if ($repairResult !== null) {
                            $repairs[] = $repairResult;
                        }
                    }
                }

                continue;
            }

            $submissionState = strtolower(trim((string) ($submission->state ?? 'pending')));
            $updatedAt = $this->toCarbon($submission->updated_at ?? null);
            $ageMinutes = $updatedAt?->diffInMinutes(now()) ?? 0;
            $submissionPayload = $this->decodeArray($submission->response_payload_json ?? null);

            if (in_array($submissionState, ['pending', 'running'], true) && $ageMinutes >= $pendingTimeoutMinutes && ! $resultExists) {
                $findings[] = $this->finding($candidateAttemptId, 'submission_stuck_pending', 'critical', [
                    'submission_id' => (string) ($submission->id ?? ''),
                    'submission_state' => $submissionState,
                    'age_minutes' => $ageMinutes,
                    'result_exists' => false,
                ]);

                if ($repair) {
                    $repairResult = $this->restartSubmission((string) ($submission->id ?? ''), 'submission_stuck_pending');
                    if ($repairResult !== null) {
                        $repairs[] = $repairResult;
                    }
                    $projectionRepair = $this->syncProjectionToPending($candidateAttemptId, $submission);
                    if ($projectionRepair !== null) {
                        $repairs[] = $projectionRepair;
                    }
                }
            }

            if ($submissionState === 'succeeded' && ! $resultExists) {
                $findings[] = $this->finding($candidateAttemptId, 'submission_succeeded_result_missing', 'critical', [
                    'submission_id' => (string) ($submission->id ?? ''),
                    'submission_state' => $submissionState,
                    'result_exists' => false,
                ]);

                if ($repair) {
                    $repairResult = $this->restartSubmission((string) ($submission->id ?? ''), 'submission_succeeded_result_missing');
                    if ($repairResult !== null) {
                        $repairs[] = $repairResult;
                    }
                    $projectionRepair = $this->syncProjectionToPending($candidateAttemptId, $submission);
                    if ($projectionRepair !== null) {
                        $repairs[] = $projectionRepair;
                    }
                }
            }

            if (in_array($submissionState, ['pending', 'running'], true) && ! $this->projectionMatchesPending($projection)) {
                $findings[] = $this->finding($candidateAttemptId, 'projection_stale_against_pending_submission', 'warning', [
                    'submission_id' => (string) ($submission->id ?? ''),
                    'submission_state' => $submissionState,
                    'projection_exists' => $projection !== null,
                    'projection_report_state' => $this->normalizeNullableString($projection->report_state ?? null),
                    'projection_reason_code' => $this->normalizeNullableString($projection->reason_code ?? null),
                ]);

                if ($repair) {
                    $projectionRepair = $this->syncProjectionToPending($candidateAttemptId, $submission);
                    if ($projectionRepair !== null) {
                        $repairs[] = $projectionRepair;
                    }
                }
            }

            if ($submissionState === 'failed' && ! $this->projectionMatchesFailed($projection)) {
                $findings[] = $this->finding($candidateAttemptId, 'projection_stale_against_failed_submission', 'warning', [
                    'submission_id' => (string) ($submission->id ?? ''),
                    'submission_state' => $submissionState,
                    'projection_exists' => $projection !== null,
                    'projection_report_state' => $this->normalizeNullableString($projection->report_state ?? null),
                    'projection_reason_code' => $this->normalizeNullableString($projection->reason_code ?? null),
                ]);

                if ($repair) {
                    $projectionRepair = $this->syncProjectionToFailed($candidateAttemptId, $submission, $submissionPayload);
                    if ($projectionRepair !== null) {
                        $repairs[] = $projectionRepair;
                    }
                }
            }

            if ($resultExists && $projection === null) {
                $findings[] = $this->finding($candidateAttemptId, 'projection_missing_after_result', 'warning', [
                    'submission_id' => (string) ($submission->id ?? ''),
                    'submission_state' => $submissionState,
                    'result_exists' => true,
                ]);

                if ($repair) {
                    $repairResult = $this->refreshProjectionFromArtifacts($candidateAttemptId);
                    if ($repairResult !== null) {
                        $repairs[] = $repairResult;
                    }
                }
            }
        }

        $payload = [
            'ok' => true,
            'generated_at' => now()->toIso8601String(),
            'scope' => [
                'attempt_id' => $attemptId,
                'window_hours' => $windowHours,
                'limit' => $limit,
                'pending_timeout_minutes' => $pendingTimeoutMinutes,
                'repair' => $repair,
            ],
            'summary' => [
                'finding_total' => count($findings),
                'repair_total' => count($repairs),
                'by_issue_code' => $this->countByKey($findings, 'issue_code'),
                'by_repair_code' => $this->countByKey($repairs, 'repair_code'),
            ],
            'findings' => $findings,
            'repairs' => $repairs,
            'pass' => $findings === [],
        ];

        if ($findings !== []) {
            Log::warning('ATTEMPT_SUBMISSION_RECOVERY_FINDINGS', [
                'scope' => $payload['scope'],
                'summary' => $payload['summary'],
                'findings' => $findings,
                'repairs' => $repairs,
            ]);
        }

        return $payload;
    }

    public function emitAlert(array $payload): void
    {
        $findingTotal = (int) data_get($payload, 'summary.finding_total', 0);
        $repairTotal = (int) data_get($payload, 'summary.repair_total', 0);
        if ($findingTotal <= 0) {
            return;
        }

        $scopeAttempt = $this->normalizeNullableString(data_get($payload, 'scope.attempt_id'));
        $byIssueCode = is_array(data_get($payload, 'summary.by_issue_code')) ? data_get($payload, 'summary.by_issue_code') : [];
        $issueSummary = [];
        foreach ($byIssueCode as $issueCode => $count) {
            $issueSummary[] = sprintf('%s=%d', (string) $issueCode, (int) $count);
        }

        $message = sprintf(
            '[ops:attempt-submission-recovery] findings=%d repairs=%d scope=%s issues=%s',
            $findingTotal,
            $repairTotal,
            $scopeAttempt ?? 'recent',
            implode(',', $issueSummary)
        );

        OpsAlertService::send($message);
    }

    /**
     * @return list<string>
     */
    private function candidateAttemptIds(?string $attemptId, int $windowHours, int $limit): array
    {
        if ($attemptId !== null) {
            return [$attemptId];
        }

        $windowStart = now()->subHours($windowHours);
        $attemptIds = [];

        if (Schema::hasTable('attempt_submissions')) {
            $rows = DB::table('attempt_submissions')
                ->select('attempt_id')
                ->where('updated_at', '>=', $windowStart)
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->pluck('attempt_id');

            foreach ($rows as $value) {
                $candidate = trim((string) $value);
                if ($candidate !== '') {
                    $attemptIds[$candidate] = true;
                }
            }
        }

        if (Schema::hasTable('results')) {
            $rows = DB::table('results')
                ->select('attempt_id')
                ->where(function ($query) use ($windowStart): void {
                    $query->where('computed_at', '>=', $windowStart)
                        ->orWhere('created_at', '>=', $windowStart);
                })
                ->orderByDesc('computed_at')
                ->limit($limit)
                ->pluck('attempt_id');

            foreach ($rows as $value) {
                $candidate = trim((string) $value);
                if ($candidate !== '') {
                    $attemptIds[$candidate] = true;
                }
            }
        }

        if (Schema::hasTable('unified_access_projections')) {
            $rows = DB::table('unified_access_projections')
                ->select('attempt_id')
                ->where(function ($query) use ($windowStart): void {
                    $query->where('refreshed_at', '>=', $windowStart)
                        ->orWhere('updated_at', '>=', $windowStart);
                })
                ->orderByDesc('refreshed_at')
                ->limit($limit)
                ->pluck('attempt_id');

            foreach ($rows as $value) {
                $candidate = trim((string) $value);
                if ($candidate !== '') {
                    $attemptIds[$candidate] = true;
                }
            }
        }

        $values = array_keys($attemptIds);
        sort($values, SORT_STRING);

        return array_slice($values, 0, $limit);
    }

    private function latestSubmissionRow(string $attemptId): ?object
    {
        if (! Schema::hasTable('attempt_submissions')) {
            return null;
        }

        return DB::table('attempt_submissions')
            ->where('attempt_id', $attemptId)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @param  array<string,mixed>  $details
     * @return array<string,mixed>
     */
    private function finding(string $attemptId, string $issueCode, string $severity, array $details): array
    {
        return [
            'attempt_id' => $attemptId,
            'issue_code' => $issueCode,
            'severity' => $severity,
            'details' => $details,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function restartSubmission(string $submissionId, string $reasonCode): ?array
    {
        $submissionId = trim($submissionId);
        if ($submissionId === '' || ! Schema::hasTable('attempt_submissions')) {
            return null;
        }

        $row = DB::transaction(function () use ($submissionId): ?object {
            $row = DB::table('attempt_submissions')
                ->where('id', $submissionId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return null;
            }

            DB::table('attempt_submissions')
                ->where('id', $submissionId)
                ->update([
                    'state' => 'pending',
                    'error_code' => null,
                    'error_message' => null,
                    'response_payload_json' => null,
                    'started_at' => null,
                    'finished_at' => null,
                    'updated_at' => now(),
                ]);

            return DB::table('attempt_submissions')->where('id', $submissionId)->first();
        });

        if ($row === null) {
            return null;
        }

        ProcessAttemptSubmissionJob::dispatch($submissionId)->afterCommit();

        return [
            'attempt_id' => (string) ($row->attempt_id ?? ''),
            'submission_id' => $submissionId,
            'repair_code' => 'submission_requeued',
            'reason_code' => $reasonCode,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function syncProjectionToPending(string $attemptId, object $submission): ?array
    {
        $projection = $this->projectionWriter->refreshAttemptProjection(
            $attemptId,
            [
                'access_state' => 'locked',
                'report_state' => 'pending',
                'pdf_state' => 'missing',
                'reason_code' => 'submission_pending',
                'actions_json' => ['report' => true, 'pdf' => false],
                'payload_json' => [
                    'fallback' => true,
                    'submission' => [
                        'id' => (string) ($submission->id ?? ''),
                        'state' => strtolower(trim((string) ($submission->state ?? 'pending'))),
                    ],
                ],
            ],
            [
                'source_system' => 'attempt_submission_recovery',
                'source_ref' => 'submission#'.(string) ($submission->id ?? ''),
                'actor_type' => 'system',
                'actor_id' => 'ops:attempt-submission-recovery',
            ]
        );

        if ($projection === null) {
            return null;
        }

        return [
            'attempt_id' => $attemptId,
            'submission_id' => (string) ($submission->id ?? ''),
            'repair_code' => 'projection_refreshed',
            'reason_code' => 'submission_pending',
        ];
    }

    /**
     * @param  array<string,mixed>  $submissionPayload
     * @return array<string,mixed>|null
     */
    private function syncProjectionToFailed(string $attemptId, object $submission, array $submissionPayload): ?array
    {
        $projection = $this->projectionWriter->refreshAttemptProjection(
            $attemptId,
            [
                'access_state' => 'locked',
                'report_state' => 'unavailable',
                'pdf_state' => 'missing',
                'reason_code' => 'submission_failed',
                'actions_json' => ['report' => false, 'pdf' => false],
                'payload_json' => [
                    'fallback' => true,
                    'submission' => [
                        'id' => (string) ($submission->id ?? ''),
                        'state' => strtolower(trim((string) ($submission->state ?? 'failed'))),
                        'error_code' => $this->normalizeNullableString($submission->error_code ?? null),
                    ],
                    'result' => $submissionPayload !== [] ? $submissionPayload : null,
                ],
            ],
            [
                'source_system' => 'attempt_submission_recovery',
                'source_ref' => 'submission#'.(string) ($submission->id ?? ''),
                'actor_type' => 'system',
                'actor_id' => 'ops:attempt-submission-recovery',
            ]
        );

        if ($projection === null) {
            return null;
        }

        return [
            'attempt_id' => $attemptId,
            'submission_id' => (string) ($submission->id ?? ''),
            'repair_code' => 'projection_refreshed',
            'reason_code' => 'submission_failed',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function refreshProjectionFromArtifacts(string $attemptId): ?array
    {
        if (Result::query()->where('attempt_id', $attemptId)->exists()) {
            $projection = $this->projectionWriter->refreshAttemptProjection(
                $attemptId,
                [
                    'access_state' => 'locked',
                    'report_state' => 'ready',
                    'pdf_state' => 'missing',
                    'reason_code' => 'result_available',
                    'actions_json' => ['report' => true, 'pdf' => false],
                    'payload_json' => [
                        'attempt_id' => $attemptId,
                        'result_exists' => true,
                        'fallback' => true,
                    ],
                ],
                [
                    'source_system' => 'attempt_submission_recovery',
                    'source_ref' => 'result#'.$attemptId,
                    'actor_type' => 'system',
                    'actor_id' => 'ops:attempt-submission-recovery',
                ]
            );

            if ($projection !== null) {
                return [
                    'attempt_id' => $attemptId,
                    'submission_id' => null,
                    'repair_code' => 'projection_backfilled',
                    'reason_code' => 'result_available',
                ];
            }
        }

        $result = $this->projectionBackfill->executeBackfill(['attempt_id' => $attemptId]);

        return [
            'attempt_id' => $attemptId,
            'submission_id' => null,
            'repair_code' => 'projection_backfilled',
            'reason_code' => 'projection_missing_after_result',
            'details' => [
                'attempt_receipts_inserted' => (int) ($result['attempt_receipts_inserted'] ?? 0),
                'attempt_receipts_reused' => (int) ($result['attempt_receipts_reused'] ?? 0),
            ],
        ];
    }

    private function projectionMatchesPending(?object $projection): bool
    {
        if ($projection === null) {
            return false;
        }

        return strtolower(trim((string) ($projection->access_state ?? ''))) === 'locked'
            && strtolower(trim((string) ($projection->report_state ?? ''))) === 'pending'
            && strtolower(trim((string) ($projection->reason_code ?? ''))) === 'submission_pending';
    }

    private function projectionMatchesFailed(?object $projection): bool
    {
        if ($projection === null) {
            return false;
        }

        return strtolower(trim((string) ($projection->access_state ?? ''))) === 'locked'
            && strtolower(trim((string) ($projection->report_state ?? ''))) === 'unavailable'
            && strtolower(trim((string) ($projection->reason_code ?? ''))) === 'submission_failed';
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,int>
     */
    private function countByKey(array $rows, string $key): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            $counts[$value] = (int) ($counts[$value] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
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

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value));
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        try {
            return Carbon::parse($text);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
