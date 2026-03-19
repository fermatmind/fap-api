<?php

declare(strict_types=1);

namespace App\Services\Payments;

final class PaymentRouter
{
    public function __construct(
        private PaymentProviderRegistry $providers,
    ) {}

    public function methodsForRegion(string $region): array
    {
        $region = $this->normalizeRegion($region);
        $allowed = $this->providers->enabledProviders();

        $methods = [];
        foreach ($this->prioritizedMethodsForRegion($region) as $method) {
            if ($method !== '' && in_array($method, $allowed, true)) {
                $methods[] = $method;
            }
        }

        $methods = array_values(array_unique($methods));
        if ($methods !== []) {
            return $methods;
        }

        $fallback = strtolower(trim((string) config('payments.fallback_provider', 'billing')));
        if ($fallback !== '' && in_array($fallback, $allowed, true)) {
            return [$fallback];
        }

        return $allowed !== [] ? [$allowed[0]] : [];
    }

    public function primaryProviderForRegion(string $region): string
    {
        $methods = $this->methodsForRegion($region);

        return $methods[0] ?? '';
    }

    /**
     * @return array<int, string>
     */
    private function prioritizedMethodsForRegion(string $region): array
    {
        $priority = config('payments.provider_priority', []);
        $methods = [];

        $override = strtolower(trim((string) config('payments.primary_provider_overrides.'.$region, '')));
        if ($override !== '') {
            $methods[] = $override;
        }

        if (is_array($priority) && isset($priority[$region]) && is_array($priority[$region])) {
            foreach ($priority[$region] as $method) {
                if (! is_string($method)) {
                    continue;
                }

                $method = strtolower(trim($method));
                if ($method !== '') {
                    $methods[] = $method;
                }
            }
        }

        return array_values(array_unique($methods));
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
