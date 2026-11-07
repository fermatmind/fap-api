<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class TestMetricsDay
{
    // Keep historical UTC buckets, ending the last one at the Beijing cutover.
    public const BEIJING_CUTOVER = '2026-11-07';

    private const TIMEZONE = 'Asia/Shanghai';

    public static function dateFor(DateTimeInterface|string $timestamp): string
    {
        $utc = CarbonImmutable::parse($timestamp, 'UTC')->utc();
        $beijingDate = $utc->setTimezone(self::TIMEZONE)->toDateString();

        return $beijingDate >= self::BEIJING_CUTOVER ? $beijingDate : $utc->toDateString();
    }

    public static function startsAtUtc(string $date): CarbonImmutable
    {
        $timezone = $date >= self::BEIJING_CUTOVER ? self::TIMEZONE : 'UTC';

        return CarbonImmutable::parse($date, $timezone)->startOfDay()->utc();
    }

    public static function refreshDescription(?string $refreshedAt): ?string
    {
        if ($refreshedAt === null || $refreshedAt === '') {
            return null;
        }

        return __('ops.widgets.test_kpi_refresh_time', [
            'time' => CarbonImmutable::parse($refreshedAt, 'UTC')
                ->setTimezone(self::TIMEZONE)
                ->format('Y-m-d H:i:s'),
        ]);
    }
}
