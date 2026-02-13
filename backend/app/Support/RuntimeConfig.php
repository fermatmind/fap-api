<?php

declare(strict_types=1);

namespace App\Support;

final class RuntimeConfig
{
    public static function value(mixed $key, mixed $default = null): mixed
    {
        $name = trim((string) $key);
        if ($name === '') {
            return $default;
        }

        return config("fap.runtime.{$name}", $default);
    }

    public static function raw(mixed $key): mixed
    {
        $name = trim((string) $key);
        if ($name === '') {
            return null;
        }

        return config("fap.runtime.{$name}");
    }
}
