<?php

declare(strict_types=1);

namespace App\Services\Analytics;

final class MbtiLandingAttribution
{
    public const CLICK_EVENT = 'mbti_landing_cta_click';

    public static function path(mixed $value): ?string
    {
        return is_string($value) && preg_match('~^/(en|zh|zh-TW)/tests/mbti-personality-test-16-personality-types\z~', $value) === 1
            ? $value
            : null;
    }

    public static function form(mixed $value): ?string
    {
        return in_array($value, ['mbti_93', 'mbti_144'], true) ? $value : null;
    }
}
