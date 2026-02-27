<?php

namespace App\Services\Commerce;

use App\Models\Sku;
use App\Services\Report\ReportAccess;
use Illuminate\Support\Collection;

class SkuCatalog
{
    public function normalizeSku(string $sku): string
    {
        $sku = strtoupper(trim($sku));
        return $sku !== '' ? $sku : '';
    }

    public function getActiveSku(
        string $sku,
        ?string $scaleCode = null,
        ?int $orgId = null,
        bool $includeGlobalFallback = true
    ): ?Sku
    {
        $sku = $this->normalizeSku($sku);
        if ($sku === '') {
            return null;
        }

        $rows = $this->baseQuery($scaleCode, true, $orgId, $includeGlobalFallback)
            ->where('sku', $sku)
            ->get();

        return $this->pickPreferredRow($rows, $orgId);
    }

    public function assertActiveSku(
        string $sku,
        ?string $scaleCode = null,
        ?int $orgId = null,
        bool $includeGlobalFallback = true
    ): Sku
    {
        $row = $this->getActiveSku($sku, $scaleCode, $orgId, $includeGlobalFallback);
        if (!$row) {
            throw new \InvalidArgumentException('SKU_NOT_FOUND');
        }

        return $row;
    }

    public function listActiveSkus(
        ?string $scaleCode = null,
        ?int $orgId = null,
        bool $includeGlobalFallback = true
    ): array
    {
        $items = [];
        $rows = $this->baseQuery($scaleCode, true, $orgId, $includeGlobalFallback)
            ->orderBy('sku')
            ->get();
        $rows = $this->dedupeRowsBySku($rows, $orgId);

        foreach ($rows as $row) {
            $meta = $this->decodeMeta($row);
            if (!empty($meta['anchor']) || !empty($meta['deprecated'])) {
                continue;
            }

            $modulesIncluded = $this->normalizeModulesIncluded($meta['modules_included'] ?? null);
            if ($modulesIncluded === []) {
                $benefitCode = strtoupper(trim((string) ($row->benefit_code ?? '')));
                if ($benefitCode !== '') {
                    $modulesIncluded = $this->benefitModuleRuleCatalog()->modulesForBenefitCode(
                        (int) ($row->org_id ?? 0),
                        $benefitCode
                    );
                }
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
                'modules_included' => $modulesIncluded,
            ];
        }

        return $items;
    }

    public function resolveSkuMeta(
        string $sku,
        ?string $scaleCode = null,
        ?int $orgId = null,
        bool $includeGlobalFallback = true
    ): array
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

        $requestedRow = $this->findSkuRow($requestedSku, $scaleCode, $orgId, $includeGlobalFallback);
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
                $effectiveSku = $this->resolveEffectiveSkuFromAnchor(
                    $requestedSku,
                    $scaleCode,
                    $orgId,
                    $includeGlobalFallback
                ) ?? '';
            }
        } else {
            $anchorSku = $this->normalizeSku((string) ($requestedMeta['anchor_sku'] ?? ''));
        }

        if ($effectiveSku === '') {
            $effectiveSku = $requestedSku;
        }

        $effectiveRow = $this->getActiveSku($effectiveSku, $scaleCode, $orgId, $includeGlobalFallback);
        if (!$effectiveRow && $requestedSku !== $effectiveSku) {
            $effectiveRow = $this->getActiveSku($requestedSku, $scaleCode, $orgId, $includeGlobalFallback);
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

    public function isAnchorSku(
        string $sku,
        ?string $scaleCode = null,
        ?int $orgId = null,
        bool $includeGlobalFallback = true
    ): bool
    {
        $sku = $this->normalizeSku($sku);
        if ($sku === '') {
            return false;
        }

        $row = $this->findSkuRow($sku, $scaleCode, $orgId, $includeGlobalFallback);
        if (!$row) {
            return false;
        }

        return $this->isAnchorMeta($this->decodeMeta($row));
    }

    public function anchorForSku(
        string $sku,
        ?string $scaleCode = null,
        ?int $orgId = null,
        bool $includeGlobalFallback = true
    ): ?string
    {
        $sku = $this->normalizeSku($sku);
        if ($sku === '') {
            return null;
        }

        $row = $this->findSkuRow($sku, $scaleCode, $orgId, $includeGlobalFallback);
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

        return $this->resolveAnchorSkuForEffective($sku, $scaleCode, $orgId, $includeGlobalFallback);
    }

    public function defaultAnchorSku(
        ?string $scaleCode = null,
        ?int $orgId = null,
        bool $includeGlobalFallback = true
    ): ?string
    {
        $rows = $this->baseQuery($scaleCode, false, $orgId, $includeGlobalFallback)
            ->orderBy('sku')
            ->get();
        $rows = $this->dedupeRowsBySku($rows, $orgId);
        foreach ($rows as $row) {
            $meta = $this->decodeMeta($row);
            if ($this->isAnchorMeta($meta)) {
                return (string) ($row->sku ?? '');
            }
        }

        $defaultEffective = $this->defaultEffectiveSku($scaleCode, $orgId, $includeGlobalFallback);
        if ($defaultEffective) {
            return $this->anchorForSku($defaultEffective, $scaleCode, $orgId, $includeGlobalFallback);
        }

        return null;
    }

    public function defaultEffectiveSku(
        ?string $scaleCode = null,
        ?int $orgId = null,
        bool $includeGlobalFallback = true
    ): ?string
    {
        $items = $this->listActiveSkus($scaleCode, $orgId, $includeGlobalFallback);
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

    private function baseQuery(
        ?string $scaleCode,
        bool $activeOnly,
        ?int $orgId = null,
        bool $includeGlobalFallback = true
    ): \Illuminate\Database\Eloquent\Builder
    {
        $query = Sku::query();
        if ($activeOnly) {
            $query->where('is_active', true);
        }

        if ($orgId !== null) {
            $query->forOrg($orgId, $includeGlobalFallback);
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

    /**
     * @return list<string>
     */
    private function normalizeModulesIncluded(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw)) {
            return [];
        }

        return ReportAccess::normalizeModules($raw);
    }

    private function isAnchorMeta(array $meta): bool
    {
        return !empty($meta['anchor']) || !empty($meta['is_anchor']);
    }

    private function findSkuRow(
        string $sku,
        ?string $scaleCode = null,
        ?int $orgId = null,
        bool $includeGlobalFallback = true
    ): ?Sku
    {
        $rows = $this->baseQuery($scaleCode, false, $orgId, $includeGlobalFallback)
            ->where('sku', $sku)
            ->get();

        return $this->pickPreferredRow($rows, $orgId);
    }

    private function resolveEffectiveSkuFromAnchor(
        string $anchorSku,
        ?string $scaleCode = null,
        ?int $orgId = null,
        bool $includeGlobalFallback = true
    ): ?string
    {
        $rows = $this->baseQuery($scaleCode, true, $orgId, $includeGlobalFallback)
            ->orderBy('sku')
            ->get();
        $rows = $this->dedupeRowsBySku($rows, $orgId);
        foreach ($rows as $row) {
            $meta = $this->decodeMeta($row);
            $metaAnchor = $this->normalizeSku((string) ($meta['anchor_sku'] ?? ''));
            if ($metaAnchor !== '' && $metaAnchor === $anchorSku) {
                return (string) ($row->sku ?? '');
            }
        }

        return null;
    }

    private function resolveAnchorSkuForEffective(
        string $effectiveSku,
        ?string $scaleCode = null,
        ?int $orgId = null,
        bool $includeGlobalFallback = true
    ): ?string
    {
        $rows = $this->baseQuery($scaleCode, false, $orgId, $includeGlobalFallback)
            ->orderBy('sku')
            ->get();
        $rows = $this->dedupeRowsBySku($rows, $orgId);
        foreach ($rows as $row) {
            $meta = $this->decodeMeta($row);
            $metaEffective = $this->normalizeSku((string) ($meta['effective_sku'] ?? ''));
            if ($metaEffective !== '' && $metaEffective === $effectiveSku) {
                return (string) ($row->sku ?? '');
            }
        }

        return null;
    }

    private function benefitModuleRuleCatalog(): BenefitModuleRuleCatalog
    {
        return app(BenefitModuleRuleCatalog::class);
    }

    /**
     * @param  Collection<int,Sku>  $rows
     * @return Collection<int,Sku>
     */
    private function dedupeRowsBySku(Collection $rows, ?int $orgId): Collection
    {
        if ($orgId === null) {
            return $rows;
        }

        return $rows
            ->groupBy(fn (Sku $row): string => $this->normalizeSku((string) ($row->sku ?? '')))
            ->map(function (Collection $group) use ($orgId): ?Sku {
                return $this->pickPreferredRow($group, $orgId);
            })
            ->filter(static fn (mixed $row): bool => $row instanceof Sku)
            ->sortBy(fn (Sku $row): string => (string) ($row->sku ?? ''))
            ->values();
    }

    /**
     * @param  Collection<int,Sku>  $rows
     */
    private function pickPreferredRow(Collection $rows, ?int $orgId): ?Sku
    {
        if ($rows->isEmpty()) {
            return null;
        }

        if ($orgId === null) {
            $first = $rows->first();
            return $first instanceof Sku ? $first : null;
        }

        $sorted = $rows->sort(function (Sku $left, Sku $right) use ($orgId): int {
            $leftPriority = $this->orgPriority((int) ($left->org_id ?? 0), $orgId);
            $rightPriority = $this->orgPriority((int) ($right->org_id ?? 0), $orgId);
            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            return strcmp((string) ($left->sku ?? ''), (string) ($right->sku ?? ''));
        });

        $first = $sorted->first();
        return $first instanceof Sku ? $first : null;
    }

    private function orgPriority(int $rowOrgId, int $targetOrgId): int
    {
        if ($rowOrgId === $targetOrgId) {
            return 0;
        }
        if ($rowOrgId === 0) {
            return 1;
        }

        $legacyOrgId = (int) config('fap.legacy_org_id', 1);
        if ($legacyOrgId > 0 && $rowOrgId === $legacyOrgId) {
            return 2;
        }

        return 3;
    }
}
