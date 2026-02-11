<?php

declare(strict_types=1);

namespace App\Services\Payments;

final class PaymentRouter
{
    public function methodsForRegion(string $region): array
    {
        $region = $this->normalizeRegion($region);
        $allowed = $this->allowedProviders();

        $priority = config('payments.provider_priority', []);
        $methods = [];
        if (is_array($priority) && isset($priority[$region]) && is_array($priority[$region])) {
            foreach ($priority[$region] as $method) {
                if (!is_string($method)) {
                    continue;
                }

                $method = strtolower(trim($method));
                if ($method !== '' && in_array($method, $allowed, true)) {
                    $methods[] = $method;
                }
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

    private function allowedProviders(): array
    {
        $providers = ['stripe', 'billing'];
        if ($this->isStubEnabled()) {
            $providers[] = 'stub';
        }

        return $providers;
    }

    private function isStubEnabled(): bool
    {
        return app()->environment(['local', 'testing']) && config('payments.allow_stub') === true;
    }
}
