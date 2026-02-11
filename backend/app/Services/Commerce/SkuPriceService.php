<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Exceptions\InvalidSkuException;
use App\Models\Sku;

final class SkuPriceService
{
    public function getPrice(string $sku, string $currency): int
    {
        $sku = strtoupper(trim($sku));
        $currency = strtoupper(trim($currency));

        if ($sku === '' || $currency === '') {
            throw new InvalidSkuException($sku, $currency);
        }

        $row = Sku::query()
            ->where('sku', $sku)
            ->where('currency', $currency)
            ->where('is_active', 1)
            ->first();
        if (!$row) {
            throw new InvalidSkuException($sku, $currency);
        }

        return (int) ($row->price_cents ?? 0);
    }
}
