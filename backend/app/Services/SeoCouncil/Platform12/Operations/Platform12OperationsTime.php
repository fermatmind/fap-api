<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Operations;

use Carbon\CarbonImmutable;
use Throwable;

/** Read-side conversions only. Never infer a zone for receipt timestamps. */
final class Platform12OperationsTime
{
    public static function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            return null;
        }
        try {
            $time = CarbonImmutable::parse($value);
            $errors = CarbonImmutable::getLastErrors();

            return $errors !== false && ($errors['warning_count'] || $errors['error_count']) ? null : $time->utc();
        } catch (Throwable) {
            return null;
        }
    }

    public static function iso(?CarbonImmutable $value): ?string
    {
        return $value?->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** Only for columns whose writer explicitly uses UTC DATETIME. */
    public static function utcDatetime(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) === 1
            ? self::instant(str_replace(' ', 'T', $value).'+00:00') : null;
    }

    public static function display(mixed $value): ?string
    {
        $time = self::instant($value);

        return $time === null ? null : $time->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s').' Asia/Shanghai (UTC+08:00)';
    }
}
