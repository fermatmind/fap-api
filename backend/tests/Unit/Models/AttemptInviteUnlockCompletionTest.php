<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\AttemptInviteUnlockCompletion;
use App\Services\Attempts\InviteUnlock\InviteUnlockCompletionStatus;
use PHPUnit\Framework\TestCase;

final class AttemptInviteUnlockCompletionTest extends TestCase
{
    public function test_all_existing_statuses_round_trip_through_the_legacy_columns(): void
    {
        foreach (InviteUnlockCompletionStatus::all() as $status) {
            $reason = InviteUnlockCompletionStatus::toQualifiedReason($status);
            $completion = new AttemptInviteUnlockCompletion([
                'qualified_reason' => $reason,
                'qualification_status' => $status,
            ]);
            $stored = $completion->getAttributes();
            $this->assertLessThanOrEqual(32, strlen($stored['qualification_status']));
            $this->assertSame($reason, $stored['qualified_reason']);
            $this->assertSame($status, $completion->qualification_status);
            $readBack = (new AttemptInviteUnlockCompletion)->newFromBuilder($stored);
            $this->assertSame($status, $readBack->qualification_status);
        }
    }

    public function test_legacy_rejection_without_the_exact_full_reason_is_unchanged(): void
    {
        $this->assertSame(
            InviteUnlockCompletionStatus::REJECTED_INVALID_ATTEMPT,
            InviteUnlockCompletionStatus::fromStoredStatus(InviteUnlockCompletionStatus::REJECTED_INVALID_ATTEMPT, null),
        );
        $this->assertSame(
            InviteUnlockCompletionStatus::REJECTED_SELF_REFERRAL,
            InviteUnlockCompletionStatus::fromStoredStatus(InviteUnlockCompletionStatus::REJECTED_SELF_REFERRAL, InviteUnlockCompletionStatus::REJECTED_NOT_SUBMITTED_OR_RESULT_MISSING),
        );
    }

    public function test_unknown_statuses_are_never_silently_truncated(): void
    {
        $status = str_repeat('unknown', 10);
        $this->assertSame($status, InviteUnlockCompletionStatus::toStoredStatus($status));
    }
}
