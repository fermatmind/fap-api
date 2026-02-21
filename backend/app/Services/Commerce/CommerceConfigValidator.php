<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Services\Report\ReportAccess;

final class CommerceConfigValidator
{
    private const BIG5_ALLOWED_MODULES = [
        ReportAccess::MODULE_BIG5_CORE,
        ReportAccess::MODULE_BIG5_FULL,
        ReportAccess::MODULE_BIG5_ACTION_PLAN,
        'big5_career',
        'big5_relationship',
    ];

    /**
     * @param list<array<string,mixed>> $rows
     */
    public function validate(array $rows): void
    {
        $errors = [];
        $seenSku = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $errors[] = $this->err($index, null, 'row must be object-like array');
                continue;
            }

            $sku = $this->norm((string) ($row['sku'] ?? $row['sku_code'] ?? ''));
            $scaleCode = $this->norm((string) ($row['scale_code'] ?? ''));
            $benefitType = strtolower(trim((string) ($row['benefit_type'] ?? '')));
            $benefitCode = $this->norm((string) ($row['benefit_code'] ?? ''));
            $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
            $priceCents = (int) ($row['price_cents'] ?? 0);
            $isActive = (bool) ($row['is_active'] ?? true);

            if ($sku === '') {
                $errors[] = $this->err($index, null, 'sku is required');
                continue;
            }

            if (isset($seenSku[$sku])) {
                $errors[] = $this->err($index, $sku, 'duplicate sku in seed data');
                continue;
            }
            $seenSku[$sku] = true;

            if ($scaleCode === '') {
                $errors[] = $this->err($index, $sku, 'scale_code is required');
            }

            if ($currency === '' || strlen($currency) !== 3) {
                $errors[] = $this->err($index, $sku, 'currency must be a 3-letter ISO code');
            }

            if ($priceCents < 0) {
                $errors[] = $this->err($index, $sku, 'price_cents must be >= 0');
            }

            if ($benefitType === 'report_unlock' && $benefitCode === '') {
                $errors[] = $this->err($index, $sku, 'benefit_code is required for report_unlock sku');
            }

            if ($scaleCode !== ReportAccess::SCALE_BIG5_OCEAN) {
                continue;
            }

            if ($currency !== 'CNY') {
                $errors[] = $this->err($index, $sku, 'BIG5_OCEAN sku currency must be CNY');
            }

            $meta = $this->metaFromRow($row);
            $modules = $this->normalizeModules(
                $row['modules_included'] ?? ($meta['modules_included'] ?? null)
            );

            foreach ($modules as $module) {
                if (!in_array($module, self::BIG5_ALLOWED_MODULES, true)) {
                    $errors[] = $this->err($index, $sku, "invalid BIG5 module: {$module}");
                }
            }

            foreach ($this->expectedModulesByBenefit($benefitCode) as $requiredModule) {
                if (!in_array($requiredModule, $modules, true)) {
                    $errors[] = $this->err(
                        $index,
                        $sku,
                        "benefit_code {$benefitCode} must include module {$requiredModule}"
                    );
                }
            }

            $offerDisabled = array_key_exists('offer', $meta) && $meta['offer'] === false;
            if ($isActive && !$offerDisabled) {
                $offerCode = trim((string) ($row['offer_code'] ?? ($meta['offer_code'] ?? '')));
                if ($offerCode === '') {
                    $errors[] = $this->err($index, $sku, 'offer_code is required for active BIG5 offers');
                }
            }
        }

        if ($errors !== []) {
            throw new \InvalidArgumentException(
                "CommerceConfigValidator failed:\n- " . implode("\n- ", $errors)
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function metaFromRow(array $row): array
    {
        $meta = $row['metadata_json'] ?? [];
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }

        return is_array($meta) ? $meta : [];
    }

    /**
     * @return list<string>
     */
    private function normalizeModules(mixed $raw): array
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

    /**
     * @return list<string>
     */
    private function expectedModulesByBenefit(string $benefitCode): array
    {
        return match ($benefitCode) {
            'BIG5_FULL_REPORT' => [
                ReportAccess::MODULE_BIG5_FULL,
                ReportAccess::MODULE_BIG5_ACTION_PLAN,
            ],
            'BIG5_ACTION_PLAN' => [ReportAccess::MODULE_BIG5_ACTION_PLAN],
            default => [],
        };
    }

    private function norm(string $value): string
    {
        $value = strtoupper(trim($value));

        return $value;
    }

    private function err(int $index, ?string $sku, string $message): string
    {
        $label = 'row#' . ($index + 1);
        if ($sku !== null && $sku !== '') {
            $label .= " sku={$sku}";
        }

        return $label . ': ' . $message;
    }
}
