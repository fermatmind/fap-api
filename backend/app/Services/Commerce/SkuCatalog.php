<?php

namespace App\Services\Commerce;

use App\Models\Sku;

class SkuCatalog
{
    public function normalizeSku(string $sku): string
    {
        $sku = strtoupper(trim($sku));
        return $sku !== '' ? $sku : '';
    }

    public function getActiveSku(string $sku, ?string $scaleCode = null): ?Sku
    {
        $sku = $this->normalizeSku($sku);
        if ($sku === '') {
            return null;
        }

        $query = $this->baseQuery($scaleCode, true)->where('sku', $sku);
        return $query->first();
    }

    public function assertActiveSku(string $sku, ?string $scaleCode = null): Sku
    {
        $row = $this->getActiveSku($sku, $scaleCode);
        if (!$row) {
            throw new \InvalidArgumentException('SKU_NOT_FOUND');
        }

        return $row;
    }

    public function listActiveSkus(?string $scaleCode = null): array
    {
        $items = [];
        $rows = $this->baseQuery($scaleCode, true)
            ->orderBy('sku')
            ->get();

        foreach ($rows as $row) {
            $meta = $this->decodeMeta($row);
            if (!empty($meta['anchor']) || !empty($meta['deprecated'])) {
                continue;
            }

            $items[] = [
                'sku' => (string) ($row->sku ?? ''),
                'scale_code' => (string) ($row->scale_code ?? ''),
                'kind' => (string) ($row->kind ?? ''),
                'unit_qty' => (int) ($row->unit_qty ?? 0),
                'benefit_code' => (string) ($row->benefit_code ?? ''),
                'scope' => (string) ($row->scope ?? ''),
                'price_cents' => (int) ($row->price_cents ?? 0),
                'currency' => (string) ($row->currency ?? ''),
                'is_active' => (bool) ($row->is_active ?? false),
                'meta_json' => $meta,
            ];
        }

        return $items;
    }

    public function resolveSkuMeta(string $sku, ?string $scaleCode = null): array
    {
        $requestedSku = $this->normalizeSku($sku);
        if ($requestedSku === '') {
            return [
                'requested_sku' => null,
                'effective_sku' => null,
                'anchor_sku' => null,
                'entitlement_id' => null,
                'sku_row' => null,
            ];
        }

        $requestedRow = $this->findSkuRow($requestedSku, $scaleCode);
        if (!$requestedRow) {
            return [
                'requested_sku' => $requestedSku,
                'effective_sku' => null,
                'anchor_sku' => null,
                'entitlement_id' => null,
                'sku_row' => null,
            ];
        }

        $requestedMeta = $this->decodeMeta($requestedRow);
        $anchorSku = '';
        $effectiveSku = $requestedSku;

        if ($this->isAnchorMeta($requestedMeta)) {
            $anchorSku = $requestedSku;
            $effectiveSku = $this->normalizeSku((string) ($requestedMeta['effective_sku'] ?? ''));
            if ($effectiveSku === '') {
                $effectiveSku = $this->resolveEffectiveSkuFromAnchor($requestedSku, $scaleCode) ?? '';
            }
        } else {
            $anchorSku = $this->normalizeSku((string) ($requestedMeta['anchor_sku'] ?? ''));
        }

        if ($effectiveSku === '') {
            $effectiveSku = $requestedSku;
        }

        $effectiveRow = $this->getActiveSku($effectiveSku, $scaleCode);
        if (!$effectiveRow && $requestedSku !== $effectiveSku) {
            $effectiveRow = $this->getActiveSku($requestedSku, $scaleCode);
        }

        $entitlementId = null;
        $entitlementMeta = $this->decodeMeta($effectiveRow ?: $requestedRow);
        if (!empty($entitlementMeta['entitlement_id'])) {
            $entitlementId = (string) $entitlementMeta['entitlement_id'];
        }

        return [
            'requested_sku' => $requestedSku,
            'effective_sku' => $effectiveRow?->sku ? (string) $effectiveRow->sku : ($effectiveSku !== '' ? $effectiveSku : null),
            'anchor_sku' => $anchorSku !== '' ? $anchorSku : null,
            'entitlement_id' => $entitlementId,
            'sku_row' => $effectiveRow,
        ];
    }

    public function isAnchorSku(string $sku, ?string $scaleCode = null): bool
    {
        $sku = $this->normalizeSku($sku);
        if ($sku === '') {
            return false;
        }

        $row = $this->findSkuRow($sku, $scaleCode);
        if (!$row) {
            return false;
        }

        return $this->isAnchorMeta($this->decodeMeta($row));
    }

    public function anchorForSku(string $sku, ?string $scaleCode = null): ?string
    {
        $sku = $this->normalizeSku($sku);
        if ($sku === '') {
            return null;
        }

        $row = $this->findSkuRow($sku, $scaleCode);
        if (!$row) {
            return null;
        }

        $meta = $this->decodeMeta($row);
        if ($this->isAnchorMeta($meta)) {
            return $sku;
        }

        $anchorSku = $this->normalizeSku((string) ($meta['anchor_sku'] ?? ''));
        if ($anchorSku !== '') {
            return $anchorSku;
        }

        return $this->resolveAnchorSkuForEffective($sku, $scaleCode);
    }

    public function defaultAnchorSku(?string $scaleCode = null): ?string
    {
        $rows = $this->baseQuery($scaleCode, false)->orderBy('sku')->get();
        foreach ($rows as $row) {
            $meta = $this->decodeMeta($row);
            if ($this->isAnchorMeta($meta)) {
                return (string) ($row->sku ?? '');
            }
        }

        $defaultEffective = $this->defaultEffectiveSku($scaleCode);
        if ($defaultEffective) {
            return $this->anchorForSku($defaultEffective, $scaleCode);
        }

        return null;
    }

    public function defaultEffectiveSku(?string $scaleCode = null): ?string
    {
        $items = $this->listActiveSkus($scaleCode);
        if (count($items) === 0) {
            return null;
        }

        foreach ($items as $item) {
            $meta = $item['meta_json'] ?? [];
            if (is_array($meta) && (!empty($meta['effective_default']) || !empty($meta['default']))) {
                return (string) ($item['sku'] ?? null);
            }
        }

        return (string) ($items[0]['sku'] ?? null);
    }

    private function baseQuery(?string $scaleCode, bool $activeOnly): \Illuminate\Database\Eloquent\Builder
    {
        $query = Sku::query();
        if ($activeOnly) {
            $query->where('is_active', true);
        }
        $scale = $this->normalizeScaleCode($scaleCode);
        if ($scale !== null) {
            $query->where('scale_code', $scale);
        }
        return $query;
    }

    private function normalizeScaleCode(?string $scaleCode): ?string
    {
        $scaleCode = $scaleCode !== null ? strtoupper(trim($scaleCode)) : '';
        return $scaleCode !== '' ? $scaleCode : null;
    }

    private function decodeMeta(?Sku $row): array
    {
        if (!$row) {
            return [];
        }

        $meta = $row->meta_json ?? null;
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : null;
        }

        return is_array($meta) ? $meta : [];
    }

    private function isAnchorMeta(array $meta): bool
    {
        return !empty($meta['anchor']) || !empty($meta['is_anchor']);
    }

    private function findSkuRow(string $sku, ?string $scaleCode = null): ?Sku
    {
        $query = $this->baseQuery($scaleCode, false)->where('sku', $sku);
        return $query->first();
    }

    private function resolveEffectiveSkuFromAnchor(string $anchorSku, ?string $scaleCode = null): ?string
    {
        $rows = $this->baseQuery($scaleCode, true)->orderBy('sku')->get();
        foreach ($rows as $row) {
            $meta = $this->decodeMeta($row);
            $metaAnchor = $this->normalizeSku((string) ($meta['anchor_sku'] ?? ''));
            if ($metaAnchor !== '' && $metaAnchor === $anchorSku) {
                return (string) ($row->sku ?? '');
            }
        }

        return null;
    }

    private function resolveAnchorSkuForEffective(string $effectiveSku, ?string $scaleCode = null): ?string
    {
        $rows = $this->baseQuery($scaleCode, false)->orderBy('sku')->get();
        foreach ($rows as $row) {
            $meta = $this->decodeMeta($row);
            $metaEffective = $this->normalizeSku((string) ($meta['effective_sku'] ?? ''));
            if ($metaEffective !== '' && $metaEffective === $effectiveSku) {
                return (string) ($row->sku ?? '');
            }
        }

        return null;
    }
}
