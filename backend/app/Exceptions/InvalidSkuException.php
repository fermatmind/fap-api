<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class InvalidSkuException extends RuntimeException
{
    public const ERROR_CODE = 'INVALID_SKU';

    public function __construct(?string $sku = null, ?string $currency = null)
    {
        $skuPart = strtoupper(trim((string) ($sku ?? '')));
        $currencyPart = strtoupper(trim((string) ($currency ?? '')));

        $skuPart = $skuPart !== '' ? $skuPart : 'N/A';
        $currencyPart = $currencyPart !== '' ? $currencyPart : 'N/A';

        parent::__construct("invalid sku. sku={$skuPart}, currency={$currencyPart}");
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }
}
