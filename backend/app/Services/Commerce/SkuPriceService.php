<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Exceptions\InvalidSkuException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SkuPriceService
{
    public function getPrice(string $sku, string $currency, int $orgId = 1): int
    {
        if (!Schema::hasTable('skus')) {
            throw new InvalidSkuException();
        }

        $sku = strtoupper(trim($sku));
        $currency = strtoupper(trim($currency));

        if ($sku === '' || $currency === '') {
            throw new InvalidSkuException();
        }

        $query = DB::table('skus')
            ->where('sku', $sku)
            ->where('currency', $currency)
            ->where('is_active', 1);

        if (Schema::hasColumn('skus', 'org_id')) {
            $query->where('org_id', $orgId);
        }

        $row = $query->first();
        if (!$row) {
            throw new InvalidSkuException();
        }

        return (int) ($row->price_cents ?? 0);
    }
}
