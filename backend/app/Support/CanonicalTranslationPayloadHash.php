<?php

declare(strict_types=1);

namespace App\Support;

final class CanonicalTranslationPayloadHash
{
    public static function hash(mixed $payload): string
    {
        return hash('sha256', json_encode(
            self::sortObjectKeys($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    private static function sortObjectKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::sortObjectKeys($item);
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
