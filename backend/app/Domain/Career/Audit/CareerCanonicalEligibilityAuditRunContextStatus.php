<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonicalEligibilityAuditRunContextStatus
{
    public const SUPPLIED = 'supplied';

    public const MISSING = 'missing';

    public const OPTIONAL = 'optional';

    public const NOT_REQUESTED = 'not_requested';

    public const REQUIRES_APPROVAL = 'requires_approval';

    public const UNVERIFIED = 'unverified';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::SUPPLIED,
            self::MISSING,
            self::OPTIONAL,
            self::NOT_REQUESTED,
            self::REQUIRES_APPROVAL,
            self::UNVERIFIED,
        ];
    }

    public static function assertValid(string $value): void
    {
        if (! in_array($value, self::values(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career audit run context status [%s].', $value));
        }
    }
}
