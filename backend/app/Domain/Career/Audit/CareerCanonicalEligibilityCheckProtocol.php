<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonicalEligibilityCheckProtocol
{
    public const STATE_PENDING = 'pending';

    public const STATE_FAILED = 'failed';

    public const STATE_GREEN = 'green';

    public const ACTION_WAIT_OR_POLL = 'wait_or_poll';

    public const ACTION_INSPECT_FAILURE = 'inspect_failure';

    public const ACTION_CONTINUE = 'continue';

    public const TRAIN_CONTINUE_YES = 'YES';

    public const TRAIN_CONTINUE_WAITING_FOR_CHECKS = 'WAITING_FOR_CHECKS';

    /**
     * @return list<string>
     */
    public static function states(): array
    {
        return [
            self::STATE_PENDING,
            self::STATE_FAILED,
            self::STATE_GREEN,
        ];
    }

    public static function actionForState(string $state): string
    {
        return match (self::assertValidState($state)) {
            self::STATE_PENDING => self::ACTION_WAIT_OR_POLL,
            self::STATE_FAILED => self::ACTION_INSPECT_FAILURE,
            self::STATE_GREEN => self::ACTION_CONTINUE,
        };
    }

    public static function isImmediateStop(string $state): bool
    {
        return self::assertValidState($state) === self::STATE_FAILED;
    }

    public static function trainContinueForState(string $state): string
    {
        return match (self::assertValidState($state)) {
            self::STATE_PENDING => self::TRAIN_CONTINUE_WAITING_FOR_CHECKS,
            self::STATE_GREEN => self::TRAIN_CONTINUE_YES,
            self::STATE_FAILED => self::ACTION_INSPECT_FAILURE,
        };
    }

    public static function assertValidState(string $state): string
    {
        if (! in_array($state, self::states(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career canonical eligibility check state [%s].', $state));
        }

        return $state;
    }
}
