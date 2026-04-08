<?php

declare(strict_types=1);

namespace App\Domain\Career\Import;

use InvalidArgumentException;

final class ImportScopeMode
{
    public const EXACT = 'exact';

    public const TRUST_INHERITANCE = 'trust_inheritance';

    /**
     * @return list<string>
     */
    public static function parse(string $scopeOption): array
    {
        $parts = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $scopeOption)
        ), static fn (string $value): bool => $value !== ''));

        if ($parts === []) {
            throw new InvalidArgumentException('At least one scope mode is required.');
        }

        $modes = [];
        foreach ($parts as $part) {
            if (! in_array($part, [self::EXACT, self::TRUST_INHERITANCE], true)) {
                throw new InvalidArgumentException(sprintf('Unsupported scope mode [%s].', $part));
            }
            $modes[] = $part;
        }

        return array_values(array_unique($modes));
    }

    /**
     * @param  list<string>  $modes
     */
    public static function ledgerValue(array $modes): string
    {
        sort($modes);

        return match ($modes) {
            [self::EXACT] => 'first_wave_exact',
            [self::TRUST_INHERITANCE] => 'first_wave_trust_inheritance',
            default => 'first_wave_mixed',
        };
    }
}
