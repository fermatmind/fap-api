<?php

declare(strict_types=1);

namespace App\Services\Payments;

final class PaymentRouter
{
    public function methodsForRegion(string $region): array
    {
        $region = $this->normalizeRegion($region);

        $priority = config('payments.provider_priority', []);
        $methods = [];
        if (is_array($priority) && isset($priority[$region]) && is_array($priority[$region])) {
            foreach ($priority[$region] as $method) {
                if (is_string($method) && trim($method) !== '') {
                    $methods[] = trim($method);
                }
            }
        }

        $methods = array_values(array_unique($methods));
        if ($methods !== []) {
            return $methods;
        }

        $fallback = (string) config('payments.fallback_provider', 'billing');
        return $fallback !== '' ? [$fallback] : [];
    }

    public function primaryProviderForRegion(string $region): string
    {
        $methods = $this->methodsForRegion($region);
        if ($methods !== []) {
            return (string) $methods[0];
        }

        return (string) config('payments.fallback_provider', 'billing');
    }

    private function normalizeRegion(string $region): string
    {
        $region = strtoupper(trim($region));
        if ($region !== '') {
            return $region;
        }

        $default = (string) (config('regions.default_region') ?? config('content_packs.default_region', 'CN_MAINLAND'));
        $default = strtoupper(trim($default));
        return $default !== '' ? $default : 'CN_MAINLAND';
    }
}
