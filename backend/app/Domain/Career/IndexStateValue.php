<?php

declare(strict_types=1);

namespace App\Domain\Career;

final class IndexStateValue
{
    public const UNAVAILABLE = 'unavailable';

    public const TRUST_LIMITED = 'trust_limited';

    public const NOINDEX = 'noindex';

    public const INDEXABLE = 'indexable';

    public const PROMOTION_CANDIDATE = 'promotion_candidate';

    public const INDEXED = 'indexed';

    public const DEMOTED = 'demoted';

    public static function publicFacing(string $state, bool $indexEligible): string
    {
        $normalized = strtolower(trim($state));

        return match ($normalized) {
            self::INDEXED => self::INDEXABLE,
            self::PROMOTION_CANDIDATE => self::TRUST_LIMITED,
            self::DEMOTED => self::NOINDEX,
            default => $indexEligible && $normalized === '' ? self::INDEXABLE : ($normalized !== '' ? $normalized : self::NOINDEX),
        };
    }

    public static function isIndexedLike(string $state, bool $indexEligible): bool
    {
        if (! $indexEligible) {
            return false;
        }

        return in_array(strtolower(trim($state)), [self::INDEXABLE, self::INDEXED], true);
    }

    public static function isIndexRestricted(string $state, bool $indexEligible): bool
    {
        if (! $indexEligible) {
            return true;
        }

        return in_array(
            self::publicFacing($state, $indexEligible),
            [self::TRUST_LIMITED, self::NOINDEX, self::UNAVAILABLE],
            true,
        );
    }

    public static function isIndexBlocked(string $state, bool $indexEligible): bool
    {
        return in_array(
            self::publicFacing($state, $indexEligible),
            [self::NOINDEX, self::UNAVAILABLE],
            true,
        );
    }

    public static function wasIndexedOrCandidate(string $state): bool
    {
        return in_array(
            strtolower(trim($state)),
            [self::INDEXABLE, self::INDEXED, self::PROMOTION_CANDIDATE],
            true,
        );
    }
}
