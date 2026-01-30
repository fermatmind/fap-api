<?php

namespace App\Support;

final class StableBucket
{
    public static function bucket(string $value, int $mod = 100): int
    {
        if ($mod <= 0) {
            return 0;
        }

        $hash = hash('sha256', $value);
        if (!is_string($hash) || $hash === '') {
            return 0;
        }

        $slice = substr($hash, 0, 8);
        $num = hexdec($slice);
        if (!is_int($num)) {
            $num = 0;
        }

        return $num % $mod;
    }
}
