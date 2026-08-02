<?php

declare(strict_types=1);

namespace App\Services\Commerce;

final class ReportUnlockProductCatalog
{
    /** @return array<string,mixed>|null */
    public function forScale(string $scaleCode): ?array
    {
        $scaleCode = strtoupper(trim($scaleCode));
        $contract = config('report_unlock.products.'.$scaleCode);

        if (! is_array($contract)) {
            return null;
        }

        $sku = strtoupper(trim((string) ($contract['sku'] ?? '')));
        $benefitCode = strtoupper(trim((string) ($contract['benefit_code'] ?? '')));
        $priceCents = (int) ($contract['price_cents'] ?? 0);
        if ($sku === '' || $benefitCode === '' || $priceCents <= 0) {
            return null;
        }

        return array_merge($contract, [
            'scale_code' => $scaleCode,
            'sku' => $sku,
            'benefit_code' => $benefitCode,
            'price_cents' => $priceCents,
            'currency' => strtoupper(trim((string) ($contract['currency'] ?? 'CNY'))),
            'scope' => strtolower(trim((string) ($contract['scope'] ?? 'attempt'))),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function forSku(string $sku): ?array
    {
        $sku = strtoupper(trim($sku));
        foreach ((array) config('report_unlock.products', []) as $scaleCode => $_contract) {
            $contract = $this->forScale((string) $scaleCode);
            if ($contract !== null && $contract['sku'] === $sku) {
                return $contract;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    public function provider(string $provider, array $contract): array
    {
        $provider = strtolower(trim($provider));
        $base = config('payments.'.$provider, []);
        $override = config('payments.'.$provider.'.products.'.($contract['scale_code'] ?? ''), []);

        return array_merge(is_array($base) ? $base : [], is_array($override) ? $override : [], [
            'sku' => (string) $contract['sku'],
            'price_cents' => (int) $contract['price_cents'],
        ]);
    }
}
