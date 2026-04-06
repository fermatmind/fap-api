<?php

declare(strict_types=1);

namespace App\Services\Attempts\InviteUnlock;

use App\Services\Report\ReportAccess;

final class InviteUnlockDiagnostics
{
    /**
     * @return array{
     *   status:string,
     *   status_reason:string,
     *   completed_invitees:int,
     *   required_invitees:int,
     *   remaining_invitees:int,
     *   progress_ratio:float,
     *   progress_percent:int,
     *   unlock_stage:string,
     *   unlock_source:string,
     *   invite_status:?string,
     *   snapshot_at:string
     * }
     */
    public static function build(
        int $completedInvitees,
        int $requiredInvitees,
        string $unlockStage,
        string $unlockSource,
        ?string $inviteStatus = null
    ): array {
        $required = max(1, $requiredInvitees);
        $completed = max(0, min($required, $completedInvitees));
        $stage = ReportAccess::normalizeUnlockStage($unlockStage);
        $source = ReportAccess::normalizeUnlockSource($unlockSource);
        $remaining = max(0, $required - $completed);
        $ratio = round($completed / $required, 4);
        $percent = (int) round($ratio * 100);
        $status = self::resolveStatus($stage, $source);
        $statusReason = self::resolveStatusReason($stage, $source, $completed);
        $normalizedInviteStatus = self::normalizeInviteStatus($inviteStatus);

        return [
            'status' => $status,
            'status_reason' => $statusReason,
            'completed_invitees' => $completed,
            'required_invitees' => $required,
            'remaining_invitees' => $remaining,
            'progress_ratio' => $ratio,
            'progress_percent' => $percent,
            'unlock_stage' => $stage,
            'unlock_source' => $source,
            'invite_status' => $normalizedInviteStatus,
            'snapshot_at' => now()->toIso8601String(),
        ];
    }

    private static function resolveStatus(string $stage, string $source): string
    {
        if ($stage === ReportAccess::UNLOCK_STAGE_FULL && $source === ReportAccess::UNLOCK_SOURCE_MIXED) {
            return 'mixed_unlock';
        }

        if ($stage === ReportAccess::UNLOCK_STAGE_FULL) {
            return 'full_unlock';
        }

        if ($stage === ReportAccess::UNLOCK_STAGE_PARTIAL) {
            return 'partial_unlock';
        }

        return 'locked';
    }

    private static function resolveStatusReason(string $stage, string $source, int $completedInvitees): string
    {
        if ($stage === ReportAccess::UNLOCK_STAGE_FULL && $source === ReportAccess::UNLOCK_SOURCE_MIXED) {
            return 'unlock_stage_full_source_mixed';
        }

        if ($stage === ReportAccess::UNLOCK_STAGE_FULL) {
            return 'unlock_stage_full';
        }

        if ($stage === ReportAccess::UNLOCK_STAGE_PARTIAL) {
            return 'unlock_stage_partial';
        }

        if ($completedInvitees > 0) {
            return 'unlock_stage_locked_with_progress';
        }

        return 'unlock_stage_locked';
    }

    private static function normalizeInviteStatus(?string $inviteStatus): ?string
    {
        if ($inviteStatus === null) {
            return null;
        }

        $normalized = strtolower(trim($inviteStatus));

        return $normalized === '' ? null : $normalized;
    }
}
