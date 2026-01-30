<?php

declare(strict_types=1);

namespace App\Support;

final class RegionContext
{
    private string $region;
    private string $locale;
    private string $currency;

    public function __construct()
    {
        $defaultRegion = (string) (config('regions.default_region') ?? config('content_packs.default_region', 'CN_MAINLAND'));
        $defaultRegion = strtoupper(trim($defaultRegion)) !== '' ? strtoupper(trim($defaultRegion)) : 'CN_MAINLAND';

        $regions = config('regions.regions', []);
        $defaultLocale = '';
        $defaultCurrency = '';
        if (is_array($regions) && isset($regions[$defaultRegion]) && is_array($regions[$defaultRegion])) {
            $defaultLocale = (string) ($regions[$defaultRegion]['default_locale'] ?? '');
            $defaultCurrency = (string) ($regions[$defaultRegion]['currency'] ?? '');
        }

        if ($defaultLocale === '') {
            $defaultLocale = (string) config('content_packs.default_locale', 'zh-CN');
        }
        if ($defaultCurrency === '') {
            $defaultCurrency = 'CNY';
        }

        $this->region = $defaultRegion;
        $this->locale = $defaultLocale;
        $this->currency = $defaultCurrency;
    }

    public function set(string $region, string $locale, string $currency): void
    {
        $this->region = $region;
        $this->locale = $locale;
        $this->currency = $currency;
    }

    public function region(): string
    {
        return $this->region;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function currency(): string
    {
        return $this->currency;
    }
}
