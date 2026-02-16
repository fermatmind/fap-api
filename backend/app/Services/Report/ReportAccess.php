<?php

declare(strict_types=1);

namespace App\Services\Report;

final class ReportAccess
{
    public const VARIANT_FREE = 'free';
    public const VARIANT_FULL = 'full';

    public const REPORT_ACCESS_FREE = 'free';
    public const REPORT_ACCESS_FULL = 'full';

    public const CARD_ACCESS_FREE = 'free';
    public const CARD_ACCESS_PREVIEW = 'preview';
    public const CARD_ACCESS_PAID = 'paid';

    public const MODULE_CORE_FREE = 'core_free';
    public const MODULE_CORE_FULL = 'core_full';
    public const MODULE_CAREER = 'career';
    public const MODULE_RELATIONSHIPS = 'relationships';

    /**
     * Growth/traits/stress_recovery are part of core_full by default.
     */
    public const SECTION_TO_MODULE = [
        'traits' => self::MODULE_CORE_FULL,
        'growth' => self::MODULE_CORE_FULL,
        'stress_recovery' => self::MODULE_CORE_FULL,
        'career' => self::MODULE_CAREER,
        'relationships' => self::MODULE_RELATIONSHIPS,
    ];

    /**
     * @return list<string>
     */
    public static function defaultModulesAllowedForLocked(): array
    {
        return [self::MODULE_CORE_FREE];
    }

    /**
     * @return list<string>
     */
    public static function normalizeModules(array $modules): array
    {
        $out = [];
        foreach ($modules as $module) {
            $value = trim((string) $module);
            if ($value === '') {
                continue;
            }
            $out[strtolower($value)] = true;
        }

        return array_keys($out);
    }

    /**
     * @return list<string>
     */
    public static function allDefaultModulesOffered(): array
    {
        return [
            self::MODULE_CORE_FULL,
            self::MODULE_CAREER,
            self::MODULE_RELATIONSHIPS,
        ];
    }

    public static function normalizeVariant(?string $variant): string
    {
        $variant = strtolower(trim((string) $variant));

        return $variant === self::VARIANT_FREE
            ? self::VARIANT_FREE
            : self::VARIANT_FULL;
    }

    public static function normalizeReportAccessLevel(?string $level): string
    {
        $level = strtolower(trim((string) $level));

        return $level === self::REPORT_ACCESS_FREE
            ? self::REPORT_ACCESS_FREE
            : self::REPORT_ACCESS_FULL;
    }

    public static function normalizeCardAccessLevel(?string $level): string
    {
        $level = strtolower(trim((string) $level));

        return match ($level) {
            self::CARD_ACCESS_FREE => self::CARD_ACCESS_FREE,
            self::CARD_ACCESS_PREVIEW => self::CARD_ACCESS_PREVIEW,
            self::CARD_ACCESS_PAID => self::CARD_ACCESS_PAID,
            default => self::CARD_ACCESS_PAID,
        };
    }

    public static function defaultModuleCodeForSection(string $section): string
    {
        $section = strtolower(trim($section));

        return self::SECTION_TO_MODULE[$section] ?? self::MODULE_CORE_FULL;
    }

    /**
     * @return list<string>
     */
    public static function allowedCardLevelsForVariant(string $variant): array
    {
        $variant = self::normalizeVariant($variant);

        if ($variant === self::VARIANT_FREE) {
            return [self::CARD_ACCESS_FREE, self::CARD_ACCESS_PREVIEW];
        }

        return [self::CARD_ACCESS_FREE, self::CARD_ACCESS_PREVIEW, self::CARD_ACCESS_PAID];
    }
}
