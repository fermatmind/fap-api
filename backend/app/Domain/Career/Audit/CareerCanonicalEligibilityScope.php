<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonicalEligibilityScope
{
    public const ALL = 'all';

    public const BATCH = 'batch';

    public const SLUGS = 'slugs';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::ALL,
            self::BATCH,
            self::SLUGS,
        ];
    }

    public static function assertValid(string $value): string
    {
        if (! in_array($value, self::values(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career canonical eligibility scope [%s].', $value));
        }

        return $value;
    }
}
