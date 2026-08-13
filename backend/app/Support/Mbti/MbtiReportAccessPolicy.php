<?php

declare(strict_types=1);

namespace App\Support\Mbti;

use App\Services\Report\ReportAccess;

final class MbtiReportAccessPolicy
{
    public const MODE_FREE_FULL = 'free_full';

    public const MODE_PAID_UNLOCK = 'paid_unlock';

    public static function mode(): string
    {
        $mode = strtolower(trim((string) config('report_unlock.mbti_access_mode', self::MODE_FREE_FULL)));

        return in_array($mode, [self::MODE_FREE_FULL, self::MODE_PAID_UNLOCK], true)
            ? $mode
            : self::MODE_PAID_UNLOCK;
    }

    public static function grantsFreeFull(string $scaleCode): bool
    {
        return strtoupper(trim($scaleCode)) === ReportAccess::SCALE_MBTI
            && self::mode() === self::MODE_FREE_FULL;
    }
}
