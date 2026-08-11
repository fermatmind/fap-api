<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

final class CareerGenerationCanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::sortRecursively($value),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    public static function sha256(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    /** @param list<string> $values */
    public static function setSha256(array $values): string
    {
        $normalized = array_values(array_unique($values));
        sort($normalized, SORT_STRING);

        return hash('sha256', implode("\n", $normalized)."\n");
    }

    private static function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $child) {
            $value[$key] = self::sortRecursively($child);
        }

        return $value;
    }
}
