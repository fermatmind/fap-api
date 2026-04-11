<?php

declare(strict_types=1);

namespace App\Domain\Career\Transition;

enum TransitionPathType: string
{
    case StableUpside = 'stable_upside';

    public static function tryNormalize(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim(strtolower($value));
        if ($normalized === '') {
            return null;
        }

        return self::tryFrom($normalized);
    }
}
