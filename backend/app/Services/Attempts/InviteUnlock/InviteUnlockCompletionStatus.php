<?php

declare(strict_types=1);

namespace App\Services\Attempts\InviteUnlock;

final class InviteUnlockCompletionStatus
{
    public const PENDING_VALIDATION = 'pending_validation';

    public const QUALIFIED_COUNTED = 'qualified_counted';

    public const REJECTED_SELF_REFERRAL = 'rejected_self_referral';

    public const REJECTED_DUPLICATE_INVITEE = 'rejected_duplicate_invitee';

    public const REJECTED_DUPLICATE_COMPLETION = 'rejected_duplicate_completion';

    public const REJECTED_INVALID_ATTEMPT = 'rejected_invalid_attempt';

    public const REJECTED_NOT_SUBMITTED_OR_RESULT_MISSING = 'rejected_not_submitted_or_result_missing';

    public const REJECTED_SCALE_MISMATCH = 'rejected_scale_mismatch';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::PENDING_VALIDATION,
            self::QUALIFIED_COUNTED,
            self::REJECTED_SELF_REFERRAL,
            self::REJECTED_DUPLICATE_INVITEE,
            self::REJECTED_DUPLICATE_COMPLETION,
            self::REJECTED_INVALID_ATTEMPT,
            self::REJECTED_NOT_SUBMITTED_OR_RESULT_MISSING,
            self::REJECTED_SCALE_MISMATCH,
        ];
    }

    public static function toQualifiedReason(string $status): ?string
    {
        $normalized = strtolower(trim($status));

        return $normalized === self::PENDING_VALIDATION ? null : $normalized;
    }

    public static function toStoredStatus(string $status): string
    {
        // The legacy status column is 32 characters; qualified_reason retains the full v1 rejection.
        return $status === self::REJECTED_NOT_SUBMITTED_OR_RESULT_MISSING
            ? self::REJECTED_INVALID_ATTEMPT
            : $status;
    }

    public static function fromStoredStatus(string $status, ?string $reason): string
    {
        return $status === self::REJECTED_INVALID_ATTEMPT && $reason === self::REJECTED_NOT_SUBMITTED_OR_RESULT_MISSING
            ? self::REJECTED_NOT_SUBMITTED_OR_RESULT_MISSING
            : $status;
    }
}
