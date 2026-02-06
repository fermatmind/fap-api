<?php

namespace App\Support\Commerce;

/**
 * @deprecated Use App\Services\Commerce\SkuCatalog instead.
 */
final class SkuContract
{
    public static function normalizeSku(string $sku): string
    {
        $sku = strtoupper(trim($sku));
        return $sku !== '' ? $sku : '';
    }
}
