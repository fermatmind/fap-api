<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonicalEligibilitySeverity
{
    public const INFO = 'info';

    public const LOW = 'low';

    public const MEDIUM = 'medium';

    public const HIGH = 'high';

    public const BLOCKER_FOR_PUBLICATION = 'blocker_for_publication';

    public const BLOCKER_FOR_FULL_2786_CLAIM = 'blocker_for_full_2786_claim';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::INFO,
            self::LOW,
            self::MEDIUM,
            self::HIGH,
            self::BLOCKER_FOR_PUBLICATION,
            self::BLOCKER_FOR_FULL_2786_CLAIM,
        ];
    }

    public static function assertValid(string $value): string
    {
        if (! in_array($value, self::values(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career canonical eligibility severity [%s].', $value));
        }

        return $value;
    }

    public static function blocksInsideCurrentPr(string $value): bool
    {
        self::assertValid($value);

        return in_array($value, [
            self::HIGH,
            self::BLOCKER_FOR_PUBLICATION,
            self::BLOCKER_FOR_FULL_2786_CLAIM,
        ], true);
    }
}
