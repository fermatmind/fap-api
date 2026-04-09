<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

final class WaveClassification
{
    public const STABLE = 'stable';

    public const CANDIDATE = 'candidate';

    public const HOLD = 'hold';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::STABLE,
            self::CANDIDATE,
            self::HOLD,
        ];
    }
}
