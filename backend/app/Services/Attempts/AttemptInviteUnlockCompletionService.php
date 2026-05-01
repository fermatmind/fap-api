<?php

declare(strict_types=1);

namespace App\Services\Attempts;

use App\Models\Attempt;
use App\Models\AttemptInviteUnlock;
use App\Models\AttemptInviteUnlockCompletion;
use App\Models\Result;
use App\Services\Analytics\EventRecorder;
use App\Services\Attempts\InviteUnlock\InviteUnlockCompletionStatus;
use App\Services\Attempts\InviteUnlock\InviteUnlockDiagnostics;
use App\Services\Attempts\InviteUnlock\InviteUnlockStatus;
use App\Services\Commerce\EntitlementManager;
use App\Support\Logging\SensitiveDiagnosticRedactor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class AttemptInviteUnlockCompletionService
{
    private const MBTI_PARTIAL_BENEFIT_CODE = 'MBTI_CAREER';

    private const MBTI_FULL_BENEFIT_CODE = 'MBTI_REPORT_FULL';

    public function __construct(
        private readonly AttemptInviteUnlockService $inviteService,
        private readonly EntitlementManager $entitlements,
        private readonly EventRecorder $eventRecorder,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function recordCompletionForInvite(
        string $inviteCode,
        string $inviteeAttemptId,
        ?string $inviteeUserId,
        ?string $inviteeAnonId,
    ): array {
        $startedAt = hrtime(true);
        $normalizedInviteCode = trim($inviteCode);
        $normalizedAttemptId = trim($inviteeAttemptId);
        if ($normalizedInviteCode === '' || $normalizedAttemptId === '') {
            $invalidInputPayload = [
                'ok' => false,
                'error_code' => 'INVITE_UNLOCK_COMPLETION_INVALID_INPUT',
                'message' => 'invite_code and invitee_attempt_id are required.',
            ];

            $this->logCompletionDiagnostic(
                $normalizedInviteCode,
                $normalizedAttemptId,
                $invalidInputPayload,
                $startedAt
            );

            return $invalidInputPayload;
        }

        $result = DB::transaction(function () use (
            $normalizedInviteCode,
            $normalizedAttemptId,
            $inviteeUserId,
            $inviteeAnonId
        ): array {
            $invite = AttemptInviteUnlock::query()
                ->where('invite_code', $normalizedInviteCode)
                ->lockForUpdate()
                ->first();

            if (! $invite instanceof AttemptInviteUnlock) {
                return [
                    'ok' => false,
                    'error_code' => 'INVITE_UNLOCK_NOT_FOUND',
                    'message' => 'invite not found.',
                ];
            }

            $attemptIdempotency = $this->buildIdempotencyKey(
                'invite_completion',
                [$invite->id, $normalizedAttemptId]
            );

            $existingByAttemptIdempotency = AttemptInviteUnlockCompletion::query()
                ->where('idempotency_key', $attemptIdempotency)
                ->first();

            if ($existingByAttemptIdempotency instanceof AttemptInviteUnlockCompletion) {
                $refreshedInvite = $this->inviteService->refreshProgressSnapshot($invite);

                return $this->buildCompletionPayload(
                    $existingByAttemptIdempotency,
                    $refreshedInvite,
                    true
                );
            }

            $inviteeAttempt = Attempt::query()
                ->where('org_id', (int) $invite->target_org_id)
                ->where('id', $normalizedAttemptId)
                ->first();

            if (! $inviteeAttempt instanceof Attempt) {
                return $this->rejectAndPersist(
                    $invite,
                    InviteUnlockCompletionStatus::REJECTED_INVALID_ATTEMPT,
                    $attemptIdempotency,
                    $normalizedAttemptId,
                    null,
                    null,
                    [
                        'candidate_invitee_user_id' => $this->normalizeUserId($inviteeUserId),
                        'candidate_invitee_anon_id' => $this->normalizeString($inviteeAnonId),
                    ]
                );
            }

            $resolvedInviteeUserId = $this->normalizeUserId((string) ($inviteeAttempt->user_id ?? ''))
                ?? $this->normalizeUserId($inviteeUserId);
            $resolvedInviteeAnonId = $this->normalizeString((string) ($inviteeAttempt->anon_id ?? ''))
                ?? $this->normalizeString($inviteeAnonId);
            $inviteeIdentityKey = $this->buildInviteeIdentityKey($resolvedInviteeUserId, $resolvedInviteeAnonId, $normalizedAttemptId);

            $inviterUserId = $this->normalizeUserId((string) ($invite->inviter_user_id ?? ''));
            $inviterAnonId = $this->normalizeString((string) ($invite->inviter_anon_id ?? ''));
            $isSelfReferral = (
                $inviterUserId !== null
                && $resolvedInviteeUserId !== null
                && $inviterUserId === $resolvedInviteeUserId
            ) || (
                $inviterAnonId !== null
                && $resolvedInviteeAnonId !== null
                && $inviterAnonId === $resolvedInviteeAnonId
            ) || trim((string) ($invite->target_attempt_id ?? '')) === $normalizedAttemptId;

            if ($isSelfReferral) {
                $existingSelfReferral = AttemptInviteUnlockCompletion::query()
                    ->where('invite_id', (string) $invite->id)
                    ->where(function ($query) use ($normalizedAttemptId, $inviteeIdentityKey): void {
                        $query->where('invitee_attempt_id', $normalizedAttemptId);
                        if ($inviteeIdentityKey !== null) {
                            $query->orWhere('invitee_identity_key', $inviteeIdentityKey);
                        }
                    })
                    ->first();
                if ($existingSelfReferral instanceof AttemptInviteUnlockCompletion) {
                    $refreshedInvite = $this->inviteService->refreshProgressSnapshot($invite);

                    return $this->buildCompletionPayload(
                        $existingSelfReferral,
                        $refreshedInvite,
                        true
                    );
                }

                return $this->rejectAndPersist(
                    $invite,
                    InviteUnlockCompletionStatus::REJECTED_SELF_REFERRAL,
                    $attemptIdempotency,
                    $normalizedAttemptId,
                    $inviteeIdentityKey,
                    $inviteeIdentityKey,
                    [],
                    $resolvedInviteeUserId,
                    $resolvedInviteeAnonId
                );
            }

            $duplicateByAttempt = AttemptInviteUnlockCompletion::query()
                ->where('invite_id', (string) $invite->id)
                ->where('invitee_attempt_id', $normalizedAttemptId)
                ->first();
            if ($duplicateByAttempt instanceof AttemptInviteUnlockCompletion) {
                return $this->rejectDuplicateAndPersist(
                    $invite,
                    InviteUnlockCompletionStatus::REJECTED_DUPLICATE_COMPLETION,
                    $normalizedAttemptId,
                    $inviteeIdentityKey,
                    $resolvedInviteeUserId,
                    $resolvedInviteeAnonId,
                    (string) $duplicateByAttempt->id
                );
            }

            if ($inviteeIdentityKey !== null) {
                $duplicateByIdentityInInvite = AttemptInviteUnlockCompletion::query()
                    ->where('invite_id', (string) $invite->id)
                    ->where('invitee_identity_key', $inviteeIdentityKey)
                    ->first();
                if ($duplicateByIdentityInInvite instanceof AttemptInviteUnlockCompletion) {
                    return $this->rejectDuplicateAndPersist(
                        $invite,
                        InviteUnlockCompletionStatus::REJECTED_DUPLICATE_INVITEE,
                        $normalizedAttemptId,
                        $inviteeIdentityKey,
                        $resolvedInviteeUserId,
                        $resolvedInviteeAnonId,
                        (string) $duplicateByIdentityInInvite->id
                    );
                }

                $duplicateCountedIdentity = AttemptInviteUnlockCompletion::query()
                    ->where('counted', true)
                    ->where('counted_identity_key', $inviteeIdentityKey)
                    ->first();
                if ($duplicateCountedIdentity instanceof AttemptInviteUnlockCompletion) {
                    return $this->rejectDuplicateAndPersist(
                        $invite,
                        InviteUnlockCompletionStatus::REJECTED_DUPLICATE_INVITEE,
                        $normalizedAttemptId,
                        $inviteeIdentityKey,
                        $resolvedInviteeUserId,
                        $resolvedInviteeAnonId,
                        (string) $duplicateCountedIdentity->id
                    );
                }
            }

            if (
                strtolower(trim((string) ($invite->status ?? InviteUnlockStatus::PENDING))) === InviteUnlockStatus::COMPLETED
                || (int) ($invite->completed_invitees ?? 0) >= max(1, (int) ($invite->required_invitees ?? 2))
            ) {
                return $this->rejectAndPersist(
                    $invite,
                    InviteUnlockCompletionStatus::REJECTED_DUPLICATE_COMPLETION,
                    $attemptIdempotency,
                    $normalizedAttemptId,
                    $inviteeIdentityKey,
                    $inviteeIdentityKey,
                    [
                        'already_completed' => true,
                    ],
                    $resolvedInviteeUserId,
                    $resolvedInviteeAnonId
                );
            }

            $inviteeScaleCode = strtoupper(trim((string) ($inviteeAttempt->scale_code ?? '')));
            $targetScaleCode = strtoupper(trim((string) ($invite->target_scale_code ?? '')));
            if (! $this->inviteService->areScaleFamiliesCompatible($targetScaleCode, $inviteeScaleCode)) {
                return $this->rejectAndPersist(
                    $invite,
                    InviteUnlockCompletionStatus::REJECTED_SCALE_MISMATCH,
                    $attemptIdempotency,
                    $normalizedAttemptId,
                    $inviteeIdentityKey,
                    $inviteeIdentityKey,
                    [
                        'target_scale_code' => $targetScaleCode,
                        'invitee_scale_code' => $inviteeScaleCode,
                    ],
                    $resolvedInviteeUserId,
                    $resolvedInviteeAnonId
                );
            }

            $hasResult = $inviteeAttempt->submitted_at !== null
                && Result::query()
                    ->where('org_id', (int) $invite->target_org_id)
                    ->where('attempt_id', $normalizedAttemptId)
                    ->exists();

            if (! $hasResult) {
                return $this->rejectAndPersist(
                    $invite,
                    InviteUnlockCompletionStatus::REJECTED_NOT_SUBMITTED_OR_RESULT_MISSING,
                    $attemptIdempotency,
                    $normalizedAttemptId,
                    $inviteeIdentityKey,
                    $inviteeIdentityKey,
                    [],
                    $resolvedInviteeUserId,
                    $resolvedInviteeAnonId
                );
            }

            try {
                $completion = $this->persistCompletion(
                    $invite,
                    $attemptIdempotency,
                    InviteUnlockCompletionStatus::QUALIFIED_COUNTED,
                    $normalizedAttemptId,
                    $inviteeIdentityKey,
                    $inviteeIdentityKey,
                    true,
                    true,
                    [],
                    $resolvedInviteeUserId,
                    $resolvedInviteeAnonId
                );
            } catch (QueryException $e) {
                if (! $this->isDuplicateKey($e)) {
                    throw $e;
                }

                $existingByAttemptIdempotency = AttemptInviteUnlockCompletion::query()
                    ->where('idempotency_key', $attemptIdempotency)
                    ->first();
                if ($existingByAttemptIdempotency instanceof AttemptInviteUnlockCompletion) {
                    $refreshedInvite = $this->inviteService->refreshProgressSnapshot($invite);

                    return $this->buildCompletionPayload(
                        $existingByAttemptIdempotency,
                        $refreshedInvite,
                        true
                    );
                }

                $duplicateByAttempt = AttemptInviteUnlockCompletion::query()
                    ->where('invite_id', (string) $invite->id)
                    ->where('invitee_attempt_id', $normalizedAttemptId)
                    ->first();
                if ($duplicateByAttempt instanceof AttemptInviteUnlockCompletion) {
                    return $this->rejectDuplicateAndPersist(
                        $invite,
                        InviteUnlockCompletionStatus::REJECTED_DUPLICATE_COMPLETION,
                        $normalizedAttemptId,
                        $inviteeIdentityKey,
                        $resolvedInviteeUserId,
                        $resolvedInviteeAnonId,
                        (string) $duplicateByAttempt->id
                    );
                }

                if ($inviteeIdentityKey !== null) {
                    $duplicateByIdentityInInvite = AttemptInviteUnlockCompletion::query()
                        ->where('invite_id', (string) $invite->id)
                        ->where('invitee_identity_key', $inviteeIdentityKey)
                        ->first();
                    if ($duplicateByIdentityInInvite instanceof AttemptInviteUnlockCompletion) {
                        return $this->rejectDuplicateAndPersist(
                            $invite,
                            InviteUnlockCompletionStatus::REJECTED_DUPLICATE_INVITEE,
                            $normalizedAttemptId,
                            $inviteeIdentityKey,
                            $resolvedInviteeUserId,
                            $resolvedInviteeAnonId,
                            (string) $duplicateByIdentityInInvite->id
                        );
                    }

                    $duplicateCountedIdentity = AttemptInviteUnlockCompletion::query()
                        ->where('counted', true)
                        ->where('counted_identity_key', $inviteeIdentityKey)
                        ->first();
                    if ($duplicateCountedIdentity instanceof AttemptInviteUnlockCompletion) {
                        return $this->rejectDuplicateAndPersist(
                            $invite,
                            InviteUnlockCompletionStatus::REJECTED_DUPLICATE_INVITEE,
                            $normalizedAttemptId,
                            $inviteeIdentityKey,
                            $resolvedInviteeUserId,
                            $resolvedInviteeAnonId,
                            (string) $duplicateCountedIdentity->id
                        );
                    }
                }

                throw $e;
            }

            $refreshedInvite = $this->inviteService->refreshProgressSnapshot($invite);

            return $this->buildCompletionPayload($completion, $refreshedInvite, false);
        });

        $this->logCompletionDiagnostic(
            $normalizedInviteCode,
            $normalizedAttemptId,
            $result,
            $startedAt
        );

        return $result;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function rejectAndPersist(
        AttemptInviteUnlock $invite,
        string $status,
        string $idempotencyKey,
        ?string $inviteeAttemptId,
        ?string $inviteeIdentityKey,
        ?string $countedIdentityKey,
        array $meta = [],
        ?string $inviteeUserId = null,
        ?string $inviteeAnonId = null,
    ): array {
        $completion = $this->persistCompletion(
            $invite,
            $idempotencyKey,
            $status,
            $inviteeAttemptId,
            $inviteeIdentityKey,
            $countedIdentityKey,
            false,
            false,
            $meta,
            $inviteeUserId,
            $inviteeAnonId
        );
        $refreshedInvite = $this->inviteService->refreshProgressSnapshot($invite);

        return $this->buildCompletionPayload($completion, $refreshedInvite, false);
    }

    /**
     * @return array<string,mixed>
     */
    private function rejectDuplicateAndPersist(
        AttemptInviteUnlock $invite,
        string $status,
        string $candidateInviteeAttemptId,
        ?string $candidateInviteeIdentityKey,
        ?string $inviteeUserId,
        ?string $inviteeAnonId,
        string $duplicateOfCompletionId,
    ): array {
        $idempotencyKey = $this->buildIdempotencyKey(
            $status,
            [
                (string) $invite->id,
                $candidateInviteeAttemptId,
                $candidateInviteeIdentityKey ?? '',
                $duplicateOfCompletionId,
            ]
        );

        $existing = AttemptInviteUnlockCompletion::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing instanceof AttemptInviteUnlockCompletion) {
            $refreshedInvite = $this->inviteService->refreshProgressSnapshot($invite);

            return $this->buildCompletionPayload($existing, $refreshedInvite, true);
        }

        return $this->rejectAndPersist(
            $invite,
            $status,
            $idempotencyKey,
            null,
            null,
            null,
            [
                'candidate_invitee_attempt_id' => $candidateInviteeAttemptId,
                'candidate_invitee_identity_key' => $candidateInviteeIdentityKey,
                'duplicate_of_completion_id' => $duplicateOfCompletionId,
            ],
            $inviteeUserId,
            $inviteeAnonId
        );
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function persistCompletion(
        AttemptInviteUnlock $invite,
        string $idempotencyKey,
        string $status,
        ?string $inviteeAttemptId,
        ?string $inviteeIdentityKey,
        ?string $countedIdentityKey,
        bool $qualified,
        bool $counted,
        array $meta = [],
        ?string $inviteeUserId = null,
        ?string $inviteeAnonId = null,
    ): AttemptInviteUnlockCompletion {
        $normalizedStatus = strtolower(trim($status));
        $qualifiedReason = InviteUnlockCompletionStatus::toQualifiedReason($normalizedStatus);
        $normalizedInviteeAttemptId = $this->normalizeString($inviteeAttemptId);
        $normalizedInviteeIdentityKey = $this->normalizeString($inviteeIdentityKey);
        $normalizedCountedIdentityKey = $counted ? $this->normalizeString($countedIdentityKey) : null;
        $normalizedInviteeUserId = $this->normalizeUserId($inviteeUserId);
        $normalizedInviteeAnonId = $this->normalizeString($inviteeAnonId);

        $payload = [
            'id' => (string) Str::uuid(),
            'invite_id' => (string) $invite->id,
            'invite_code' => (string) $invite->invite_code,
            'target_attempt_id' => (string) $invite->target_attempt_id,
            'invitee_attempt_id' => $normalizedInviteeAttemptId,
            'invitee_org_id' => $normalizedInviteeAttemptId !== null ? (int) $invite->target_org_id : null,
            'invitee_user_id' => $normalizedInviteeUserId,
            'invitee_anon_id' => $normalizedInviteeAnonId,
            'invitee_identity_key' => $normalizedInviteeIdentityKey,
            'qualified' => $qualified,
            'qualified_reason' => $qualifiedReason,
            'qualification_status' => $normalizedStatus,
            'counted' => $counted,
            'counted_identity_key' => $normalizedCountedIdentityKey,
            'idempotency_key' => $idempotencyKey,
            'meta_json' => $meta === [] ? null : $meta,
        ];

        try {
            /** @var AttemptInviteUnlockCompletion $created */
            $created = AttemptInviteUnlockCompletion::query()->create($payload);

            return $created;
        } catch (QueryException $e) {
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }

            $existing = AttemptInviteUnlockCompletion::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof AttemptInviteUnlockCompletion) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function buildCompletionPayload(
        AttemptInviteUnlockCompletion $completion,
        AttemptInviteUnlock $invite,
        bool $idempotent
    ): array {
        $entitlementState = $this->syncStagedEntitlementsForInvite($invite);
        $this->emitCompletionTelemetry($completion, $invite, $entitlementState, $idempotent);

        return [
            'ok' => true,
            'idempotent' => $idempotent,
            'invite_id' => (string) $invite->id,
            'invite_code' => (string) $invite->invite_code,
            'completion_id' => (string) $completion->id,
            'qualification_status' => (string) ($completion->qualification_status ?? ''),
            'qualified_reason' => (string) ($completion->qualified_reason ?? ''),
            'qualified' => (bool) ($completion->qualified ?? false),
            'counted' => (bool) ($completion->counted ?? false),
            'progress' => $this->inviteService->buildInvitePayload($invite),
            'unlock_stage' => (string) ($entitlementState['unlock_stage'] ?? 'locked'),
            'unlock_source' => (string) ($entitlementState['unlock_source'] ?? 'none'),
        ];
    }

    /**
     * @param  array{unlock_stage:string,unlock_source:string}  $entitlementState
     */
    private function emitCompletionTelemetry(
        AttemptInviteUnlockCompletion $completion,
        AttemptInviteUnlock $invite,
        array $entitlementState,
        bool $idempotent
    ): void {
        if ($idempotent) {
            return;
        }

        $orgId = (int) ($invite->target_org_id ?? 0);
        $attemptId = trim((string) ($invite->target_attempt_id ?? ''));
        if ($attemptId === '') {
            return;
        }

        $inviterUserId = $this->normalizeUserId((string) ($invite->inviter_user_id ?? ''));
        $inviterAnonId = $this->normalizeString((string) ($invite->inviter_anon_id ?? ''));
        $unlockStage = (string) ($entitlementState['unlock_stage'] ?? 'locked');
        $unlockSource = (string) ($entitlementState['unlock_source'] ?? 'none');
        $scaleCode = strtoupper(trim((string) ($invite->target_scale_code ?? '')));
        $completedInvitees = (int) ($invite->completed_invitees ?? 0);
        $requiredInvitees = max(1, (int) ($invite->required_invitees ?? 2));
        $baseMeta = [
            'target_attempt_id' => $attemptId,
            'scale_code' => $scaleCode,
            'invite_id' => (string) ($invite->id ?? ''),
            'invite_code' => (string) ($invite->invite_code ?? ''),
            'completion_id' => (string) ($completion->id ?? ''),
            'unlock_stage' => $unlockStage,
            'unlock_source' => $unlockSource,
            'completed_invitees' => $completedInvitees,
            'required_invitees' => $requiredInvitees,
            'qualified' => (bool) ($completion->qualified ?? false),
            'counted' => (bool) ($completion->counted ?? false),
            'qualification_status' => (string) ($completion->qualification_status ?? ''),
            'qualified_reason' => (string) ($completion->qualified_reason ?? ''),
            'invitee_attempt_id' => $this->normalizeString((string) ($completion->invitee_attempt_id ?? '')),
            'invitee_identity_key' => $this->normalizeString((string) ($completion->invitee_identity_key ?? '')),
        ];
        $context = [
            'org_id' => $orgId,
            'anon_id' => $inviterAnonId,
            'attempt_id' => $attemptId,
            'scale_code' => $scaleCode,
        ];
        $inviterUserIdInt = $inviterUserId !== null ? (int) $inviterUserId : null;

        if ((bool) ($completion->qualified ?? false) && (bool) ($completion->counted ?? false)) {
            $this->eventRecorder->record('invite_unlock_completion_qualified', $inviterUserIdInt, $baseMeta, $context);
        } else {
            $this->eventRecorder->record('invite_unlock_completion_rejected', $inviterUserIdInt, $baseMeta, $context);
        }

        if ($unlockStage === 'partial') {
            $this->eventRecorder->record('invite_unlock_partial_granted', $inviterUserIdInt, $baseMeta, $context);
        } elseif ($unlockStage === 'full') {
            $this->eventRecorder->record('invite_unlock_full_granted', $inviterUserIdInt, $baseMeta, $context);
            if ($unlockSource === 'mixed') {
                $this->eventRecorder->record('invite_unlock_mixed_confirmed', $inviterUserIdInt, $baseMeta, $context);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function logCompletionDiagnostic(
        string $inviteCode,
        string $inviteeAttemptId,
        array $payload,
        int $startedAt
    ): void {
        $progress = is_array($payload['progress'] ?? null) ? $payload['progress'] : [];
        $diagnostics = is_array($progress['invite_unlock_diag_v1'] ?? null)
            ? $progress['invite_unlock_diag_v1']
            : InviteUnlockDiagnostics::build(
                (int) ($progress['completed_invitees'] ?? 0),
                (int) ($progress['required_invitees'] ?? 2),
                (string) ($payload['unlock_stage'] ?? ($progress['unlock_stage'] ?? 'locked')),
                (string) ($payload['unlock_source'] ?? ($progress['unlock_source'] ?? 'none')),
                isset($progress['status']) ? (string) $progress['status'] : null
            );
        $durationMs = (int) floor((hrtime(true) - $startedAt) / 1_000_000);
        $logPayload = [
            'source' => __METHOD__,
            'invite_code_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($inviteCode),
            'invitee_attempt_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($inviteeAttemptId),
            'ok' => (bool) ($payload['ok'] ?? false),
            'idempotent' => (bool) ($payload['idempotent'] ?? false),
            'invite_fingerprint' => SensitiveDiagnosticRedactor::fingerprint((string) ($payload['invite_id'] ?? '')),
            'completion_id' => (string) ($payload['completion_id'] ?? ''),
            'qualification_status' => (string) ($payload['qualification_status'] ?? ''),
            'qualified_reason' => (string) ($payload['qualified_reason'] ?? ''),
            'qualified' => (bool) ($payload['qualified'] ?? false),
            'counted' => (bool) ($payload['counted'] ?? false),
            'diagnostic_status' => (string) ($diagnostics['status'] ?? 'locked'),
            'diagnostic_status_reason' => (string) ($diagnostics['status_reason'] ?? 'unlock_stage_locked'),
            'unlock_stage' => (string) ($diagnostics['unlock_stage'] ?? 'locked'),
            'unlock_source' => (string) ($diagnostics['unlock_source'] ?? 'none'),
            'completed_invitees' => (int) ($diagnostics['completed_invitees'] ?? 0),
            'required_invitees' => (int) ($diagnostics['required_invitees'] ?? 2),
            'remaining_invitees' => (int) ($diagnostics['remaining_invitees'] ?? 2),
            'progress_percent' => (int) ($diagnostics['progress_percent'] ?? 0),
            'duration_ms' => $durationMs,
        ];

        if ((bool) ($payload['ok'] ?? false) === true) {
            Log::info('INVITE_UNLOCK_COMPLETION_DIAGNOSTIC', $logPayload);

            return;
        }

        Log::warning('INVITE_UNLOCK_COMPLETION_DIAGNOSTIC', array_merge($logPayload, [
            'error_code' => (string) ($payload['error_code'] ?? 'INVITE_UNLOCK_COMPLETION_REJECTED'),
            'message' => (string) ($payload['message'] ?? 'invite unlock completion rejected'),
        ]));
    }

    /**
     * @return array{unlock_stage:string,unlock_source:string}
     */
    private function syncStagedEntitlementsForInvite(AttemptInviteUnlock $invite): array
    {
        $orgId = (int) ($invite->target_org_id ?? 0);
        $attemptId = trim((string) ($invite->target_attempt_id ?? ''));
        $scaleCode = strtoupper(trim((string) ($invite->target_scale_code ?? '')));
        if ($orgId < 0 || $attemptId === '' || $scaleCode !== 'MBTI') {
            return [
                'unlock_stage' => 'locked',
                'unlock_source' => 'none',
            ];
        }

        $inviterUserId = $this->normalizeUserId((string) ($invite->inviter_user_id ?? ''));
        $inviterAnonId = $this->normalizeString((string) ($invite->inviter_anon_id ?? ''));

        $completedInvitees = max(0, (int) ($invite->completed_invitees ?? 0));
        $fullAlready = $this->entitlements->hasActiveGrantForAttemptBenefitCode(
            $orgId,
            $attemptId,
            self::MBTI_FULL_BENEFIT_CODE
        );
        $partialAlready = $this->entitlements->hasActiveGrantForAttemptBenefitCode(
            $orgId,
            $attemptId,
            self::MBTI_PARTIAL_BENEFIT_CODE
        );

        if ($completedInvitees >= 2 && ! $fullAlready) {
            $this->entitlements->grantAttemptUnlock(
                $orgId,
                $inviterUserId,
                $inviterAnonId,
                self::MBTI_FULL_BENEFIT_CODE,
                $attemptId,
                null,
                'attempt',
                null,
                null,
                [
                    'granted_via' => 'invite_unlock',
                    'invite_unlock_code' => (string) ($invite->invite_code ?? ''),
                    'invite_unlock_id' => (string) ($invite->id ?? ''),
                    'invite_unlock_stage' => 'full',
                ]
            );
        } elseif ($completedInvitees >= 1 && ! $fullAlready && ! $partialAlready) {
            $this->entitlements->grantAttemptUnlock(
                $orgId,
                $inviterUserId,
                $inviterAnonId,
                self::MBTI_PARTIAL_BENEFIT_CODE,
                $attemptId,
                null,
                'attempt',
                null,
                null,
                [
                    'granted_via' => 'invite_unlock',
                    'invite_unlock_code' => (string) ($invite->invite_code ?? ''),
                    'invite_unlock_id' => (string) ($invite->id ?? ''),
                    'invite_unlock_stage' => 'partial',
                ]
            );
        }

        $state = $this->entitlements->syncAttemptProjectionFromEntitlements(
            $orgId,
            $attemptId,
            [
                'source_system' => 'invite_unlock_completion',
                'source_ref' => (string) ($invite->invite_code ?? $attemptId),
                'actor_type' => $inviterUserId !== null ? 'user' : 'anon',
                'actor_id' => $inviterUserId ?? $inviterAnonId,
                'reason_code' => 'invite_unlock_stage_synced',
            ]
        );

        return [
            'unlock_stage' => (string) ($state['unlock_stage'] ?? 'locked'),
            'unlock_source' => (string) ($state['unlock_source'] ?? 'none'),
        ];
    }

    /**
     * @param  list<string>  $parts
     */
    private function buildIdempotencyKey(string $prefix, array $parts): string
    {
        $seed = implode('|', array_map(
            static fn (string $part): string => trim($part),
            $parts
        ));

        return strtolower(trim($prefix)).':'.hash('sha256', $seed);
    }

    private function buildInviteeIdentityKey(?string $inviteeUserId, ?string $inviteeAnonId, string $inviteeAttemptId): ?string
    {
        if ($inviteeUserId !== null) {
            return 'user:'.$inviteeUserId;
        }

        if ($inviteeAnonId !== null) {
            return 'anon:'.$inviteeAnonId;
        }

        $normalizedAttemptId = trim($inviteeAttemptId);
        if ($normalizedAttemptId !== '') {
            return 'attempt:'.$normalizedAttemptId;
        }

        return null;
    }

    private function normalizeUserId(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '' || preg_match('/^\d+$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    private function normalizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        if (in_array($sqlState, ['23000', '23505'], true)) {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate')
            || str_contains($message, 'unique constraint failed')
            || str_contains($message, 'integrity constraint');
    }
}
