<?php

declare(strict_types=1);

namespace App\Support\Mbti;

final class MbtiAxisStrengthBand
{
    public static function fromDominantPercent(?int $dominantPct, ?string $state): ?string
    {
        if (! is_int($dominantPct)) {
            return null;
        }

        return self::fromDeltaAndState((int) round(abs($dominantPct - 50)), $state);
    }

    public static function fromDeltaAndState(int $delta, ?string $state): string
    {
        $normalizedState = strtolower(trim((string) $state));

        if ($delta < 12) {
            return 'boundary';
        }

        if ($delta >= 40 || str_contains($normalizedState, 'very')) {
            return 'very_strong';
        }

        if ($delta >= 25 || $normalizedState === 'strong') {
            return 'strong';
        }

        return 'clear';
    }
}
