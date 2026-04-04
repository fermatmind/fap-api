<?php

declare(strict_types=1);

namespace App\Services\Report;

final class InviteUnlockSummaryBuilder
{
    /**
     * @return array{
     *   unlock_stage:string,
     *   unlock_source:string,
     *   completed_invitees:int,
     *   required_invitees:int,
     *   partial_scope:?string,
     *   label:string,
     *   short_label:string
     * }
     */
    public function build(
        string $scaleCode,
        string $unlockStage,
        string $unlockSource,
        int $completedInvitees,
        int $requiredInvitees = 2
    ): array {
        $normalizedScaleCode = strtoupper(trim($scaleCode));
        $normalizedStage = ReportAccess::normalizeUnlockStage($unlockStage);
        $normalizedSource = ReportAccess::normalizeUnlockSource($unlockSource);
        $required = max(1, $requiredInvitees);
        $completed = max(0, min($required, $completedInvitees));
        $partialScope = $normalizedScaleCode === ReportAccess::SCALE_MBTI ? 'career' : null;

        $label = "Invite unlock {$completed}/{$required}";
        $shortLabel = "Invite {$completed}/{$required}";

        if ($normalizedStage === ReportAccess::UNLOCK_STAGE_PARTIAL) {
            $label = $partialScope === 'career'
                ? "Invite unlock {$completed}/{$required} · Career unlocked"
                : "Invite unlock {$completed}/{$required} · Partial unlocked";
            $shortLabel = "Invite unlock {$completed}/{$required}";
        } elseif ($normalizedStage === ReportAccess::UNLOCK_STAGE_FULL) {
            if ($normalizedSource === ReportAccess::UNLOCK_SOURCE_PAYMENT) {
                $label = 'Paid unlock active';
                $shortLabel = 'Paid unlock';
            } elseif ($normalizedSource === ReportAccess::UNLOCK_SOURCE_MIXED) {
                $label = 'Invite + payment unlock active';
                $shortLabel = 'Invite + payment';
            } else {
                $label = "Invite unlock completed {$completed}/{$required}";
                $shortLabel = 'Invite unlock completed';
            }
        }

        return [
            'unlock_stage' => $normalizedStage,
            'unlock_source' => $normalizedSource,
            'completed_invitees' => $completed,
            'required_invitees' => $required,
            'partial_scope' => $partialScope,
            'label' => $label,
            'short_label' => $shortLabel,
        ];
    }
}
