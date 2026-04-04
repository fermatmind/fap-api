<?php

declare(strict_types=1);

namespace App\Services\Attempts;

use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;
use App\Models\AttemptInviteUnlock;
use App\Models\AttemptInviteUnlockCompletion;
use App\Models\Result;
use App\Services\Attempts\InviteUnlock\InviteUnlockStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AttemptInviteUnlockService
{
    private const DEFAULT_REQUIRED_INVITEES = 2;

    private const RULE_VERSION = 'v1';

    /**
     * @var list<string>
     */
    private const SUPPORTED_SCALES = ['MBTI', 'BIG5_OCEAN'];

    /**
     * @return array<string,mixed>
     */
    public function createOrReuseInvite(Attempt $targetAttempt, ?string $inviterUserId, ?string $inviterAnonId): array
    {
        $targetOrgId = (int) ($targetAttempt->org_id ?? 0);
        $targetAttemptId = trim((string) ($targetAttempt->id ?? ''));
        $targetScaleCode = $this->normalizeSupportedScaleCode((string) ($targetAttempt->scale_code ?? ''));
        $normalizedInviterUserId = $this->normalizeUserId($inviterUserId);
        $normalizedInviterAnonId = $this->normalizeString($inviterAnonId);

        if ($targetAttemptId === '') {
            throw new ApiProblemException(422, 'INVITE_UNLOCK_TARGET_INVALID', 'target attempt is invalid.');
        }

        $this->assertTargetAttemptEligible($targetAttempt, $targetOrgId, $targetAttemptId);

        try {
            return DB::transaction(function () use (
                $targetOrgId,
                $targetAttemptId,
                $targetScaleCode,
                $normalizedInviterUserId,
                $normalizedInviterAnonId
            ): array {
                $existing = AttemptInviteUnlock::query()
                    ->where('target_org_id', $targetOrgId)
                    ->where('target_attempt_id', $targetAttemptId)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof AttemptInviteUnlock) {
                    $needsUpdate = false;
                    if ($existing->inviter_user_id === null && $normalizedInviterUserId !== null) {
                        $existing->inviter_user_id = $normalizedInviterUserId;
                        $needsUpdate = true;
                    }
                    if ($existing->inviter_anon_id === null && $normalizedInviterAnonId !== null) {
                        $existing->inviter_anon_id = $normalizedInviterAnonId;
                        $needsUpdate = true;
                    }
                    if ($needsUpdate) {
                        $existing->save();
                    }

                    $fresh = $this->refreshProgressSnapshot($existing);

                    return $this->buildInvitePayload($fresh);
                }

                $invite = new AttemptInviteUnlock;
                $invite->id = (string) Str::uuid();
                $invite->target_org_id = $targetOrgId;
                $invite->invite_code = $this->generateInviteCode();
                $invite->target_attempt_id = $targetAttemptId;
                $invite->target_scale_code = $targetScaleCode;
                $invite->inviter_user_id = $normalizedInviterUserId;
                $invite->inviter_anon_id = $normalizedInviterAnonId;
                $invite->status = InviteUnlockStatus::PENDING;
                $invite->required_invitees = self::DEFAULT_REQUIRED_INVITEES;
                $invite->completed_invitees = 0;
                $invite->qualification_rule_version = self::RULE_VERSION;
                $invite->meta_json = null;
                $invite->saveOrFail();

                return $this->buildInvitePayload($invite);
            });
        } catch (QueryException $e) {
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }

            $existing = AttemptInviteUnlock::query()
                ->where('target_org_id', $targetOrgId)
                ->where('target_attempt_id', $targetAttemptId)
                ->first();

            if (! $existing instanceof AttemptInviteUnlock) {
                throw $e;
            }

            $fresh = $this->refreshProgressSnapshot($existing);

            return $this->buildInvitePayload($fresh);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function getInviteProgress(Attempt $targetAttempt): array
    {
        $targetOrgId = (int) ($targetAttempt->org_id ?? 0);
        $targetAttemptId = trim((string) ($targetAttempt->id ?? ''));
        $targetScaleCode = strtoupper(trim((string) ($targetAttempt->scale_code ?? '')));

        if ($targetAttemptId === '') {
            throw new ApiProblemException(422, 'INVITE_UNLOCK_TARGET_INVALID', 'target attempt is invalid.');
        }

        $existing = AttemptInviteUnlock::query()
            ->where('target_org_id', $targetOrgId)
            ->where('target_attempt_id', $targetAttemptId)
            ->first();

        if (! $existing instanceof AttemptInviteUnlock) {
            return [
                'has_invite' => false,
                'invite_id' => null,
                'invite_code' => null,
                'target_org_id' => $targetOrgId,
                'target_attempt_id' => $targetAttemptId,
                'target_scale_code' => $targetScaleCode !== '' ? $targetScaleCode : null,
                'status' => null,
                'required_invitees' => self::DEFAULT_REQUIRED_INVITEES,
                'completed_invitees' => 0,
                'qualification_rule_version' => self::RULE_VERSION,
                'expires_at' => null,
            ];
        }

        $fresh = $this->refreshProgressSnapshot($existing);

        return $this->buildInvitePayload($fresh);
    }

    public function refreshProgressSnapshot(AttemptInviteUnlock $invite): AttemptInviteUnlock
    {
        $count = AttemptInviteUnlockCompletion::query()
            ->where('invite_id', (string) $invite->id)
            ->where('counted', true)
            ->count();

        $required = max(1, (int) ($invite->required_invitees ?? self::DEFAULT_REQUIRED_INVITEES));
        $currentStatus = strtolower(trim((string) ($invite->status ?? InviteUnlockStatus::PENDING)));
        $nextStatus = $currentStatus;

        if (! in_array($currentStatus, [InviteUnlockStatus::EXPIRED, InviteUnlockStatus::CANCELLED], true)) {
            if ($count >= $required) {
                $nextStatus = InviteUnlockStatus::COMPLETED;
            } elseif ($count > 0) {
                $nextStatus = InviteUnlockStatus::IN_PROGRESS;
            } else {
                $nextStatus = InviteUnlockStatus::PENDING;
            }
        }

        $needsUpdate = $count !== (int) ($invite->completed_invitees ?? 0)
            || $nextStatus !== $currentStatus;

        if ($needsUpdate) {
            $invite->completed_invitees = $count;
            $invite->status = $nextStatus;
            $invite->save();
        }

        return $invite->refresh();
    }

    public function findByInviteCode(string $inviteCode): ?AttemptInviteUnlock
    {
        $normalized = trim($inviteCode);
        if ($normalized === '') {
            return null;
        }

        return AttemptInviteUnlock::query()
            ->where('invite_code', $normalized)
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    public function buildInvitePayload(AttemptInviteUnlock $invite): array
    {
        return [
            'has_invite' => true,
            'invite_id' => (string) $invite->id,
            'invite_code' => (string) $invite->invite_code,
            'target_org_id' => (int) ($invite->target_org_id ?? 0),
            'target_attempt_id' => (string) ($invite->target_attempt_id ?? ''),
            'target_scale_code' => (string) ($invite->target_scale_code ?? ''),
            'status' => (string) ($invite->status ?? InviteUnlockStatus::PENDING),
            'required_invitees' => (int) ($invite->required_invitees ?? self::DEFAULT_REQUIRED_INVITEES),
            'completed_invitees' => (int) ($invite->completed_invitees ?? 0),
            'qualification_rule_version' => (string) ($invite->qualification_rule_version ?? self::RULE_VERSION),
            'expires_at' => $invite->expires_at?->toISOString(),
        ];
    }

    public function areScaleFamiliesCompatible(string $targetScaleCode, string $inviteeScaleCode): bool
    {
        $target = strtoupper(trim($targetScaleCode));
        $invitee = strtoupper(trim($inviteeScaleCode));

        if ($target === '' || $invitee === '') {
            return false;
        }

        if ($target === 'MBTI') {
            return $invitee === 'MBTI';
        }

        if ($target === 'BIG5_OCEAN') {
            return $invitee === 'BIG5_OCEAN';
        }

        return false;
    }

    private function assertTargetAttemptEligible(Attempt $attempt, int $orgId, string $attemptId): void
    {
        if ($attempt->submitted_at === null) {
            throw new ApiProblemException(
                422,
                'INVITE_UNLOCK_TARGET_NOT_SUBMITTED',
                'invite unlock target attempt must be submitted.'
            );
        }

        $hasResult = Result::query()
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->exists();

        if (! $hasResult) {
            throw new ApiProblemException(
                422,
                'INVITE_UNLOCK_TARGET_RESULT_MISSING',
                'invite unlock target attempt must have a result.'
            );
        }
    }

    private function normalizeSupportedScaleCode(string $scaleCode): string
    {
        $normalized = strtoupper(trim($scaleCode));
        if (! in_array($normalized, self::SUPPORTED_SCALES, true)) {
            throw new ApiProblemException(
                422,
                'INVITE_UNLOCK_SCALE_NOT_SUPPORTED',
                'invite unlock currently supports MBTI and BIG5_OCEAN only.'
            );
        }

        return $normalized;
    }

    private function generateInviteCode(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $code = 'iul_'.Str::lower(Str::random(24));
            $exists = AttemptInviteUnlock::query()
                ->where('invite_code', $code)
                ->exists();
            if (! $exists) {
                return $code;
            }
        }

        return 'iul_'.Str::lower(Str::uuid()->toString());
    }

    private function normalizeUserId(?string $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $normalized = trim($userId);
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
