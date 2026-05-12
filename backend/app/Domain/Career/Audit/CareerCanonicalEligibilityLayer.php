<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonicalEligibilityLayer
{
    public const ENTITY = 'entity';

    public const BASELINE = 'baseline';

    public const INDEX = 'index';

    public const RUNTIME = 'runtime';

    public const SEO_GEO = 'seo_geo';

    public const SURFACE = 'surface';

    public const SAFETY = 'safety';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::ENTITY,
            self::BASELINE,
            self::INDEX,
            self::RUNTIME,
            self::SEO_GEO,
            self::SURFACE,
            self::SAFETY,
        ];
    }

    public static function assertValid(string $value): string
    {
        if (! in_array($value, self::values(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career canonical eligibility layer [%s].', $value));
        }

        return $value;
    }
}
