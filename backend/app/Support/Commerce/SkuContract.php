<?php

namespace App\Support\Commerce;

class SkuContract
{
    public const UPGRADE_SKU_ANCHOR = 'MBTI_REPORT_FULL';

    public const SKU_REPORT_FULL_199 = 'MBTI_REPORT_FULL_199';
    public const SKU_PRO_MONTH_599 = 'MBTI_PRO_MONTH_599';
    public const SKU_PRO_YEAR_1999 = 'MBTI_PRO_YEAR_1999';
    public const SKU_GIFT_PACK_2990 = 'MBTI_GIFT_PACK_2990';

    public const ENTITLEMENT_REPORT_FULL = 'report_full';
    public const ENTITLEMENT_PRO = 'mbti_pro';
    public const ENTITLEMENT_GIFT = 'mbti_gift_credits';

    public static function effectiveSkus(): array
    {
        return [
            self::SKU_REPORT_FULL_199,
            self::SKU_PRO_MONTH_599,
            self::SKU_PRO_YEAR_1999,
            self::SKU_GIFT_PACK_2990,
        ];
    }

    public static function defaultEffectiveSku(): string
    {
        return self::SKU_REPORT_FULL_199;
    }

    public static function anchorUpgradeSku(): string
    {
        return self::UPGRADE_SKU_ANCHOR;
    }

    public static function anchorForSku(string $sku): ?string
    {
        $sku = self::normalizeSku($sku);
        if ($sku === '') {
            return null;
        }

        if ($sku === self::UPGRADE_SKU_ANCHOR) {
            return self::UPGRADE_SKU_ANCHOR;
        }

        if ($sku === self::SKU_REPORT_FULL_199) {
            return self::UPGRADE_SKU_ANCHOR;
        }

        return null;
    }

    public static function entitlementIdForSku(string $sku): ?string
    {
        $sku = self::normalizeSku($sku);
        if ($sku === '') {
            return null;
        }

        if ($sku === self::UPGRADE_SKU_ANCHOR || $sku === self::SKU_REPORT_FULL_199) {
            return self::ENTITLEMENT_REPORT_FULL;
        }

        if ($sku === self::SKU_PRO_MONTH_599 || $sku === self::SKU_PRO_YEAR_1999) {
            return self::ENTITLEMENT_PRO;
        }

        if ($sku === self::SKU_GIFT_PACK_2990) {
            return self::ENTITLEMENT_GIFT;
        }

        return null;
    }

    public static function normalizeRequestedSku(string $requestedSku): array
    {
        $requestedSku = self::normalizeSku($requestedSku);
        $effectiveSku = $requestedSku;
        $anchorSku = self::anchorForSku($requestedSku);

        if ($requestedSku === self::UPGRADE_SKU_ANCHOR) {
            $effectiveSku = self::SKU_REPORT_FULL_199;
            $anchorSku = self::UPGRADE_SKU_ANCHOR;
        }

        $entitlementId = self::entitlementIdForSku($effectiveSku !== '' ? $effectiveSku : $requestedSku);

        return [
            'requested_sku' => $requestedSku !== '' ? $requestedSku : null,
            'effective_sku' => $effectiveSku !== '' ? $effectiveSku : null,
            'anchor_sku' => $anchorSku,
            'entitlement_id' => $entitlementId,
        ];
    }

    public static function offers(): array
    {
        return [
            [
                'sku' => self::SKU_REPORT_FULL_199,
                'price_cents' => 199,
                'currency' => 'CNY',
                'title' => 'MBTI Full Report',
                'entitlement_id' => self::ENTITLEMENT_REPORT_FULL,
                'grant' => [
                    'type' => 'report_unlock',
                    'qty' => 1,
                    'period_days' => null,
                ],
            ],
            [
                'sku' => self::SKU_PRO_MONTH_599,
                'price_cents' => 599,
                'currency' => 'CNY',
                'title' => 'MBTI Pro Month',
                'entitlement_id' => self::ENTITLEMENT_PRO,
                'grant' => [
                    'type' => 'pro_access',
                    'qty' => 1,
                    'period_days' => 30,
                ],
            ],
            [
                'sku' => self::SKU_PRO_YEAR_1999,
                'price_cents' => 1999,
                'currency' => 'CNY',
                'title' => 'MBTI Pro Year',
                'entitlement_id' => self::ENTITLEMENT_PRO,
                'grant' => [
                    'type' => 'pro_access',
                    'qty' => 1,
                    'period_days' => 365,
                ],
            ],
            [
                'sku' => self::SKU_GIFT_PACK_2990,
                'price_cents' => 2990,
                'currency' => 'CNY',
                'title' => 'MBTI Gift Pack',
                'entitlement_id' => self::ENTITLEMENT_GIFT,
                'grant' => [
                    'type' => 'gift_credits',
                    'qty' => 1,
                    'period_days' => null,
                ],
            ],
        ];
    }

    private static function normalizeSku(string $sku): string
    {
        $sku = strtoupper(trim($sku));
        return $sku !== '' ? $sku : '';
    }
}
