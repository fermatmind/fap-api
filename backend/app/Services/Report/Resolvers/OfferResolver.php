<?php

namespace App\Services\Report\Resolvers;

use App\Services\Commerce\SkuCatalog;
use App\Services\Report\ReportAccess;

class OfferResolver
{
    private const DEFAULT_VIEW_POLICY = [
        'free_sections' => ['intro', 'score'],
        'blur_others' => true,
        'teaser_percent' => 0.3,
        'upgrade_sku' => null,
    ];

    public function __construct(private SkuCatalog $skus) {}

    public function normalizeViewPolicy(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        $raw = is_array($raw) ? $raw : [];

        $policy = array_merge(self::DEFAULT_VIEW_POLICY, $raw);

        $freeSections = $policy['free_sections'] ?? null;
        $policy['free_sections'] = is_array($freeSections) ? array_values($freeSections) : self::DEFAULT_VIEW_POLICY['free_sections'];

        $policy['blur_others'] = (bool) ($policy['blur_others'] ?? true);

        $pct = (float) ($policy['teaser_percent'] ?? self::DEFAULT_VIEW_POLICY['teaser_percent']);
        if ($pct < 0) {
            $pct = 0;
        }
        if ($pct > 1) {
            $pct = 1;
        }
        $policy['teaser_percent'] = $pct;

        $upgradeSku = trim((string) ($policy['upgrade_sku'] ?? ''));
        $policy['upgrade_sku'] = $upgradeSku !== '' ? $upgradeSku : null;

        return $policy;
    }

    public function normalizeCommercial(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }

        return is_array($raw) ? $raw : [];
    }

    public function buildPaywall(array $viewPolicy, array $commercial, string $scaleCode, int $orgId): array
    {
        $scaleCode = strtoupper(trim($scaleCode));
        $effectiveSku = strtoupper(trim((string) ($viewPolicy['upgrade_sku'] ?? '')));
        if ($effectiveSku === '' || $this->skus->isAnchorSku($effectiveSku, $scaleCode, $orgId)) {
            $effectiveSku = $this->skus->defaultEffectiveSku($scaleCode, $orgId) ?? $effectiveSku;
        }

        $viewPolicy['upgrade_sku'] = $effectiveSku !== '' ? $effectiveSku : null;

        $anchorSku = $this->skus->anchorForSku($effectiveSku, $scaleCode, $orgId);
        if ($anchorSku === null || $anchorSku === '') {
            $anchorSku = $this->skus->defaultAnchorSku($scaleCode, $orgId);
        }

        $offers = $this->buildOffersFromSkus($this->skus->listActiveSkus($scaleCode, $orgId));
        if (count($offers) === 0) {
            $offers = $this->normalizeOffers($commercial['offers'] ?? null);
        }

        return [
            'upgrade_sku' => $anchorSku,
            'upgrade_sku_effective' => $effectiveSku !== '' ? $effectiveSku : null,
            'offers' => $offers,
            'view_policy' => $viewPolicy,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $offers
     * @return list<string>
     */
    public function collectModulesFromOffers(array $offers): array
    {
        $modules = [];
        foreach ($offers as $offer) {
            if (! is_array($offer)) {
                continue;
            }
            $modules = array_merge(
                $modules,
                $this->normalizeModulesIncluded($offer['modules_included'] ?? null)
            );
        }

        return ReportAccess::normalizeModules($modules);
    }

    /**
     * @param  list<string>  $modulesAllowed
     * @param  list<string>  $modulesOffered
     */
    public function modulesCoverOffered(array $modulesAllowed, array $modulesOffered): bool
    {
        if ($modulesOffered === []) {
            return true;
        }

        $allowed = array_fill_keys(ReportAccess::normalizeModules($modulesAllowed), true);
        foreach (ReportAccess::normalizeModules($modulesOffered) as $module) {
            if (! isset($allowed[$module])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildOffersFromSkus(array $items): array
    {
        $offers = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
            if ($sku === '') {
                continue;
            }

            $meta = $item['meta_json'] ?? null;
            if (is_string($meta)) {
                $decoded = json_decode($meta, true);
                $meta = is_array($decoded) ? $decoded : null;
            }
            $meta = is_array($meta) ? $meta : [];

            if (array_key_exists('offer', $meta) && $meta['offer'] === false) {
                continue;
            }

            $grantType = trim((string) ($meta['grant_type'] ?? ''));
            if ($grantType === '') {
                $grantType = strtolower(trim((string) ($item['kind'] ?? '')));
            }

            $grantQty = isset($meta['grant_qty']) ? (int) $meta['grant_qty'] : 1;
            $periodDays = isset($meta['period_days']) ? (int) $meta['period_days'] : null;

            $entitlementId = trim((string) ($meta['entitlement_id'] ?? ''));
            $benefitCode = strtoupper(trim((string) ($item['benefit_code'] ?? '')));
            $offerCode = trim((string) ($meta['offer_code'] ?? ''));
            $modulesIncluded = $this->normalizeModulesIncluded(
                $item['modules_included'] ?? ($meta['modules_included'] ?? null)
            );

            $offers[] = [
                'sku' => $sku,
                'sku_code' => $sku,
                'price_cents' => (int) ($item['price_cents'] ?? 0),
                'currency' => (string) ($item['currency'] ?? 'CNY'),
                'title' => (string) ($meta['title'] ?? $meta['label'] ?? ''),
                'entitlement_id' => $entitlementId !== '' ? $entitlementId : null,
                'benefit_code' => $benefitCode !== '' ? $benefitCode : null,
                'offer_code' => $offerCode !== '' ? $offerCode : null,
                'modules_included' => $modulesIncluded,
                'grant' => [
                    'type' => $grantType !== '' ? $grantType : null,
                    'qty' => $grantQty,
                    'period_days' => $periodDays,
                ],
            ];
        }

        return $offers;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function normalizeOffers(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($raw)) {
            return [];
        }

        $offers = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $sku = strtoupper(trim((string) ($item['sku'] ?? ($item['sku_code'] ?? ''))));
            if ($sku === '') {
                continue;
            }

            $grant = $this->normalizeGrant($item['grant'] ?? null);
            $entitlementId = trim((string) ($item['entitlement_id'] ?? ''));
            $benefitCode = strtoupper(trim((string) ($item['benefit_code'] ?? '')));
            $offerCode = trim((string) ($item['offer_code'] ?? ''));
            $modulesIncluded = $this->normalizeModulesIncluded($item['modules_included'] ?? null);

            $offers[] = [
                'sku' => $sku,
                'sku_code' => $sku,
                'price_cents' => (int) ($item['price_cents'] ?? 0),
                'currency' => (string) ($item['currency'] ?? 'CNY'),
                'title' => (string) ($item['title'] ?? ''),
                'entitlement_id' => $entitlementId !== '' ? $entitlementId : null,
                'benefit_code' => $benefitCode !== '' ? $benefitCode : null,
                'offer_code' => $offerCode !== '' ? $offerCode : null,
                'modules_included' => $modulesIncluded,
                'grant' => $grant,
            ];
        }

        return $offers;
    }

    /**
     * @return array{type:?string,qty:?int,period_days:?int}
     */
    private function normalizeGrant(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        $raw = is_array($raw) ? $raw : [];

        $type = trim((string) ($raw['type'] ?? ''));
        $qty = isset($raw['qty']) ? (int) $raw['qty'] : null;
        $periodDays = isset($raw['period_days']) ? (int) $raw['period_days'] : null;

        return [
            'type' => $type !== '' ? $type : null,
            'qty' => $qty,
            'period_days' => $periodDays,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeModulesIncluded(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($raw)) {
            return [];
        }

        return ReportAccess::normalizeModules($raw);
    }
}
