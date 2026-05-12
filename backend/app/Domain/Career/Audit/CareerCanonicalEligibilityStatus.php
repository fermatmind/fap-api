<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonicalEligibilityStatus
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const BLOCKED = 'blocked';

    public const WARNING = 'warning';

    public const UNVERIFIED = 'unverified';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::PASS,
            self::FAIL,
            self::BLOCKED,
            self::WARNING,
            self::UNVERIFIED,
        ];
    }

    public static function assertValid(string $value): string
    {
        if (! in_array($value, self::values(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career canonical eligibility status [%s].', $value));
        }

        return $value;
    }
}
