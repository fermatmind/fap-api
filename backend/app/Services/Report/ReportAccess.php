<?php

declare(strict_types=1);

namespace App\Services\Report;

final class ReportAccess
{
    public const ACCESS_HUB_KEY = 'mbti_access_hub_v1';

    public const ACCESS_HUB_STATE_LOCKED = 'locked';

    public const ACCESS_HUB_STATE_READY = 'ready';

    public const ACCESS_HUB_STATE_PENDING = 'pending';

    public const ACCESS_HUB_STATE_RECOVERY_AVAILABLE = 'recovery_available';

    public const ACCESS_HUB_SOURCE_REPORT_GATE = 'report_gate';

    public const ACCESS_HUB_SOURCE_ORDER_DELIVERY = 'order_delivery';

    public const ACCESS_HUB_SOURCE_ATTEMPT_PDF = 'attempt_pdf';

    public const ACCESS_HUB_SOURCE_NONE = 'none';

    public const ACCESS_HUB_ENTRY_KIND_MBTI_HISTORY = 'mbti_history';

    public const SCALE_MBTI = 'MBTI';

    public const SCALE_BIG5_OCEAN = 'BIG5_OCEAN';

    public const SCALE_CLINICAL_COMBO_68 = 'CLINICAL_COMBO_68';

    public const SCALE_SDS_20 = 'SDS_20';

    public const SCALE_EQ_60 = 'EQ_60';

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

    public const MODULE_BIG5_CORE = 'big5_core';

    public const MODULE_BIG5_FULL = 'big5_full';

    public const MODULE_BIG5_ACTION_PLAN = 'big5_action_plan';

    public const MODULE_CLINICAL_CORE = 'clinical_core';

    public const MODULE_CLINICAL_FULL = 'clinical_full';

    public const MODULE_CLINICAL_RESILIENCE = 'clinical_resilience';

    public const MODULE_CLINICAL_PERFECTIONISM = 'clinical_perfectionism';

    public const MODULE_CLINICAL_ACTION_PLAN = 'clinical_action_plan';

    public const MODULE_SDS_CORE = 'sds_core';

    public const MODULE_SDS_FULL = 'sds_full';

    public const MODULE_SDS_FACTOR_DEEPDIVE = 'sds_factor_deepdive';

    public const MODULE_SDS_ACTION_PLAN = 'sds_action_plan';

    public const MODULE_EQ_CORE = 'eq_core';

    public const MODULE_EQ_FULL = 'eq_full';

    public const MODULE_EQ_CROSS_INSIGHTS = 'eq_cross_insights';

    public const MODULE_EQ_GROWTH_PLAN = 'eq_growth_plan';

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
    public static function defaultModulesAllowedForLocked(?string $scaleCode = null): array
    {
        $scaleCode = strtoupper(trim((string) $scaleCode));
        if ($scaleCode === self::SCALE_BIG5_OCEAN) {
            return [self::MODULE_BIG5_CORE];
        }
        if ($scaleCode === self::SCALE_CLINICAL_COMBO_68) {
            return [self::MODULE_CLINICAL_CORE];
        }
        if ($scaleCode === self::SCALE_SDS_20) {
            return [self::MODULE_SDS_CORE];
        }
        if ($scaleCode === self::SCALE_EQ_60) {
            return [self::MODULE_EQ_CORE];
        }

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
    public static function allDefaultModulesOffered(?string $scaleCode = null): array
    {
        $scaleCode = strtoupper(trim((string) $scaleCode));
        if ($scaleCode === self::SCALE_BIG5_OCEAN) {
            return [
                self::MODULE_BIG5_FULL,
                self::MODULE_BIG5_ACTION_PLAN,
            ];
        }
        if ($scaleCode === self::SCALE_CLINICAL_COMBO_68) {
            return [
                self::MODULE_CLINICAL_FULL,
                self::MODULE_CLINICAL_RESILIENCE,
                self::MODULE_CLINICAL_PERFECTIONISM,
                self::MODULE_CLINICAL_ACTION_PLAN,
            ];
        }
        if ($scaleCode === self::SCALE_SDS_20) {
            return [
                self::MODULE_SDS_FULL,
                self::MODULE_SDS_FACTOR_DEEPDIVE,
                self::MODULE_SDS_ACTION_PLAN,
            ];
        }
        if ($scaleCode === self::SCALE_EQ_60) {
            return [
                self::MODULE_EQ_FULL,
                self::MODULE_EQ_CROSS_INSIGHTS,
                self::MODULE_EQ_GROWTH_PLAN,
            ];
        }

        return [
            self::MODULE_CORE_FULL,
            self::MODULE_CAREER,
            self::MODULE_RELATIONSHIPS,
        ];
    }

    public static function freeModuleForScale(?string $scaleCode = null): string
    {
        $scaleCode = strtoupper(trim((string) $scaleCode));

        return match ($scaleCode) {
            self::SCALE_BIG5_OCEAN => self::MODULE_BIG5_CORE,
            self::SCALE_CLINICAL_COMBO_68 => self::MODULE_CLINICAL_CORE,
            self::SCALE_SDS_20 => self::MODULE_SDS_CORE,
            self::SCALE_EQ_60 => self::MODULE_EQ_CORE,
            default => self::MODULE_CORE_FREE,
        };
    }

    public static function fullModuleForScale(?string $scaleCode = null): string
    {
        $scaleCode = strtoupper(trim((string) $scaleCode));

        return match ($scaleCode) {
            self::SCALE_BIG5_OCEAN => self::MODULE_BIG5_FULL,
            self::SCALE_CLINICAL_COMBO_68 => self::MODULE_CLINICAL_FULL,
            self::SCALE_SDS_20 => self::MODULE_SDS_FULL,
            self::SCALE_EQ_60 => self::MODULE_EQ_FULL,
            default => self::MODULE_CORE_FULL,
        };
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
