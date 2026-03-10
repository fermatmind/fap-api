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

    private const DEFAULT_CTA = [
        'visible' => false,
        'kind' => 'none',
        'title' => null,
        'subtitle' => null,
        'primary_label' => null,
        'secondary_label' => null,
        'benefit_bullets' => [],
        'badge' => null,
        'target_sku' => null,
        'target_sku_effective' => null,
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

    public function buildPaywall(array $viewPolicy, array $commercial, array $commercialSpec, string $scaleCode, int $orgId): array
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
            'cta_copy' => $this->resolveCtaCopy($commercialSpec, $anchorSku, $effectiveSku !== '' ? $effectiveSku : null),
            'view_policy' => $viewPolicy,
        ];
    }

    public function buildCtaPayload(array $paywall, bool $locked): array
    {
        $payload = self::DEFAULT_CTA;
        $payload['target_sku'] = $this->normalizeNullableString($paywall['upgrade_sku'] ?? null);
        $payload['target_sku_effective'] = $this->normalizeNullableString($paywall['upgrade_sku_effective'] ?? null);

        $canUpsell = $locked
            && (
                $payload['target_sku'] !== null
                || $payload['target_sku_effective'] !== null
                || count((array) ($paywall['offers'] ?? [])) > 0
            );

        if (! $canUpsell) {
            return $payload;
        }

        $copy = $this->normalizeCtaCopy($paywall['cta_copy'] ?? null);

        return array_merge($payload, [
            'visible' => true,
            'kind' => 'upsell',
            'title' => $copy['title'],
            'subtitle' => $copy['subtitle'],
            'primary_label' => $copy['primary_label'],
            'secondary_label' => $copy['secondary_label'],
            'benefit_bullets' => $copy['benefit_bullets'],
            'badge' => $copy['badge'],
        ]);
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

    private function resolveCtaCopy(array $commercialSpec, ?string $anchorSku, ?string $effectiveSku): array
    {
        $variants = is_array($commercialSpec['variants'] ?? null) ? $commercialSpec['variants'] : [];
        if ($variants === []) {
            return $this->normalizeCtaCopy(null);
        }

        $normalizedAnchor = strtoupper(trim((string) ($anchorSku ?? '')));
        $normalizedEffective = strtoupper(trim((string) ($effectiveSku ?? '')));
        $defaultVariant = null;

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            if (($variant['default'] ?? false) === true && $defaultVariant === null) {
                $defaultVariant = $variant;
            }

            $variantAnchor = strtoupper(trim((string) ($variant['upgrade_sku_anchor'] ?? '')));
            $variantEffective = strtoupper(trim((string) ($variant['upgrade_sku'] ?? '')));

            if ($normalizedEffective !== '' && $variantEffective === $normalizedEffective) {
                return $this->normalizeCtaCopy($variant['cta_copy'] ?? ($variant['cta'] ?? null));
            }

            if ($normalizedAnchor !== '' && $variantAnchor === $normalizedAnchor) {
                return $this->normalizeCtaCopy($variant['cta_copy'] ?? ($variant['cta'] ?? null));
            }
        }

        if (is_array($defaultVariant)) {
            return $this->normalizeCtaCopy($defaultVariant['cta_copy'] ?? ($defaultVariant['cta'] ?? null));
        }

        return $this->normalizeCtaCopy($variants[0]['cta_copy'] ?? ($variants[0]['cta'] ?? null) ?? null);
    }

    private function normalizeCtaCopy(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }

        $raw = is_array($raw) ? $raw : [];

        $benefitBullets = $raw['benefit_bullets'] ?? [];
        if (! is_array($benefitBullets)) {
            $benefitBullets = [];
        }

        $benefitBullets = array_values(array_filter(array_map(function ($item) {
            return $this->normalizeNullableString($item);
        }, $benefitBullets)));

        return [
            'title' => $this->normalizeNullableString($raw['title'] ?? null),
            'subtitle' => $this->normalizeNullableString($raw['subtitle'] ?? null),
            'primary_label' => $this->normalizeNullableString($raw['primary_label'] ?? null),
            'secondary_label' => $this->normalizeNullableString($raw['secondary_label'] ?? null),
            'benefit_bullets' => $benefitBullets,
            'badge' => $this->normalizeNullableString($raw['badge'] ?? null),
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
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
